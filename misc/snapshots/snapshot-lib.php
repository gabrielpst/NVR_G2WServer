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

/**
 * Agenda de miniaturas por instalação (opcional).
 *
 * Sem o arquivo, vale o padrão: renovar a cada SNAPSHOT_INTERVAL (~2 min).
 * Com /etc/g2w/miniaturas.json a instalação passa a renovar só em horários
 * fixos do dia e fica com aquela imagem até o próximo horário:
 *
 *   { "horarios": ["08:00", "14:00"], "fuso": "America/Campo_Grande" }
 *
 * O fuso é explícito porque o PHP de linha de comando do container roda em UTC.
 * Devolve array('horarios' => [minutos do dia, ...] em ordem, 'fuso' => DateTimeZone)
 * ou null quando não há agenda válida.
 */
if (!defined('BC_SNAP_AGENDA')) {
    define('BC_SNAP_AGENDA', '/etc/g2w/miniaturas.json');
}
function bc_snap_agenda()
{
    if (!is_readable(BC_SNAP_AGENDA)) {
        return null;
    }
    $d = json_decode((string)@file_get_contents(BC_SNAP_AGENDA), true);
    if (!is_array($d) || empty($d['horarios']) || !is_array($d['horarios'])) {
        return null;
    }
    $min = array();
    foreach ($d['horarios'] as $h) {
        if (is_string($h) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($h), $m)) {
            $min[intval($m[1]) * 60 + intval($m[2])] = true;
        }
    }
    if (!$min) {
        return null;
    }
    $min = array_keys($min);
    sort($min);
    try {
        $tz = new DateTimeZone(!empty($d['fuso']) && is_string($d['fuso']) ? $d['fuso'] : 'America/Campo_Grande');
    } catch (Exception $e) {
        $tz = new DateTimeZone('America/Campo_Grande');
    }
    return array('horarios' => $min, 'fuso' => $tz);
}

/**
 * Timestamp do horário agendado mais recente (hoje ou ontem) em relação a $agora.
 */
function bc_snap_ultimo_horario($agenda, $agora = null)
{
    $agora = ($agora === null) ? time() : intval($agora);
    $dia = new DateTime('@' . $agora);
    $dia->setTimezone($agenda['fuso']);
    $dia->setTime(0, 0, 0);
    $melhor = 0;
    foreach (array(-1, 0) as $desloc) {
        $base = clone $dia;
        if ($desloc) { $base->modify('-1 day'); }
        foreach ($agenda['horarios'] as $m) {
            $t = clone $base;
            $t->setTime(intdiv($m, 60), $m % 60, 0);
            $ts = $t->getTimestamp();
            if ($ts <= $agora && $ts > $melhor) { $melhor = $ts; }
        }
    }
    return $melhor;
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
    // -skip_frame nokey (2026-09-03): decodifica SÓ keyframes — sem isso o
    // primeiro quadro entregue é um P/B-frame sem referência (câmeras H.264/HEVC
    // via RTMP/MediaMTX, GOP ~5s) e o JPEG sai cinza com fragmentos (1,6 KB).
    // Custo: espera até o próximo IDR (≤ 1 GOP, ~4-5 s medidos). Testado nas
    // duas câmeras do Silvestre4 — ver contexto 2026-09-03 no Nextcloud.
    // -k 3 (2026-11-25): o ffmpeg preso na leitura do RTSP ignora o TERM do
    // timeout e fica pendurado, travando a fila inteira (K Soluções: 6 h sem
    // miniatura nova). Com -k, 3 s depois do TERM vem o KILL.
    $cmd = sprintf(
        'timeout -k 3 %d %s -nostdin -rtsp_transport tcp -skip_frame nokey -fflags nobuffer -flags low_delay ' .
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
