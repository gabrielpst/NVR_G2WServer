<?php

/*
 * G2W Monitor — tela "Ao vivo" (2026-11): /aovivo
 *
 * Substitui a /liveview antiga (MooTools + hls.js de CDN, que não tocava: o
 * MooTools troca Function.prototype.bind e quebra o hls.js 1.x). Para qualquer
 * usuário com acesso ao site; cada um só enxerga as câmeras que pode ver.
 *
 *   GET /aovivo          a tela
 *   GET /aovivo/estado   (JSON) câmeras permitidas + hora da última foto
 *
 * O vídeo em si continua vindo das rotas que já existiam: o token por
 * /media/hls?tokenOnly=1, a playlist e os segmentos por /hls/…, a foto por
 * /media/request?mode=livesnapshot. Nada aqui grava no banco.
 */
class aovivo extends Controller
{
    public $uri;

    public function __construct()
    {
        parent::__construct();
        G2WGestao::aplicarFuso();
        $this->chAccess('viewer');
    }

    /* Câmeras ligadas que este usuário pode ver, em ordem de nome. */
    private function cameras()
    {
        $res = array();
        foreach (G2WGestao::q("SELECT id, device_name FROM Devices WHERE disabled=0 ORDER BY device_name") as $c) {
            $id = intval($c['id']);
            if (!$this->user->camPermission($id)) continue;
            $ts = @filemtime(G2WGestao::arquivoMiniatura($id));
            $res[] = array(
                'id' => $id,
                'nome' => (string)$c['device_name'],
                'foto_ts' => $ts ?: null,
                'foto_hora' => $ts ? date('H:i', $ts) : null,
            );
        }
        return $res;
    }

    public function getData()
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        // blob: é o que o tocador usa para entregar o vídeo ao navegador (MSE) e para o seu worker
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self' blob:; worker-src 'self' blob:; connect-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

        $v = new View('aovivo');
        $v->dados = array(
            'cameras' => $this->cameras(),
            // só para separar a preferência de quem divide o mesmo navegador
            'usuario_id' => intval($this->user->info['id']),
        );
        $v->usuario = (string)$this->user->info['username'];
        $v->admin = (bool)intval($this->user->info['access_setup']);
        $v->gravacoes = $v->admin || intval($this->user->info['access_backup']);
        $v->agora = time();
        return $v->render();
    }

    public function getEstado()
    {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        die(json_encode(array('ok' => true, 'cameras' => $this->cameras()), JSON_UNESCAPED_UNICODE));
    }
}
