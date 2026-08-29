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

static int bc_streaming_setup_elementary(struct bc_record *bc_rec, std::shared_ptr<const stream_properties> props,
	int index, enum AVMediaType type, bc_streaming_type bc_type);
static int bc_streaming_setup_hls(struct bc_record *bc_rec, std::shared_ptr<const stream_properties> props);

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
		ret = bc_streaming_setup_hls(bc_rec, props);
		if (ret)
			return ret;
		return 0;
	}

	ret |= bc_streaming_setup_elementary(bc_rec, props, 0, AVMEDIA_TYPE_VIDEO, bc_type);
	if (bc_rec->bc->input->has_audio())
		ret |= bc_streaming_setup_elementary(bc_rec, props, 1, AVMEDIA_TYPE_AUDIO, bc_type);
	if (ret)
		return ret;

	if (bc_type == BC_RTP)
		bc_rec->rtsp_stream = rtsp_stream::create(bc_rec, bc_rec->rtp_stream_ctx);

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
	bool with_audio = props->has_audio();
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

void bc_streaming_destroy_rtp(struct bc_record *bc_rec)
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

void bc_streaming_destroy_hls(struct bc_record *bc_rec)
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

int bc_streaming_packet_write(struct bc_record *bc_rec, const stream_packet &pkt)
{
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

	if (!bc_streaming_is_active(bc_rec))
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
		return -1;
	}

	av_init_packet(&opkt);
	opkt.flags        = pkt.flags;
	opkt.pts          = av_rescale_q_rnd(pkt.pts, AV_TIME_BASE_Q, bc_rec->rtp_stream_ctx[ctx_index]->streams[0]->time_base, (enum AVRounding)(AV_ROUND_NEAR_INF|AV_ROUND_PASS_MINMAX));
	opkt.dts          = av_rescale_q_rnd(pkt.dts, AV_TIME_BASE_Q, bc_rec->rtp_stream_ctx[ctx_index]->streams[0]->time_base, (enum AVRounding)(AV_ROUND_NEAR_INF|AV_ROUND_PASS_MINMAX));

	opkt.data         = const_cast<uint8_t*>(pkt.data());
	opkt.size         = pkt.size;
	opkt.stream_index = 0;

	bc_rec->cur_pkt_flags = pkt.flags;
	bc_rec->cur_stream_index = ctx_index;
	bc_rec->pkt_first_chunk = true;

	re = av_write_frame(bc_rec->rtp_stream_ctx[ctx_index], &opkt);
	if (re < 0) {
		if (re == AVERROR(EINVAL)) {
			bc_rec->log.log(Warning, "Likely timestamping(%ld) error. Ignoring.", opkt.pts);
			return 1;
		}
		char err[512] = { 0 };
		av_strerror(re, err, sizeof(err));
		bc_rec->log.log(Error, "Can't write to live stream: %s", err);

		/* Cleanup */
		stop_handle_properly(bc_rec);
		return -1;
	}

	return 1;
}

int bc_streaming_hls_packet_write(struct bc_record *bc_rec, const stream_packet &pkt)
{
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

	/* Single muxed A/V context (see bc_streaming_setup_hls): ctx_index is now
	 * the STREAM index inside one context (0 video, 1 audio), not a separate
	 * context. */
	AVFormatContext *ctx = bc_rec->hls_stream_ctx[0];
	if (!ctx || !ctx->streams)
		return 0;

	// Audio packet on a device whose muxer has no audio stream -> drop silently.
	if (ctx_index >= (int)ctx->nb_streams || !ctx->streams[ctx_index]) {
		if (ctx_index != 0)
			return 0;
		bc_rec->log.log(Error, "Invalid HLS streaming context: nb_streams=%d",
			(int)ctx->nb_streams);
		return -1;
	}

	av_init_packet(&opkt);
	opkt.flags        = pkt.flags;
	opkt.pts          = av_rescale_q_rnd(pkt.pts, AV_TIME_BASE_Q, ctx->streams[ctx_index]->time_base, (enum AVRounding)(AV_ROUND_NEAR_INF|AV_ROUND_PASS_MINMAX));
	opkt.dts          = av_rescale_q_rnd(pkt.dts, AV_TIME_BASE_Q, ctx->streams[ctx_index]->time_base, (enum AVRounding)(AV_ROUND_NEAR_INF|AV_ROUND_PASS_MINMAX));

	opkt.data         = const_cast<uint8_t*>(pkt.data());
	opkt.size         = pkt.size;
	opkt.stream_index = ctx_index;

	/* Segmentation (hls_content::add_data) keys on cur_pkt_flags/cur_pts and
	 * cuts a segment on a video keyframe. Video drives it; audio must neither
	 * cut a segment nor move the pts timeline, or segments break. So for audio
	 * strip the KEY flag (every AAC frame is "key") and keep cur_pts on the last
	 * video pts. */
	bc_rec->pkt_first_chunk = true;
	bc_rec->cur_stream_index = ctx_index;
	if (ctx_index == 0) {
		bc_rec->cur_pkt_flags = pkt.flags;
		bc_rec->cur_pts = opkt.pts;
	} else {
		bc_rec->cur_pkt_flags = pkt.flags & ~AV_PKT_FLAG_KEY;
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
		if (re == AVERROR(EINVAL)) {
			bc_rec->log.log(Warning, "Likely timestamping error. Ignoring.");
			return 1;
		}

		char err[512] = { 0 };
		av_strerror(re, err, sizeof(err));
		bc_rec->log.log(Error, "Can't write to live stream: %s", err);

		/* Cleanup */
		stop_handle_properly(bc_rec);
		return -1;
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
			bc_rec->log.log(Error, "Failed to write header in fragmented MP4 payload");
			return -1;
		}
	}

	return 1;
}
