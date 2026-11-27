<?php

/*
 * G2W Monitor — GET /media/formatos (2026-11, "Formato da imagem").
 *
 * Diz ao aplicativo como mostrar a imagem de cada câmera que o usuário pode
 * ver: o formato escolhido no painel e, quando ele é fixo, a proporção em que
 * a imagem deve ser esticada. Contrato em docs/api-formato-imagem.md.
 * Com cookie de sessão ou com Basic (como /media/request). Não devolve
 * endereço, usuário nem senha de câmera.
 */
class mediaFormatos extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->chAccess('basic');
    }

    public function getData()
    {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $u = $this->user->info;
        if (!intval($u['access_setup']) && !intval($u['access_web']) && !intval($u['access_remote'])) {
            http_response_code(403);
            die(json_encode(array('ok' => false, 'erro' => 'Usuário desativado.')));
        }

        $res = array();
        foreach (G2WGestao::q("SELECT id FROM Devices WHERE disabled=0 ORDER BY id") as $c) {
            $id = intval($c['id']);
            if (!$this->user->camPermission($id)) continue;
            $f = G2WGestao::formatoDe($id);
            $res[] = array('id' => $id, 'formato' => $f, 'proporcao' => G2WGestao::proporcaoDoFormato($f));
        }
        die(json_encode(array('ok' => true, 'cameras' => $res)));
    }

    public function postData()
    {
        http_response_code(405);
        die();
    }
}
