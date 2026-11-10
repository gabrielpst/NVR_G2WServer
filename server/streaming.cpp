/*
 * Copyright 2010-2019 Bluecherry, LLC
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

#include <stdlib.h>
#include <unistd.h>
#include "bc-server.h"
#include "rtsp.h"
#include "hls.h"

extern "C" {
#include <libavutil/mathematics.h>
#include <libavutil/dict.h>
}

#define MP4_MAX_PACKET_SIZE 50000
#define RTP_MAX_PACKET_SIZE 1472
#define TS_MAX_PACKET_SIZE 	1316

/* Live view self-healing. None of these paths may touch the camera
 * connection or the recorders: the live view is rebuilt on its own. */
#define LIVE_RETRY_SEC          5   /* wait before recreating a failed live muxer */
#define LIVE_REPORT_SEC         60  /* at most one timestamp/drop summary line per camera */
#define HLS_STALL_SEC           30  /* no segment for this long, with keyframes arriving -> recreate */
#define HLS_STALL_SEC_MAX       600 /* stall limit doubles on each fruitless recreation, up to this */
#define HLS_TS_BACK_LIMIT       (3 * (int64_t)AV_TIME_BASE)   /* further back than this is a new timeline */
#define HLS_TS_FWD_LIMIT        (10 * (int64_t)AV_TIME_BASE)  /* further ahead than this is a new timeline */
#define HLS_TS_REBASE_GAP       (AV_TIME_BASE / 25)           /* where a rebased timeline resumes */
#define HLS_TS_REBASE_HOLD_SEC  2   /* stragglers of the old timeline are dropped for this long */

static int bc_streaming_setup_elementary(struct bc_record *bc_rec, std::shared_ptr<const stream_properties> props,
	int index, enum AVMediaType type, bc_streaming_type bc_type);
static int bc_streaming_setup_hls(struct bc_record *bc_rec, std::shared_ptr<const stream_properties> props);
static void hls_ctx_free(struct bc_record *bc_rec);
static void rtp_ctx_free(struct bc_record *bc_rec);

/* HLS segments are MPEG-TS (or fMP4 for MPEG-4 video); G.711 and other
 * camera codecs cannot be carried there in a way players accept. */
static bool hls_audio_supported(enum AVCodecID codec_id)
{
	return codec_id == AV_CODEC_ID_AAC || codec_id == AV_CODEC_ID_MP3;
}

/* One line per camera per LIVE_REPORT_SEC instead of one line per packet */
static void live_report(struct bc_record *bc_rec, time_t now)
{
	bc_record::live_state &lv = bc_rec->live;

	if (now - lv.report_time < LIVE_REPORT_SEC)
		return;
	lv.report_time = now;

	if (!lv.ts_adjusted && !lv.pkts_dropped && !lv.ts_rebased && !lv.write_rejected)
		return;

	bc_rec->log.log(Warning, "Live view, last %d s: %u timestamps adjusted, %u timeline jumps, "
		"%u packets skipped, %u packets rejected by muxer (recording is not affected)",
		LIVE_REPORT_SEC, lv.ts_adjusted, lv.ts_rebased, lv.pkts_dropped, lv.write_rejected);
	lv.ts_adjusted = lv.pkts_dropped = lv.ts_rebased = lv.write_rejected = 0;
}

static hls_content *hls_content_of(struct bc_record *bc_rec)
{
	return bc_rec->hls_stream ? bc_rec->hls_stream->get_hls_content(bc_rec->id) : NULL;
}

/* Seconds without a new segment that mean the muxer is stuck: never less
 * than three configured segment durations. */
static unsigned hls_stall_limit(hls_content *content, const bc_record::live_state &lv)
{
	size_t segment_size = 0;
	double segment_duration = 0.;
	unsigned limit = lv.hls_stall_limit;

	content->get_config(&segment_size, &segment_duration);
	if (segment_duration * 3 > limit)
		limit = (unsigned)(segment_duration * 3);

	return limit;
}

/* Drop the HLS muxer of this camera only and schedule its recreation. The
 * segment window already published stays available to players. */
static void hls_live_restart(struct bc_record *bc_rec, time_t now)
{
	hls_ctx_free(bc_rec);

	hls_content *content = hls_content_of(bc_rec);
	if (content)
		content->reset_input();

	bc_rec->live.hls_retry_at = now + LIVE_RETRY_SEC;
}

static int rtp_io_write(void *opaque, uint8_t *buf, int buf_size)
{
	struct bc_record *bc_rec = (struct bc_record *)opaque;
	bc_rec->rtsp_stream->sendPackets(buf, buf_size, bc_rec->cur_pkt_flags,
		bc_rec->cur_stream_index);

	return buf_size;
}

static int hls_io_write(void *opaque, uint8_t *buf, int buf_size)
{
	struct bc_record *bc_rec = (struct bc_record *)opaque;

	if (bc_rec->hls_stream) {
		hls_segment::type type = bc_rec->hls_segment_type;
		hls_content *content = bc_rec->hls_stream->get_hls_content(bc_rec->id);
		if (content) content->add_data(buf, buf_size, bc_rec->cur_pts, type, bc_rec->cur_pkt_flags);
	}

	return buf_size;
}

int bc_streaming_setup(struct bc_record *bc_rec, bc_streaming_type bc_type, std::shared_ptr<const stream_properties> props)
{
	int ret = 0;

	if ((bc_type == BC_RTP && bc_rec->rtp_stream_ctx[0]) ||
		(bc_type == BC_HLS && bc_rec->hls_stream_ctx[0])) {
		bc_rec->log.log(Warning, "bc_streaming_setup() launched on already-setup bc_record");
		return 0;
	}

	if (bc_type == BC_HLS) {
		/* HLS: mux video + audio into a SINGLE mpegts/fmp4 context so every
		 * segment carries both tracks under one PAT/PMT. The separate elementary
		 * contexts kept below (for BC_RTP) wrote two independent muxers into the
		 * same content buffer -> two conflicting PAT/PMTs per segment, so HLS
		 * players locked onto video and dropped the audio. */
		bc_record::live_state &lv = bc_rec->live;
		time_t now = bc_gettime_monotonic();

		/* Remember that this input feeds HLS, so a failed setup (or a
		 * muxer torn down later) is retried from the packet path. */
		lv.hls_wanted = true;
		lv.hls_stall_limit = HLS_STALL_SEC;

		ret = bc_streaming_setup_hls(bc_rec, props);
		if (ret)
			lv.hls_retry_at = now + LIVE_RETRY_SEC;
		return ret;
	}

	ret |= bc_streaming_setup_elementary(bc_rec, props, 0, AVMEDIA_TYPE_VIDEO, bc_type);
	if (bc_rec->bc->input->has_audio())
		ret |= bc_streaming_setup_elementary(bc_rec, props, 1, AVMEDIA_TYPE_AUDIO, bc_type);
	if (ret)
		return ret;

	if (bc_type == BC_RTP) {
		bc_rec->rtsp_stream = rtsp_stream::create(bc_rec, bc_rec->rtp_stream_ctx);
		bc_rec->live.rtp_wanted = true;
		bc_rec->live.rtp_last_dts[0] = bc_rec->live.rtp_last_dts[1] = AV_NOPTS_VALUE;
	}

	return 0;
}

static int bc_streaming_setup_elementary(struct bc_record *bc_rec, std::shared_ptr<const stream_properties> props,
	int index, enum AVMediaType type, bc_streaming_type bc_type)
{
	AVFormatContext *ctx;
	AVStream *st;
	AVDictionary *muxer_opts = NULL;
	int ret = -1;
	uint8_t *bufptr;
	AVRational bkp_ts;
	bool remux_mp4 = (props->video.codec_id == AV_CODEC_ID_MPEG4);

	ctx = avformat_alloc_context();
	if (!ctx) {
		ret = AVERROR(ENOMEM);
		goto error;
	}

	int (*io_callback)(void*, uint8_t*, int);
	const char *format_name;
	unsigned int packet_size;

	if (bc_type == BC_RTP) {
		packet_size = RTP_MAX_PACKET_SIZE;
		io_callback = rtp_io_write;
		format_name = "rtp";
	} else if (remux_mp4) {
		bc_rec->hls_segment_type = hls_segment::type::fmp4;
		packet_size = MP4_MAX_PACKET_SIZE;
		io_callback = hls_io_write;
		format_name = "mp4";
	} else {
		bc_rec->hls_segment_type = hls_segment::type::mpegts;
		packet_size = TS_MAX_PACKET_SIZE;
		io_callback = hls_io_write;
		format_name = "mpegts";
	}

	ctx->oformat = av_guess_format(format_name, NULL, NULL);
	if (!ctx->oformat) {
		bc_rec->log.log(Error, "%s output format not available", format_name);
		goto error;
	}

	bc_rec->log.log(Debug, "Using %s muxer for %s restreaming for device: %d",
		format_name, (bc_type == BC_RTP) ? "rtsp" : "hls", bc_rec->id);

	st = avformat_new_stream(ctx, NULL);
	if (!st) {
		bc_rec->log.log(Error, "Couldn't add stream to %s muxer", format_name);
		goto error;
	}

	bkp_ts = st->time_base;
	if (type == AVMEDIA_TYPE_VIDEO)
		props->video.apply(st->codecpar);
	else
		props->audio.apply(st->codecpar);

	st->time_base = bkp_ts;
	bc_rec->log.log(Debug, "streaming ctx[%d] time_base: %d/%d", index,
		st->time_base.num, st->time_base.den);

/*
	if (ctx->oformat->flags & AVFMT_GLOBALHEADER)
		st->codec->flags |= AV_CODEC_FLAG_GLOBAL_HEADER;
*/

	ctx->packet_size = packet_size;

	// This I/O context will call our functions to output the byte stream
	bufptr = (unsigned char *)av_malloc(packet_size);
	if (!bufptr) {
		ret = AVERROR(ENOMEM);
		goto error;
	}

	ctx->pb = avio_alloc_context(/* buffer and its size */bufptr, packet_size,
			/* write flag */1,
			/* context pointer passed to functions */bc_rec,
			/* read function */NULL,
			/* write function */io_callback,
			/* seek function */NULL);

	bufptr = NULL;  // ctx->pb now owns it

	if (!ctx->pb) {
		fprintf(stderr, "Failed to allocate I/O context\n");
		goto error;
	}

	if (bc_type == BC_RTP) {
		ctx->pb->seekable = 0;  // Adjust accordingly
		av_dict_set(&muxer_opts, "rtpflags", "skip_rtcp", 0);
		// Add real-time streaming optimizations
		av_dict_set(&muxer_opts, "nobuffer", "1", 0);  // Disable buffering for immediate processing
		av_dict_set(&muxer_opts, "low_delay", "1", 0); // Reduce latency
		av_dict_set(&muxer_opts, "fflags", "nobuffer+genpts", 0); // Additional real-time flags
	} else if (remux_mp4) {
		// Write header for every MP4 fragment to be used as independent segments in HLS playlist
		av_dict_set(&muxer_opts, "movflags", "frag_keyframe+empty_moov+default_base_moof", 0);
	} else {
		char period[32]; // Only PAT/PMT required per TS segment
		snprintf(period, sizeof(period), "%d", (INT_MAX / 2) - 1);
		av_dict_set(&muxer_opts, "sdt_period", period, 0);
		av_dict_set(&muxer_opts, "pat_period", period, 0);
	}

	if ((ret = avformat_write_header(ctx, &muxer_opts)) < 0) {
		bc_rec->log.log(Error, "Initializing muxer for %s streaming failed", format_name);
		goto mux_open_error;
	}

	if (bc_type == BC_RTP) bc_rec->rtp_stream_ctx[index] = ctx;
	else bc_rec->hls_stream_ctx[index] = ctx;

	return 0;

mux_open_error:
	av_free(bufptr);
	av_free(ctx->pb->buffer);
	av_freep(&ctx->pb);
error:
	avformat_free_context(ctx);

	char errbuf[100];
	av_strerror(ret, errbuf, sizeof(errbuf));
	bc_rec->log.log(Error, "bc_streaming_setup() failed: %s", errbuf);
	return ret;
}

/* HLS-only: one muxer context holding BOTH the video stream (index 0) and, when
 * present, the audio stream (index 1). Unlike bc_streaming_setup_elementary
 * (one stream per context, correct for RTSP tracks), this keeps A/V under a
 * single PAT/PMT so the mpegts segments the app pulls carry sound. Stored in
 * hls_stream_ctx[0]; hls_stream_ctx[1] stays NULL. */
static int bc_streaming_setup_hls(struct bc_record *bc_rec, std::shared_ptr<const stream_properties> props)
{
	AVFormatContext *ctx;
	AVStream *st;
	AVDictionary *muxer_opts = NULL;
	int ret = -1;
	uint8_t *bufptr;
	AVRational bkp_ts;
	bool remux_mp4 = (props->video.codec_id == AV_CODEC_ID_MPEG4);
	bool with_audio = props->has_audio() && hls_audio_supported(props->audio.codec_id);
	const char *format_name;
	unsigned int packet_size;

	ctx = avformat_alloc_context();
	if (!ctx) {
		ret = AVERROR(ENOMEM);
		goto error;
	}

	if (remux_mp4) {
		bc_rec->hls_segment_type = hls_segment::type::fmp4;
		packet_size = MP4_MAX_PACKET_SIZE;
		format_name = "mp4";
	} else {
		bc_rec->hls_segment_type = hls_segment::type::mpegts;
		packet_size = TS_MAX_PACKET_SIZE;
		format_name = "mpegts";
	}

	ctx->oformat = av_guess_format(format_name, NULL, NULL);
	if (!ctx->oformat) {
		bc_rec->log.log(Error, "%s output format not available", format_name);
		goto error;
	}

	bc_rec->log.log(Debug, "Using %s muxer (muxed A/V) for hls restreaming for device: %d",
		format_name, bc_rec->id);

	/* stream 0: video */
	st = avformat_new_stream(ctx, NULL);
	if (!st) {
		bc_rec->log.log(Error, "Couldn't add video stream to %s muxer", format_name);
		goto error;
	}
	bkp_ts = st->time_base;
	props->video.apply(st->codecpar);
	st->time_base = bkp_ts;

	/* stream 1: audio (only when the source has an audio track) */
	if (with_audio) {
		st = avformat_new_stream(ctx, NULL);
		if (!st) {
			bc_rec->log.log(Error, "Couldn't add audio stream to %s muxer", format_name);
			goto error;
		}
		bkp_ts = st->time_base;
		props->audio.apply(st->codecpar);
		st->time_base = bkp_ts;
	}

	ctx->packet_size = packet_size;

	bufptr = (unsigned char *)av_malloc(packet_size);
	if (!bufptr) {
		ret = AVERROR(ENOMEM);
		goto error;
	}

	ctx->pb = avio_alloc_context(bufptr, packet_size, 1, bc_rec, NULL, hls_io_write, NULL);
	bufptr = NULL;  // ctx->pb now owns it

	if (!ctx->pb) {
		fprintf(stderr, "Failed to allocate I/O context\n");
		goto error;
	}

	if (remux_mp4) {
		av_dict_set(&muxer_opts, "movflags", "frag_keyframe+empty_moov+default_base_moof", 0);
	} else {
		char period[32]; // Only PAT/PMT required per TS segment
		snprintf(period, sizeof(period), "%d", (INT_MAX / 2) - 1);
		av_dict_set(&muxer_opts, "sdt_period", period, 0);
		av_dict_set(&muxer_opts, "pat_period", period, 0);
	}

	if ((ret = avformat_write_header(ctx, &muxer_opts)) < 0) {
		bc_rec->log.log(Error, "Initializing muxer for hls streaming failed");
		goto mux_open_error;
	}

	bc_rec->hls_stream_ctx[0] = ctx;
	bc_rec->hls_stream_ctx[1] = NULL;

	{
		bc_record::live_state &lv = bc_rec->live;
		hls_content *content = hls_content_of(bc_rec);

		/* Every muxer starts at a video keyframe so its first segment is
		 * decodable. The timeline (hls_ts_offset, hls_ref_ts) is NOT reset:
		 * output timestamps keep increasing across muxers and reconnections. */
		lv.hls_wait_keyframe = true;
		lv.hls_retry_at = 0;
		lv.hls_last_dts[0] = lv.hls_last_dts[1] = AV_NOPTS_VALUE;
		lv.hls_segments_seen = content ? content->segment_counter() : 0;
		lv.hls_segment_time = bc_gettime_monotonic();
		lv.hls_keyframes_fed = 0;
	}

	if (props->has_audio() && !with_audio)
		bc_rec->log.log(Info, "Live view (HLS) without audio: codec %s cannot be carried, "
			"only AAC and MP3 can", avcodec_get_name(props->audio.codec_id));

	return 0;

mux_open_error:
	av_free(bufptr);
	av_free(ctx->pb->buffer);
	av_freep(&ctx->pb);
error:
	avformat_free_context(ctx);

	char errbuf[100];
	av_strerror(ret, errbuf, sizeof(errbuf));
	bc_rec->log.log(Error, "bc_streaming_setup_hls() failed: %s", errbuf);
	return ret;
}

static void rtp_ctx_free(struct bc_record *bc_rec)
{
	for (int i = 0; i < 2; i++) {
		AVFormatContext *ctx = bc_rec->rtp_stream_ctx[i];

		if (!ctx)
			continue;

		if (ctx->pb) {
			av_write_trailer(ctx);
			av_free(ctx->pb->buffer);
			av_freep(&ctx->pb);
		}

		avformat_free_context(ctx);
		bc_rec->rtp_stream_ctx[i] = NULL;
	}

	rtsp_stream::remove(bc_rec);
	bc_rec->rtsp_stream = NULL;
}

void bc_streaming_destroy_rtp(struct bc_record *bc_rec)
{
	rtp_ctx_free(bc_rec);
	bc_rec->live.rtp_wanted = false;
}

static void hls_ctx_free(struct bc_record *bc_rec)
{
	for (int i = 0; i < 2; i++) {
		AVFormatContext *ctx = bc_rec->hls_stream_ctx[i];

		if (!ctx)
			continue;

		if (ctx->pb) {
			av_write_trailer(ctx);
			av_free(ctx->pb->buffer);
			av_freep(&ctx->pb);
		}

		avformat_free_context(ctx);
		bc_rec->hls_stream_ctx[i] = NULL;
	}
}

void bc_streaming_destroy_hls(struct bc_record *bc_rec)
{
	hls_ctx_free(bc_rec);
	bc_rec->live.hls_wanted = false;
	bc_rec->live.hls_retry_at = 0;
}

int bc_streaming_is_setup(struct bc_record *bc_rec)
{
	return bc_rec->rtsp_stream != NULL;
}

int bc_streaming_is_active(struct bc_record *bc_rec)
{
	return (bc_rec->rtsp_stream && bc_rec->rtsp_stream->activeSessionCount());
}

int bc_streaming_is_active_hls(struct bc_record *bc_rec)
{
	return bc_rec->hls_stream != NULL ? 1 : 0;
}

/* Muxers refuse a DTS that does not increase; cameras (and RTMP relays)
 * do send repeated or slightly out-of-order ones. Nudge instead of failing. */
static bool monotonic_dts(int64_t *last, AVPacket *opkt)
{
	bool adjusted = false;

	if (opkt->dts == AV_NOPTS_VALUE)
		opkt->dts = opkt->pts;
	if (opkt->dts == AV_NOPTS_VALUE) {
		opkt->dts = (*last == AV_NOPTS_VALUE) ? 0 : *last + 1;
		adjusted = true;
	}
	if (*last != AV_NOPTS_VALUE && opkt->dts <= *last) {
		opkt->dts = *last + 1;
		adjusted = true;
	}
	if (opkt->pts == AV_NOPTS_VALUE || opkt->pts < opkt->dts)
		opkt->pts = opkt->dts;

	*last = opkt->dts;
	return adjusted;
}

int bc_streaming_packet_write(struct bc_record *bc_rec, const stream_packet &pkt)
{
	bc_record::live_state &lv = bc_rec->live;
	AVPacket opkt;
	int re;
	int ctx_index;

	if (pkt.type == AVMEDIA_TYPE_VIDEO) {
		ctx_index = 0;
	} else if (pkt.type == AVMEDIA_TYPE_AUDIO) {
		ctx_index = 1;
	} else {
		bc_rec->log.log(Warning, "Got unknown packet type, ignoring.");
		return 0;
	}

	/* RTP muxers lost to a write error are rebuilt here, on their own */
	if (!bc_rec->rtsp_stream && lv.rtp_wanted && !bc_rec->rtp_stream_ctx[0] &&
	    pkt.properties() && bc_gettime_monotonic() >= lv.rtp_retry_at) {
		if (bc_streaming_setup(bc_rec, BC_RTP, pkt.properties())) {
			rtp_ctx_free(bc_rec);
			lv.rtp_retry_at = bc_gettime_monotonic() + LIVE_RETRY_SEC;
		} else {
			bc_rec->log.log(Info, "Live view (RTSP) muxer recreated");
		}
	}

	if (!bc_streaming_is_active(bc_rec))
		return 0;

	// Audio packet on a device whose RTP session has no audio track
	if (ctx_index == 1 && !bc_rec->rtp_stream_ctx[1])
		return 0;

	// CRITICAL SAFETY CHECK: Validate streaming context and streams
	if (!bc_rec->rtp_stream_ctx[ctx_index] || 
	    !bc_rec->rtp_stream_ctx[ctx_index]->streams || 
	    bc_rec->rtp_stream_ctx[ctx_index]->nb_streams == 0 ||
	    !bc_rec->rtp_stream_ctx[ctx_index]->streams[0]) {
		bc_rec->log.log(Error, "Invalid RTP streaming context: ctx=%p, streams=%p, nb_streams=%d", 
		       bc_rec->rtp_stream_ctx[ctx_index],
		       bc_rec->rtp_stream_ctx[ctx_index] ? bc_rec->rtp_stream_ctx[ctx_index]->streams : NULL,
		       bc_rec->rtp_stream_ctx[ctx_index] ? bc_rec->rtp_stream_ctx[ctx_index]->nb_streams : -1);
		rtp_ctx_free(bc_rec);
		lv.rtp_retry_at = bc_gettime_monotonic() + LIVE_RETRY_SEC;
		return 0;
	}

	av_init_packet(&opkt);
	opkt.flags        = pkt.flags;
	opkt.pts          = av_rescale_q_rnd(pkt.pts, AV_TIME_BASE_Q, bc_rec->rtp_stream_ctx[ctx_index]->streams[0]->time_base, (enum AVRounding)(AV_ROUND_NEAR_INF|AV_ROUND_PASS_MINMAX));
	opkt.dts          = av_rescale_q_rnd(pkt.dts, AV_TIME_BASE_Q, bc_rec->rtp_stream_ctx[ctx_index]->streams[0]->time_base, (enum AVRounding)(AV_ROUND_NEAR_INF|AV_ROUND_PASS_MINMAX));

	opkt.data         = const_cast<uint8_t*>(pkt.data());
	opkt.size         = pkt.size;
	opkt.stream_index = 0;

	if (monotonic_dts(&lv.rtp_last_dts[ctx_index], &opkt))
		lv.ts_adjusted++;

	bc_rec->cur_pkt_flags = pkt.flags;
	bc_rec->cur_stream_index = ctx_index;
	bc_rec->pkt_first_chunk = true;

	re = av_write_frame(bc_rec->rtp_stream_ctx[ctx_index], &opkt);
	if (re < 0) {
		if (re == AVERROR(EINVAL) || re == AVERROR_INVALIDDATA) {
			lv.write_rejected++;
			live_report(bc_rec, bc_gettime_monotonic());
			return 1;
		}
		char err[512] = { 0 };
		av_strerror(re, err, sizeof(err));
		bc_rec->log.log(Error, "Can't write to live stream (RTSP): %s. Recreating the live "
			"muxer; camera connection and recording are kept", err);

		rtp_ctx_free(bc_rec);
		lv.rtp_retry_at = bc_gettime_monotonic() + LIVE_RETRY_SEC;
		return 0;
	}

	return 1;
}

/* Returns 1 if the packet was handed to the muxer, 0 if it was skipped.
 * Never fails: whatever goes wrong with the HLS live view is repaired here
 * (packet dropped, timeline rebased or muxer recreated) so that the caller
 * keeps the camera connection, and therefore the recording, untouched. */
int bc_streaming_hls_packet_write(struct bc_record *bc_rec, const stream_packet &pkt)
{
	bc_record::live_state &lv = bc_rec->live;
	AVDictionary *muxer_opts = NULL;
	AVPacket opkt;
	int re;
	int ctx_index;

	if (pkt.type == AVMEDIA_TYPE_VIDEO) {
		ctx_index = 0;
	} else if (pkt.type == AVMEDIA_TYPE_AUDIO) {
		ctx_index = 1;
	} else {
		bc_rec->log.log(Warning, "Got unknown packet type, ignoring.");
		return 0;
	}

	time_t now = bc_gettime_monotonic();
	bool is_video_key = (ctx_index == 0) && (pkt.flags & stream_packet::KeyframeFlag);

	live_report(bc_rec, now);

	/* Single muxed A/V context (see bc_streaming_setup_hls): ctx_index is now
	 * the STREAM index inside one context (0 video, 1 audio), not a separate
	 * context. */
	AVFormatContext *ctx = bc_rec->hls_stream_ctx[0];

	if (!ctx) {
		/* Muxer lost (write error, stall, failed setup): rebuild it alone,
		 * starting at a keyframe so the next segment is decodable. */
		if (!lv.hls_wanted || !is_video_key || now < lv.hls_retry_at || !pkt.properties())
			return 0;

		if (bc_streaming_setup_hls(bc_rec, pkt.properties())) {
			lv.hls_retry_at = now + LIVE_RETRY_SEC;
			return 0;
		}

		bc_rec->log.log(Info, "Live view (HLS) muxer recreated");
		ctx = bc_rec->hls_stream_ctx[0];
	}

	if (!ctx->streams || !ctx->nb_streams || !ctx->streams[0]) {
		bc_rec->log.log(Error, "Invalid HLS streaming context: nb_streams=%d; recreating it",
			(int)ctx->nb_streams);
		hls_live_restart(bc_rec, now);
		return 0;
	}

	// Audio packet on a device whose muxer has no audio stream -> drop silently.
	if (ctx_index >= (int)ctx->nb_streams || !ctx->streams[ctx_index])
		return 0;

	if (lv.hls_wait_keyframe) {
		if (!is_video_key)
			return 0;
		lv.hls_wait_keyframe = false;
	}

	/* Stall watchdog: the camera is delivering keyframes (each one should cut
	 * a segment) yet no segment has come out. Recreate this camera's muxer;
	 * the limit doubles while that does not help, so a stream that can never
	 * be segmented costs one log line per attempt, not a loop. */
	hls_content *content = hls_content_of(bc_rec);
	if (content && is_video_key) {
		uint32_t produced = content->segment_counter();

		if (produced != lv.hls_segments_seen) {
			lv.hls_segments_seen = produced;
			lv.hls_segment_time = now;
			lv.hls_keyframes_fed = 0;
			lv.hls_stall_limit = HLS_STALL_SEC;
		} else if (++lv.hls_keyframes_fed > 2 &&
			   now - lv.hls_segment_time > (time_t)hls_stall_limit(content, lv)) {
			bc_rec->log.log(Error, "Live view (HLS) produced no segment for %ld s with the camera "
				"delivering video (%u keyframes); recreating its muxer, recording is not affected",
				(long)(now - lv.hls_segment_time), lv.hls_keyframes_fed);

			if (lv.hls_stall_limit < HLS_STALL_SEC_MAX)
				lv.hls_stall_limit *= 2;

			hls_live_restart(bc_rec, now);
			return 0;
		}
	}

	/* Timeline. Source timestamps restart on every reconnection and may jump
	 * at any time; players need them to keep increasing. hls_ts_offset moves
	 * the source timeline (both streams together, preserving A/V sync) so
	 * that it continues right after what was already sent. */
	int64_t src_dts = pkt.dts != AV_NOPTS_VALUE ? pkt.dts : pkt.pts;
	int64_t src_pts = pkt.pts != AV_NOPTS_VALUE ? pkt.pts : src_dts;

	if (src_dts == AV_NOPTS_VALUE) {
		/* No timestamps at all: place it at the current position */
		if (lv.hls_ref_ts == AV_NOPTS_VALUE) {
			lv.pkts_dropped++;
			return 0;
		}
		src_dts = src_pts = lv.hls_ref_ts - lv.hls_ts_offset;
		lv.ts_adjusted++;
	}

	if (lv.hls_ref_ts == AV_NOPTS_VALUE) {
		/* First packet of this camera: output timeline starts at 1 s */
		lv.hls_ts_offset = AV_TIME_BASE - src_dts;
	} else {
		int64_t out = src_dts + lv.hls_ts_offset;

		if (out < lv.hls_ref_ts - HLS_TS_BACK_LIMIT || out > lv.hls_ref_ts + HLS_TS_FWD_LIMIT) {
			if (now - lv.hls_rebase_time < HLS_TS_REBASE_HOLD_SEC) {
				/* Leftover of the timeline we have just left */
				lv.pkts_dropped++;
				return 0;
			}

			lv.hls_ts_offset = lv.hls_ref_ts + HLS_TS_REBASE_GAP - src_dts;
			lv.hls_rebase_time = now;
			lv.ts_rebased++;
		}
	}

	src_dts += lv.hls_ts_offset;
	src_pts += lv.hls_ts_offset;
	if (lv.hls_ref_ts == AV_NOPTS_VALUE || src_dts > lv.hls_ref_ts)
		lv.hls_ref_ts = src_dts;

	av_init_packet(&opkt);
	opkt.flags        = (pkt.flags & stream_packet::KeyframeFlag) ? AV_PKT_FLAG_KEY : 0;
	opkt.pts          = av_rescale_q_rnd(src_pts, AV_TIME_BASE_Q, ctx->streams[ctx_index]->time_base, (enum AVRounding)(AV_ROUND_NEAR_INF|AV_ROUND_PASS_MINMAX));
	opkt.dts          = av_rescale_q_rnd(src_dts, AV_TIME_BASE_Q, ctx->streams[ctx_index]->time_base, (enum AVRounding)(AV_ROUND_NEAR_INF|AV_ROUND_PASS_MINMAX));

	opkt.data         = const_cast<uint8_t*>(pkt.data());
	opkt.size         = pkt.size;
	opkt.stream_index = ctx_index;

	/* av_write_frame (below) returns EINVAL for a DTS that does not increase */
	if (monotonic_dts(&lv.hls_last_dts[ctx_index], &opkt))
		lv.ts_adjusted++;

	/* Segmentation (hls_content::add_data) keys on cur_pkt_flags/cur_pts and
	 * cuts a segment on a video keyframe. Video drives it; audio must neither
	 * cut a segment nor move the pts timeline, or segments break. So for audio
	 * strip the KEY flag (every AAC frame is "key") and keep cur_pts on the last
	 * video pts. */
	bc_rec->pkt_first_chunk = true;
	bc_rec->cur_stream_index = ctx_index;
	if (ctx_index == 0) {
		bc_rec->cur_pkt_flags = opkt.flags;
		bc_rec->cur_pts = opkt.pts;
	} else {
		bc_rec->cur_pkt_flags = 0;
		/* keep bc_rec->cur_pts (last video pts) */
	}

	/* av_write_frame, NOT av_interleaved_write_frame: interleaving buffers one
	 * packet per stream, so bytes flushed by hls_io_write during a call could
	 * belong to a previously buffered packet -> the cur_pkt_flags/cur_pts set
	 * above would apply to the wrong bytes and break segmentation. Writing
	 * un-interleaved flushes THIS packet immediately, keeping them in sync; the
	 * live source already delivers A/V in near-DTS order. */
	re = av_write_frame(ctx, &opkt);
	if (re < 0) {
		if (re == AVERROR(EINVAL) || re == AVERROR_INVALIDDATA) {
			/* This packet was refused, the muxer itself is fine. If nothing
			 * gets through, the stall watchdog above takes over. */
			lv.write_rejected++;
			return 0;
		}

		char err[512] = { 0 };
		av_strerror(re, err, sizeof(err));
		bc_rec->log.log(Error, "Can't write to live stream (HLS): %s. Recreating the live "
			"muxer; camera connection and recording are kept", err);

		hls_live_restart(bc_rec, now);
		return 0;
	}

	// SAFE ACCESS: mpeg4/fmp4 needs a header per fragment (video stream = index 0)
	AVStream *hls_stream = ctx->streams[0];
	if (hls_stream && hls_stream->codecpar &&
	    hls_stream->codecpar->codec_id == AV_CODEC_ID_MPEG4) {
		// Write header for every MP4 fragment to be used as independent segments in HLS playlist
		av_dict_set(&muxer_opts, "movflags", "frag_keyframe+empty_moov+default_base_moof", 0);
		int ret;
		ret = avformat_write_header(ctx, &muxer_opts);
		av_dict_free(&muxer_opts);
		if (ret < 0) {
			bc_rec->log.log(Error, "Failed to write header in fragmented MP4 payload; "
				"recreating the live muxer");
			hls_live_restart(bc_rec, now);
			return 0;
		}
	}

	return 1;
}
