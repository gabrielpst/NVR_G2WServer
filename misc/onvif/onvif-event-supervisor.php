#!/usr/bin/env php
<?php
/**
 * Supervisor da ponte de eventos ONVIF (G2W Monitor).
 *
 * Mantém um processo onvif-event-bridge.php por câmera que tenha a opção
 * "Trigger recording on ONVIF events" ligada, reinicia quem morre, e recarrega
 * a lista periodicamente pra pegar câmera nova/removida sem precisar reiniciar
 * o servidor.
 *
 * A ponte sai sozinha de tempos em tempos (pra renovar a assinatura ONVIF, que
 * tem validade) — quem religa é este supervisor.
 *
 * Sobe pelo entrypoint do container. Ver também:
 *   misc/onvif/onvif-event-bridge.php — o trabalhador por câmera
 *   server/trigger_server.cpp — o socket que recebe o gatilho
 */

DEFINE('INDVR', true);

$site_path = '/usr/share/bluecherry/www';
define('site_path', $site_path . '/');
define('lib_path', site_path . 'lib/');
define('ctl_path', site_path . 'ajax/');
define('template_path', site_path . 'template/');

require_once site_path . 'vendor/autoload.php';
require_once lib_path . 'lib.php';

define('BRIDGE_SCRIPT', '/usr/share/bluecherry/onvif/onvif-event-bridge.php');
define('RELOAD_INTERVAL', 20);   // segundos entre releituras da lista
define('RESPAWN_DELAY', 3);      // espera mínima antes de religar um worker

function sup_log($msg)
{
    echo date('Y-m-d\TH:i:sP'), ' [onvif-supervisor] ', $msg, "\n";
    flush();
}

$workers   = Array();   // device_id => ['proc' => resource, 'started' => ts]
$last_exit = Array();   // device_id => ts da última saída (pra não religar em laço)
$warned    = Array();   // device_id => já avisou sobre agenda sem 'T'

sup_log('iniciando');

if (!file_exists(BRIDGE_SCRIPT)) {
    sup_log('ERRO: ' . BRIDGE_SCRIPT . ' nao encontrado — saindo');
    exit(1);
}

while (true) {
    // --- limpa quem terminou -------------------------------------------------
    foreach ($workers as $id => $w) {
        $st = proc_get_status($w['proc']);
        if ($st && $st['running']) { continue; }

        proc_close($w['proc']);
        unset($workers[$id]);
        $last_exit[$id] = time();
        sup_log("worker da camera {$id} terminou (saida " . (isset($st['exitcode']) ? $st['exitcode'] : '?') . ") — vai religar");
    }

    // --- lê quem deveria estar rodando --------------------------------------
    $rows = data::query(
        "SELECT id, device_name, schedule, schedule_override_global, onvif_events_enabled, disabled"
        . " FROM Devices WHERE onvif_events_enabled = 1 AND (disabled IS NULL OR disabled = 0)"
    );
    if (!is_array($rows)) { $rows = Array(); }

    $should_run = Array();

    foreach ($rows as $row) {
        $id = (int)$row['id'];

        // O gatilho só é aceito quando a hora corrente está em 'T' na agenda —
        // fora disso o bc-server nem cria o trigger_processor e responde
        // "404 Processor Not Found". Melhor não subir o worker e dizer o porquê
        // uma vez, do que ficar disparando gatilho recusado.
        $sched = !empty($row['schedule_override_global'])
            ? (string)$row['schedule']
            : (string)data_global_schedule();

        if (strpos($sched, 'T') === false) {
            if (empty($warned[$id])) {
                sup_log("camera {$id} ({$row['device_name']}): eventos ONVIF ligados, mas a agenda nao tem nenhuma hora em 'Trigger' — worker nao sera iniciado");
                $warned[$id] = true;
            }
            continue;
        }
        unset($warned[$id]);

        $should_run[$id] = true;
    }

    // --- derruba worker de câmera que saiu da lista --------------------------
    foreach ($workers as $id => $w) {
        if (isset($should_run[$id])) { continue; }
        sup_log("camera {$id} nao deve mais rodar — encerrando worker");
        proc_terminate($w['proc']);
    }

    // --- sobe quem falta -----------------------------------------------------
    foreach ($should_run as $id => $_) {
        if (isset($workers[$id])) { continue; }
        if (isset($last_exit[$id]) && (time() - $last_exit[$id]) < RESPAWN_DELAY) { continue; }

        $cmd = 'php ' . escapeshellarg(BRIDGE_SCRIPT) . ' ' . (int)$id;

        // stdout/stderr do worker vão pro mesmo lugar que o do supervisor, que o
        // entrypoint já redireciona pro log do container.
        $descriptors = Array(
            0 => Array('file', '/dev/null', 'r'),
            1 => STDOUT,
            2 => STDERR,
        );

        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            sup_log("ERRO ao iniciar worker da camera {$id}");
            $last_exit[$id] = time();
            continue;
        }

        $workers[$id] = Array('proc' => $proc, 'started' => time());
        sup_log("worker iniciado para camera {$id}");
    }

    sleep(RELOAD_INTERVAL);
}

/**
 * Agenda global, usada quando a câmera não tem agenda própria
 * (schedule_override_global = 0).
 */
function data_global_schedule()
{
    $rows = data::query("SELECT value FROM GlobalSettings WHERE parameter = 'G_DEV_SCED'");
    return (!empty($rows[0]['value'])) ? $rows[0]['value'] : '';
}
