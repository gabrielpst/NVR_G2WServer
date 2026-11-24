<?php

/*
 * G2W Monitor — localização das câmeras para o mapa (2026-11): /mapa
 *
 *   GET /mapa/cameras   (JSON) câmeras que o usuário logado pode ver, com
 *                       nome, coordenada e endereço. É o que o aplicativo
 *                       consome. Contrato em docs/api-mapa-cameras.md.
 *   GET /mapa           leva à tela certa de quem abriu no navegador
 *
 * Localização de câmera é dado sensível: só sai para quem tem a câmera
 * liberada, e a conferência é feita aqui, câmera por câmera. Não há rota
 * "por id" nem parâmetro que escolha câmera: o que não é do usuário não
 * entra na resposta.
 */
class mapa extends Controller
{
    public $uri;

    public function getData()
    {
        $this->chAccess('viewer');
        header('Location: '.(intval($this->user->info['access_setup']) ? '/gestao/mapa' : '/aovivo'));
        exit;
    }

    public function postData()
    {
        $this->json(array('ok' => false, 'erro' => 'Esta rota só aceita leitura (GET).'), 405);
    }

    public function getCameras()
    {
        // Sem sessão a resposta é um JSON com 401 — o redirecionamento para
        // /login que o chAccess() faz serve a páginas, não ao aplicativo.
        if (empty($_SESSION['id'])) {
            $this->json(array('ok' => false, 'erro' => 'Sessão ausente ou expirada. Entre de novo.'), 401);
        }
        $this->chAccess('viewer');
        session_write_close();

        $so_localizadas = (filter_input(INPUT_GET, 'localizadas') === '1');
        $locais = G2WGestao::locais();
        $cameras = array();
        foreach (G2WGestao::q("SELECT id, device_name FROM Devices WHERE disabled=0 ORDER BY device_name") as $c) {
            $id = intval($c['id']);
            if (!$this->user->camPermission($id)) continue;
            $l = isset($locais[$id]) ? $locais[$id] : null;
            if (!$l && $so_localizadas) continue;
            $cameras[] = array(
                'id' => $id,
                'nome' => (string)$c['device_name'],
                'localizada' => (bool)$l,
                'latitude' => $l ? $l['latitude'] : null,
                'longitude' => $l ? $l['longitude'] : null,
                'endereco' => $l ? $l['endereco'] : '',
            );
        }
        $this->json(array('ok' => true, 'cameras' => $cameras));
    }

    private function json($dados, $codigo = 200)
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        die(json_encode($dados, JSON_UNESCAPED_UNICODE));
    }
}
