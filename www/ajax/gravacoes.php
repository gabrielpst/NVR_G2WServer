<?php

/*
 * G2W Monitor — tela "Gravações" de quem assiste (2026-11): /gravacoes
 *
 * Substitui a /playback antiga para o usuário comum. Lista as câmeras que o
 * usuário pode ver e, por câmera, toca o dia gravado numa linha do tempo e
 * baixa um trecho de até 20 minutos pela rota /media/trecho.
 *
 *   GET /gravacoes                       a tela (lista e câmera, na mesma página)
 *   GET /gravacoes/dia?camera=&dia=      (JSON) trechos gravados do dia
 *   GET /gravacoes/midia?id=             o arquivo de um trecho, para o tocador
 *
 * Tudo confere no servidor: direito de ver gravações ("Gravações" no cadastro
 * do usuário, ou administrador) e câmera liberada. Nada aqui grava no banco.
 */
class gravacoes extends Controller
{
    public $uri;

    public function __construct()
    {
        parent::__construct();
        G2WGestao::aplicarFuso();
        // 'viewer' e não 'backup': quem não tem a permissão recebe uma tela
        // que explica, em vez de um redirecionamento sem motivo aparente.
        $this->chAccess('viewer');
    }

    private function podeGravacoes()
    {
        $u = $this->user->info;
        return intval($u['access_setup']) || intval($u['access_backup']);
    }

    private function cameras()
    {
        $res = array();
        foreach (G2WGestao::q("SELECT id, device_name FROM Devices ORDER BY device_name") as $c) {
            $id = intval($c['id']);
            if (!$this->user->camPermission($id)) continue;
            $ts = @filemtime(G2WGestao::arquivoMiniatura($id));
            $res[] = array('id' => $id, 'nome' => (string)$c['device_name'], 'foto_ts' => $ts ?: null);
        }
        return $res;
    }

    public function getData()
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self' blob:; connect-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

        $pode = $this->podeGravacoes();
        $v = new View('gravacoes');
        $v->pode = $pode;
        $v->dados = array(
            'pode' => $pode,
            'cameras' => $pode ? $this->cameras() : array(),
            'hoje' => date('Y-m-d'),
            'trecho_max' => 1200,
        );
        $v->usuario = (string)$this->user->info['username'];
        $v->admin = (bool)intval($this->user->info['access_setup']);
        return $v->render();
    }

    public function postData()
    {
        $this->json(array('ok' => false, 'erro' => 'Esta rota só aceita leitura (GET).'), 405);
    }

    private function exigir()
    {
        if (!$this->podeGravacoes()) {
            $this->json(array('ok' => false, 'erro' => 'Seu usuário não tem acesso às gravações.'), 403);
        }
    }

    public function getDia()
    {
        $this->exigir();
        session_write_close();
        $cam = intval(filter_input(INPUT_GET, 'camera', FILTER_VALIDATE_INT));
        $dia = (string)filter_input(INPUT_GET, 'dia', FILTER_UNSAFE_RAW);
        if ($cam <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
            $this->json(array('ok' => false, 'erro' => 'Escolha a câmera e o dia.'), 422);
        }
        // câmera inexistente e câmera bloqueada dão a mesma resposta
        if (!G2WGestao::q("SELECT id FROM Devices WHERE id=?", 'i', array($cam)) || !$this->user->camPermission($cam)) {
            $this->json(array('ok' => false, 'erro' => 'Câmera não liberada para o seu usuário.'), 403);
        }
        try {
            $r = G2WGestao::gravacoesDoDia($cam, $dia);
        } catch (InvalidArgumentException $e) {
            $this->json(array('ok' => false, 'erro' => $e->getMessage()), 422);
        }
        $trechos = array();
        foreach ($r['trechos'] as $t) {
            $trechos[] = array('id' => $t['id'], 'inicio' => $t['inicio'], 'fim' => $t['fim'], 'aberto' => $t['aberto']);
        }
        // archive=1 é gravação que a limpeza do servidor já tirou do disco
        $pri = G2WGestao::q("SELECT MIN(start) AS t FROM Media WHERE device_id=? AND filepath<>'' AND archive=0", 'i', array($cam));
        $this->json(array('ok' => true, 'inicio_dia' => $r['inicio_dia'], 'fim_dia' => $r['fim_dia'], 'trechos' => $trechos,
            'agora' => time(), 'hoje' => date('Y-m-d'),
            'primeiro_dia' => (!empty($pri[0]['t'])) ? date('Y-m-d', intval($pri[0]['t'])) : null));
    }

    public function getMidia()
    {
        $this->exigir();
        $id = intval(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT));
        $r = ($id > 0) ? G2WGestao::q("SELECT filepath, device_id FROM Media WHERE id=? AND filepath<>'' AND end>0", 'i', array($id)) : array();
        // gravação inexistente e gravação de câmera bloqueada: a mesma resposta
        if (!$r || !$this->user->camPermission(intval($r[0]['device_id']))) {
            $this->json(array('ok' => false, 'erro' => 'Gravação não encontrada.'), 404);
        }
        $real = G2WGestao::arquivoDeGravacao($r[0]['filepath']);
        if ($real === false) $this->json(array('ok' => false, 'erro' => 'O arquivo desta gravação não está mais no disco.'), 404);
        G2WGestao::enviarVideo($real);
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
