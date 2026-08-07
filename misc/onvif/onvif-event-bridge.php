#!/usr/bin/env php
<?php
/**
 * Ponte de eventos ONVIF (G2W Monitor).
 *
 * Faz a câmera avisar o servidor quando ELA detecta movimento, em vez do
 * servidor ficar decodificando vídeo pra detectar sozinho — o que consome CPU
 * proporcional ao número de câmeras (e mais ainda em H.265).
 *
 * Como funciona:
 *   1. assina os eventos da câmera (ONVIF CreatePullPointSubscription);
 *   2. fica pedindo os eventos pendentes (PullMessages, long-poll);
 *   3. quando chega evento de movimento, escreve no socket de gatilho do
 *      bc-server (/tmp/bluecherry_trigger), que grava com pré-gravação.
 *
 * A câmera precisa estar no modo de agenda "Trigger" (T) — é nesse modo que o
 * bc-server registra o trigger_processor e NÃO cria o analisador de movimento
 * (ou seja, não decodifica nada).
 *
 * Por que não usar o recurso "ONVIF events" nativo do servidor: ele executa
 * /usr/lib/bluecherry/onvif_tool (server/onvif_events.cpp:73), binário que o
 * pacote nunca instala — e ainda reexecuta em laço sem espera nenhuma quando
 * falha. Esta ponte substitui aquilo sem depender de binário externo.
 *
 * Uso:
 *   php onvif-event-bridge.php <device_id> [--once] [--verbose]
 *
 *   --once     faz um ciclo de PullMessages e sai (pra testar)
 *   --verbose  imprime todo evento recebido, não só os de movimento
 */

DEFINE('INDVR', true);

$site_path = '/usr/share/bluecherry/www';
define('site_path', $site_path . '/');
define('lib_path', site_path . 'lib/');
define('ctl_path', site_path . 'ajax/');
define('template_path', site_path . 'template/');

require_once site_path . 'vendor/autoload.php';
require_once lib_path . 'lib.php';

define('TRIGGER_SOCKET', '/tmp/bluecherry_trigger');

/**
 * Estende Ponvif só pra reaproveitar a autenticação WS-Security (UsernameToken
 * com digest + nonce + timestamp corrigido pelo relógio da câmera) e o parser
 * de XML que já existem lá.
 */
class OnvifEventClient extends Ponvif
{
    public function securityHeader()
    {
        $REQ = $this->_makeToken();

        return '<Security s:mustUnderstand="1" xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">'
             . '<UsernameToken>'
             . '<Username>' . htmlspecialchars($REQ['USERNAME'], ENT_XML1) . '</Username>'
             . '<Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">' . $REQ['PASSDIGEST'] . '</Password>'
             . '<Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">' . $REQ['NONCE'] . '</Nonce>'
             . '<Created xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">' . $REQ['TIMESTAMP'] . '</Created>'
             . '</UsernameToken>'
             . '</Security>';
    }

    /**
     * Envio próprio (não usa Ponvif::_send_request) porque o PullMessages é
     * long-poll: a câmera segura a resposta até ter evento ou estourar o
     * timeout pedido. O timeout fixo de 10s do Ponvif cortaria a espera.
     * Devolve o XML cru — o parser de array do Ponvif perde os prefixos de
     * namespace, que variam entre fabricantes e são justamente o que
     * precisamos ler aqui.
     */
    public function soapRaw($url, $body_xml, $action, $timeout = 40)
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:a="http://www.w3.org/2005/08/addressing">'
            . '<s:Header>'
            . '<a:Action s:mustUnderstand="1">' . $action . '</a:Action>'
            . '<a:To s:mustUnderstand="1">' . htmlspecialchars($url, ENT_XML1) . '</a:To>'
            . $this->securityHeader()
            . '</s:Header>'
            . '<s:Body>' . $body_xml . '</s:Body>'
            . '</s:Envelope>';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $envelope);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, Array(
            'Content-Type: application/soap+xml; charset=utf-8; action="' . $action . '"',
        ));

        $out = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            return Array('ok' => false, 'error' => $err, 'body' => '');
        }

        return Array('ok' => true, 'error' => '', 'body' => $out);
    }
}

function bridge_log($msg)
{
    echo date('Y-m-d\TH:i:sP'), ' ', $msg, "\n";
}

/**
 * Dispara a gravação no bc-server. Protocolo do trigger_server:
 * uma escrita só, "<camera_id> <descrição>" (ver server/trigger_server.cpp).
 * Vai direto no socket unix em vez de passar pelo endpoint HTTP /media/trigger
 * porque roda no próprio servidor — sem round-trip HTTP e sem precisar guardar
 * credencial de painel em lugar nenhum.
 */
function fire_trigger($device_id, $description)
{
    $sock = @fsockopen('unix://' . TRIGGER_SOCKET, -1, $errno, $errstr, 5);
    if (!$sock) {
        bridge_log("ERRO: nao consegui abrir " . TRIGGER_SOCKET . " ({$errstr}) — o bc-server esta rodando?");
        return false;
    }

    fwrite($sock, $device_id . ' ' . $description);
    $reply = trim((string)fgets($sock));
    fclose($sock);

    bridge_log("gatilho disparado (device {$device_id}): {$reply}");

    // O trigger_server responde "404 Processor Not Found" quando a câmera não
    // está no modo de agenda 'T' — é o engano mais provável na configuração,
    // então vale dizer isso em vez de só repassar o código.
    if (strpos($reply, '404') === 0) {
        bridge_log("  ^ a camera precisa estar com a agenda em 'Trigger' (T) pra aceitar gatilho");
    }

    return (strpos($reply, '200') === 0);
}

// ---------------------------------------------------------------------------

$device_id = isset($argv[1]) ? (int)$argv[1] : 0;
$once      = in_array('--once', $argv);
$verbose   = in_array('--verbose', $argv);

if ($device_id <= 0) {
    fwrite(STDERR, "uso: php onvif-event-bridge.php <device_id> [--once] [--verbose]\n");
    exit(1);
}

$rows = data::query("SELECT * FROM Devices WHERE id = " . $device_id);
if (empty($rows)) {
    fwrite(STDERR, "device {$device_id} nao encontrado\n");
    exit(1);
}
$dev = $rows[0];

// Devices.device tem o formato "host|porta|caminho" (ver lib/bc-core.cpp:113).
$parts = explode('|', $dev['device']);
$host  = $parts[0];
$onvif_port = !empty($dev['onvif_port']) ? $dev['onvif_port'] : 80;

$username = isset($dev['rtsp_username']) ? $dev['rtsp_username'] : '';
$password = isset($dev['rtsp_password']) ? $dev['rtsp_password'] : '';

bridge_log("device {$device_id} ({$dev['device_name']}) -> ONVIF em {$host}:{$onvif_port}");

if (strpos($dev['schedule'], 'T') === false) {
    bridge_log("AVISO: a agenda desta camera nao tem nenhuma hora em 'Trigger' (T) — o gatilho vai ser recusado");
}

$cli = new OnvifEventClient();
$cli->setIPAddress($host . ':' . $onvif_port);
$cli->setUsername($username);
$cli->setPassword($password);

// initialize() sincroniza o relógio (deltatime) — sem isso o digest de
// autenticação é recusado por câmeras que checam a janela de tempo — e traz as
// capabilities, de onde sai o endereço do serviço de eventos.
try {
    $init = $cli->initialize();
} catch (Exception $e) {
    bridge_log("ERRO ao inicializar ONVIF: " . $e->getMessage());
    exit(1);
}

if (empty($init['datetime'])) {
    bridge_log("ERRO: a camera nao respondeu ONVIF em {$host}:{$onvif_port}");
    exit(1);
}
if (isset($init['profiles']['auth']) && $init['profiles']['auth'] === false) {
    bridge_log("ERRO: a camera recusou usuario/senha");
    exit(1);
}

$caps = $cli->getCapabilities();
$event_url = isset($caps['Events']['XAddr']) ? $caps['Events']['XAddr'] : '';
if ($event_url === '') {
    bridge_log("ERRO: esta camera nao expoe servico de eventos ONVIF");
    exit(1);
}
bridge_log("servico de eventos: {$event_url}");

// --- assinatura --------------------------------------------------------------

$sub = $cli->soapRaw(
    $event_url,
    '<CreatePullPointSubscription xmlns="http://www.onvif.org/ver10/events/wsdl">'
    . '<InitialTerminationTime>PT600S</InitialTerminationTime>'
    . '</CreatePullPointSubscription>',
    'http://docs.oasis-open.org/wsn/bw-2/NotificationProducer/CreatePullPointSubscriptionRequest',
    20
);

if (!$sub['ok']) {
    bridge_log("ERRO na assinatura: " . $sub['error']);
    exit(1);
}

// Casa pelo nome local da tag: o prefixo de namespace varia por fabricante.
if (!preg_match('/<[^>]*Address[^>]*>([^<]+)</i', $sub['body'], $m)) {
    bridge_log("ERRO: a camera nao devolveu endereco de assinatura");
    if ($verbose) { bridge_log(substr($sub['body'], 0, 800)); }
    exit(1);
}

$pull_url = trim($m[1]);
bridge_log("assinado, endereco de coleta: {$pull_url}");

// --- laço de coleta ----------------------------------------------------------

$sub_started = time();

while (true) {
    $pull = $cli->soapRaw(
        $pull_url,
        '<PullMessages xmlns="http://www.onvif.org/ver10/events/wsdl">'
        . '<Timeout>PT10S</Timeout>'
        . '<MessageLimit>20</MessageLimit>'
        . '</PullMessages>',
        'http://www.onvif.org/ver10/events/wsdl/PullPointSubscription/PullMessagesRequest',
        40
    );

    if (!$pull['ok']) {
        bridge_log("erro na coleta: " . $pull['error'] . " — refazendo assinatura em 5s");
        sleep(5);
        // Assinatura pode ter expirado ou a câmera reiniciado: sai e deixa quem
        // supervisiona reiniciar, em vez de insistir num endereço morto.
        exit(2);
    }

    // Cada NotificationMessage traz um Topic e itens de dados. O que interessa:
    //   - Topic com "Motion" (RuleEngine/CellMotionDetector/Motion,
    //     VideoSource/MotionAlarm, etc — varia por fabricante)
    //   - SimpleItem com Value "true" (movimento COMEÇOU; "false" é o fim)
    if (preg_match_all('/<[^>]*NotificationMessage[^>]*>(.*?)<\/[^>]*NotificationMessage>/is', $pull['body'], $msgs)) {
        foreach ($msgs[1] as $msg_xml) {
            $topic = '';
            if (preg_match('/<[^>]*Topic[^>]*>([^<]+)</i', $msg_xml, $tm)) {
                $topic = trim($tm[1]);
            }

            $is_motion_topic = (stripos($topic, 'Motion') !== false);

            $state = null;
            if (preg_match_all('/<[^>]*SimpleItem[^>]*Name="([^"]+)"[^>]*Value="([^"]*)"/i', $msg_xml, $items, PREG_SET_ORDER)) {
                foreach ($items as $item) {
                    if (preg_match('/(IsMotion|State|IsDetected|Motion)/i', $item[1])) {
                        $state = strtolower($item[2]);
                    }
                }
            }

            if ($verbose) {
                bridge_log("evento: topic='{$topic}' state=" . var_export($state, true));
            }

            if ($is_motion_topic && $state === 'true') {
                bridge_log("MOVIMENTO detectado pela camera -> disparando gravacao");
                fire_trigger($device_id, 'onvif_motion');
            }
        }
    }

    if ($once) { break; }

    // Renova a assinatura antes de expirar (pedimos PT600S).
    if (time() - $sub_started > 480) {
        bridge_log("renovando assinatura");
        exit(3); // sai limpo; o supervisor reinicia e assina de novo
    }
}

bridge_log("fim");
