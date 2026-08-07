<?php

/**
 * Find RTMP streams (G2W Monitor).
 *
 * Câmeras que só falam RTMP empurram o stream pro relay (MediaMTX), que o
 * reexpõe como RTSP pro bc-server gravar. O problema prático: várias câmeras
 * montam o nome do path sozinhas — a Intelbras testada, por exemplo, publica em
 * "live/liveStream_<serial>_0_0" — e não dá pra adivinhar isso na hora de
 * cadastrar a câmera no painel. Antes disso aqui, só descobrindo o nome no log
 * do container do relay, na mão.
 *
 * Esta tela consulta a API do MediaMTX, lista o que está publicando agora, e
 * cadastra o selecionado como câmera IP-RTSP normal apontando pro relay — o
 * bc-server nem sabe que existe RTMP no meio do caminho.
 */
class findrtmp extends Controller {

    public function __construct(){
        parent::__construct();
        $this->chAccess('admin');
    }

    public function getData()
    {
        $this->setView('ajax.findrtmp');
    }

    /**
     * POST /findrtmp — lista os paths conhecidos pelo relay.
     */
    public function postData()
    {
        $json = $this->curlReq(VAR_MEDIAMTX_API . '/v3/paths/list');

        if (empty($json)) {
            data::responseJSON('F', FIND_RTMP_NO_RELAY, Array());
        }

        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            data::responseJSON('F', FIND_RTMP_BAD_RESPONSE, Array());
        }

        $existing = $this->existingRelayPaths();
        $res = Array();

        foreach ($data['items'] as $item) {
            if (empty($item['name'])) { continue; }

            // Normaliza pro mesmo formato que o cadastro de câmera usa
            // (ipCamera::prepareData() prefixa "/" no caminho RTSP).
            $path = '/' . ltrim($item['name'], '/');

            $tracks = '';
            if (!empty($item['tracks']) && is_array($item['tracks'])) {
                $tracks = implode(', ', $item['tracks']);
            }

            $res[] = Array(
                'name'   => $item['name'],
                'path'   => $path,
                // "ready" = tem alguém publicando de verdade agora. Um path pode
                // aparecer na lista sem estar recebendo nada.
                'ready'  => (!empty($item['ready'])) ? 1 : 0,
                'source' => (isset($item['source']['type'])) ? $item['source']['type'] : '',
                'tracks' => $tracks,
                'exists' => (in_array($path, $existing)) ? 1 : 0,
            );
        }

        data::responseJSON('OK', FIND_RTMP_FOUND, $res);
    }

    /**
     * POST /findrtmp/add — cadastra os streams marcados como câmeras.
     */
    public function postAdd()
    {
        $added  = Array();
        $failed = Array();

        $paths    = Inp::gp_arr('path');
        $camnames = Inp::gp_arr('camname');

        foreach (Inp::gp_arr('streams') as $key => $val) {
            $path = (isset($paths[$key])) ? trim($paths[$key]) : '';
            if ($path === '') { continue; }

            $name = (isset($camnames[$key]) && trim($camnames[$key]) !== '')
                ? trim($camnames[$key])
                : trim($path, '/');

            // ipCamera::prepareData() lê $_POST direto em alguns pontos (não só
            // o argumento que recebe), então é preciso sobrescrever o
            // superglobal mesmo — é exatamente o que discoverCameras::postAdd()
            // faz pelo mesmo motivo.
            $_POST = Array(
                'mode'      => 'addip',
                'models'    => 'Generic',
                'camName'   => $name,
                'ipAddr'    => VAR_MEDIAMTX_RTSP_HOST,
                'user'      => '',
                'pass'      => '',
                'protocol'  => 'IP-RTSP',
                'rtsp'      => $path,
                'port'      => VAR_MEDIAMTX_RTSP_PORT,
                'substream' => '',
                'prefertcp' => '0',
                'mjpeg'     => '',
                'portMjpeg' => 80,
            );

            $result = ipCamera::create($_POST);

            if (!empty($result[0])) {
                $added[] = $path;
            } else {
                $failed[] = Array(
                    'path' => $path,
                    'msg'  => (isset($result[1]) && $result[1]) ? $result[1] : '',
                );
            }
        }

        $ok = (count($added) > 0);

        data::responseJSON(
            $ok,
            ($ok) ? FIND_RTMP_ADDED : FIND_RTMP_NONE_ADDED,
            Array('added' => $added, 'failed' => $failed)
        );
    }

    /**
     * Caminhos de relay já cadastrados como câmera, pra marcar na lista o que
     * não precisa ser adicionado de novo. O campo Devices.device é gravado no
     * formato "host|porta|caminho" (ver ipCamera::prepareData()).
     */
    protected function existingRelayPaths()
    {
        $paths = Array();
        $prefix = VAR_MEDIAMTX_RTSP_HOST . '|' . VAR_MEDIAMTX_RTSP_PORT . '|';

        $devices = data::query("SELECT device FROM Devices");
        if (!$devices) { return $paths; }

        foreach ($devices as $device) {
            if (empty($device['device'])) { continue; }
            if (strpos($device['device'], $prefix) !== 0) { continue; }
            $paths[] = substr($device['device'], strlen($prefix));
        }

        return $paths;
    }

    /**
     * GET HTTP simples. Mesma implementação usada em discoverCameras.php (é um
     * método protegido lá, não dá pra reaproveitar de fora).
     */
    protected function curlReq($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $output = curl_exec($ch);

        curl_close($ch);

        return $output;
    }
}
