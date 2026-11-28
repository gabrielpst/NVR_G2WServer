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
    $agenda = null;
    // G2W 2026-11: uma volta que falha (banco fora, conexão derrubada)
    // não pode matar o atualizador: registra, espera e tenta de novo.
    try {

        // câmeras IP habilitadas (as que têm stream RTSP/relay). O bloco de captura
        // de placa analógica não passa por aqui — só IP-RTSP/IP/MJPEG.
        $cams = data::query(
            "SELECT id, device, rtsp_username, rtsp_password FROM Devices " .
            "WHERE disabled='0' AND protocol IN ('IP-RTSP','IP','IP-MJPEG')"
        );

        // "Formato da imagem" de cada câmera (tabela do painel; pode não existir
        // em instalação antiga: aí vale o automático para todas).
        $formatos = array();
        $tem_formatos = data::query("SHOW TABLES LIKE 'G2WPainelFormatos'");
        if (is_array($tem_formatos) && $tem_formatos) {
            $fs = data::query("SELECT device_id, formato, alterado_em FROM G2WPainelFormatos");
            if (is_array($fs)) {
                foreach ($fs as $f) $formatos[intval($f['device_id'])] = $f;
            }
        }

        // Agenda por instalação (/etc/g2w/miniaturas.json): renova só nos horários
        // fixos. Fora deles, só captura a câmera que ainda não tem miniatura
        // (câmera nova) ou cuja miniatura é anterior ao último horário (falhou na
        // hora, ou o container estava parado). Relida a cada volta: mudar o arquivo
        // não exige reinício.
        $agenda = bc_snap_agenda();
        $corte = $agenda ? bc_snap_ultimo_horario($agenda, $t0) : 0;

        if (is_array($cams)) {
            foreach ($cams as $c) {
                $fmt = isset($formatos[intval($c['id'])]) ? $formatos[intval($c['id'])] : null;
                if ($agenda) {
                    $mt = @filemtime(bc_snap_path($c['id']));
                    // formato trocado no painel depois da última foto: refaz já
                    $mudou = $fmt && $mt && intval($fmt['alterado_em']) > $mt;
                    if ($mt && $mt >= $corte && !$mudou) { continue; }
                }
                $url = bc_snap_rtsp_url($c);
                if (!$url) { continue; }
                $ok = bc_snap_grab($url, bc_snap_path($c['id']), 15, bc_snap_filtro($fmt ? $fmt['formato'] : 'auto'));
                if (!$ok) {
                    snap_log("camera {$c['id']}: falha ao capturar quadro (segue)");
                }
            }
        }

    } catch (Throwable $e) {
        snap_log('volta interrompida: ' . get_class($e) . ' (' . $e->getCode() . '); nova tentativa em 30 s');
        sleep(30);
        continue;
    }

    // dorme o resto do intervalo (desconta o tempo gasto capturando)
    $elapsed = time() - $t0;
    $sleep = $INTERVAL - $elapsed;
    if ($agenda) {
        // com agenda, a câmera fora do ar seria tentada a cada volta até o
        // próximo horário: espaça as novas tentativas em 10 min, mas acorda
        // assim que um horário agendado chega.
        // Acorda também quando um formato é trocado no painel (a foto tem de
        // sair no formato novo sem esperar o horário). A consulta a cada 30 s
        // mantém a conexão com o banco em uso.
        for ($i = 0; $i < 20; $i++) {
            sleep(30);
            if (bc_snap_ultimo_horario($agenda) > $corte) { break; }
            try {
                $r = data::query("SELECT COALESCE(MAX(alterado_em), 0) AS m FROM G2WPainelFormatos");
                if (is_array($r) && $r && intval($r[0]['m']) >= $t0) { break; }
            } catch (Throwable $e) {
                // tabela ainda não existe ou banco fora: segue esperando
            }
        }
        continue;
    }
    if ($sleep > 0) { sleep($sleep); }
}
