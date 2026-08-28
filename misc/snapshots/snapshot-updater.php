#!/usr/bin/env php
<?php
/**
 * snapshot-updater.php — mantém um JPEG recente de cada câmera (G2W Monitor).
 *
 * A cada INTERVAL, pega um quadro de cada câmera habilitada e grava em
 * /var/lib/bluecherry/snapshots/<id>.jpg. O app serve isso como miniatura via
 * mediaRequest.php?mode=livesnapshot — assim a miniatura NÃO depende de
 * gravação (a câmera pode estar só ao vivo).
 *
 * Sobe pelo entrypoint do container. Relê a lista de câmeras a cada volta, pra
 * pegar câmera nova/removida sem reiniciar. Uma câmera que falha não trava as
 * outras. Desligar com SNAPSHOT_UPDATER=0.
 *
 * Ver: misc/snapshots/snapshot-lib.php (helpers) e
 *      www/ajax/media/mediaRequest.php (endpoint que serve o JPEG).
 */

DEFINE('INDVR', true);

$site_path = '/usr/share/bluecherry/www';
define('site_path', $site_path . '/');
define('lib_path', site_path . 'lib/');
define('ctl_path', site_path . 'ajax/');
define('template_path', site_path . 'template/');

require_once site_path . 'vendor/autoload.php';
require_once lib_path . 'lib.php';
require_once '/usr/share/bluecherry/snapshots/snapshot-lib.php';

// ~2min entre atualizações (a imagem não precisa ser muito recente — decisão do
// Gabriel). Sobrescreve com SNAPSHOT_INTERVAL no ambiente se quiser.
$INTERVAL = intval(getenv('SNAPSHOT_INTERVAL') ?: 120);
if ($INTERVAL < 15) $INTERVAL = 15;   // piso de segurança

function snap_log($msg)
{
    echo date('Y-m-d\TH:i:sP'), ' [snapshot-updater] ', $msg, "\n";
    flush();
}

bc_snap_dir();
snap_log("iniciando (intervalo {$INTERVAL}s)");

while (true) {
    $t0 = time();

    // câmeras IP habilitadas (as que têm stream RTSP/relay). O bloco de captura
    // de placa analógica não passa por aqui — só IP-RTSP/IP/MJPEG.
    $cams = data::query(
        "SELECT id, device, rtsp_username, rtsp_password FROM Devices " .
        "WHERE disabled='0' AND protocol IN ('IP-RTSP','IP','IP-MJPEG')"
    );

    if (is_array($cams)) {
        foreach ($cams as $c) {
            $url = bc_snap_rtsp_url($c);
            if (!$url) { continue; }
            $ok = bc_snap_grab($url, bc_snap_path($c['id']));
            if (!$ok) {
                snap_log("camera {$c['id']}: falha ao capturar quadro (segue)");
            }
        }
    }

    // dorme o resto do intervalo (desconta o tempo gasto capturando)
    $elapsed = time() - $t0;
    $sleep = $INTERVAL - $elapsed;
    if ($sleep > 0) { sleep($sleep); }
}
