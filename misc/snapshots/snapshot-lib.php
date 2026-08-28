<?php
/**
 * snapshot-lib.php — helpers de snapshot ao vivo (G2W Monitor).
 *
 * Miniatura sem depender de gravação: um quadro recente de cada câmera é
 * gravado num JPEG pelo snapshot-updater.php (a cada ~2min) e servido pelo
 * endpoint mediaRequest.php?mode=livesnapshot. Estes helpers são
 * compartilhados pelos dois (o endpoint também faz um grab único de fallback
 * na primeira vez, se o updater ainda não passou por aquela câmera).
 *
 * Não depende de gravação — resolve a miniatura preta quando a câmera está só
 * ao vivo (cenário do Silvestre4, disco BX500 que não aguenta gravar).
 */

if (!defined('BC_SNAP_DIR')) {
    define('BC_SNAP_DIR', '/var/lib/bluecherry/snapshots');
}
// ffmpeg do próprio bluecherry (não há ffmpeg no PATH da imagem). Usa `env`
// pra setar o LD_LIBRARY_PATH: como o comando roda atrás de `timeout`, uma
// atribuição de variável crua (VAR=... cmd) não é interpretada — o `timeout`
// tentaria executar um programa chamado "LD_LIBRARY_PATH=...". `env` resolve.
if (!defined('BC_FFMPEG')) {
    define('BC_FFMPEG', 'env LD_LIBRARY_PATH=/usr/lib/bluecherry/ /usr/lib/bluecherry/ffmpeg');
}

/** Garante o diretório de snapshots; devolve o caminho. */
function bc_snap_dir()
{
    if (!is_dir(BC_SNAP_DIR)) {
        @mkdir(BC_SNAP_DIR, 0775, true);
    }
    return BC_SNAP_DIR;
}

/** Caminho do JPEG de uma câmera. */
function bc_snap_path($deviceId)
{
    return bc_snap_dir() . '/' . intval($deviceId) . '.jpg';
}

/**
 * Monta a URL RTSP de uma linha da tabela Devices.
 * device = "host|port|path" (ex.: "mediamtx|8554|/entradaveiculos"); credencial
 * em rtsp_username/rtsp_password (vazias nas câmeras que passam pelo MediaMTX).
 * Só suporta as câmeras IP-RTSP/IP (as que têm esse formato) — devolve null pro
 * resto. A senha vai rawurlencode (o '@' de "@g2w1234" quebraria a URL).
 */
function bc_snap_rtsp_url($row)
{
    if (empty($row['device']) || strpos($row['device'], '|') === false) {
        return null;
    }
    list($host, $port, $path) = array_pad(explode('|', $row['device'], 3), 3, '');
    if ($host === '' || $port === '') {
        return null;
    }
    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . $path;
    }
    $userinfo = '';
    if (!empty($row['rtsp_username'])) {
        $userinfo = rawurlencode($row['rtsp_username']) . ':' .
                    rawurlencode($row['rtsp_password'] ?? '') . '@';
    }
    return "rtsp://{$userinfo}{$host}:{$port}{$path}";
}

/**
 * Grava UM quadro do stream num JPEG (downscale 640, qualidade de miniatura).
 * Escreve num .tmp e renomeia (atômico — o endpoint nunca lê um JPEG parcial).
 * Devolve true se gerou o arquivo.
 */
function bc_snap_grab($rtspUrl, $destPath, $timeoutSec = 15)
{
    if (empty($rtspUrl)) {
        return false;
    }
    $tmp = $destPath . '.tmp';
    // -rtsp_transport tcp: confiável na malha. nobuffer/probesize/analyzeduration
    // baixos: reduz o tempo de análise do ffmpeg. -frames:v 1: um quadro só.
    $cmd = sprintf(
        'timeout %d %s -nostdin -rtsp_transport tcp -fflags nobuffer -flags low_delay ' .
        '-probesize 500000 -analyzeduration 1000000 -y -i %s ' .
        '-vf scale=640:-2 -frames:v 1 -q:v 6 -f image2 %s >/dev/null 2>&1',
        intval($timeoutSec), BC_FFMPEG,
        escapeshellarg($rtspUrl), escapeshellarg($tmp)
    );
    system($cmd, $rc);
    if ($rc === 0 && file_exists($tmp) && filesize($tmp) > 0) {
        rename($tmp, $destPath);   // atômico no mesmo filesystem
        return true;
    }
    @unlink($tmp);
    return false;
}
