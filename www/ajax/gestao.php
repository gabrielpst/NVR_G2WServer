<?php

/*
 * G2W Monitor — painel de gestão (2026-11): /gestao
 *
 * Telas simples em português por cima do mesmo banco e da mesma sessão do
 * painel original (que continua inteiro em /monitor, "modo avançado").
 *
 *   GET  /gestao                    visão geral
 *   GET  /gestao/cameras            câmeras + armazenamento
 *   GET  /gestao/usuarios           usuários
 *   GET  /gestao/gravacoes          gravações por câmera e dia
 *   GET  /gestao/trechos            (JSON) trechos gravados de um dia
 *   GET  /gestao/midia              arquivo de gravação (tocar/baixar)
 *   GET  /gestao/miniatura          último quadro da câmera
 *   POST /gestao/<acao>             (JSON) ações — ver métodos post*
 *
 * Toda rota exige administrador, conferido aqui no servidor. Todo POST exige
 * corpo JSON + cabeçalho X-G2W-CSRF com o token da sessão.
 */
class gestao extends Controller
{
    private $pedidoApi = false;
    public $uri;

    public function start($uri = Array())
    {
        $this->autorizar($uri);
        return parent::start($uri);
    }

    private function autorizar($uri)
    {
        G2WGestao::aplicarFuso();
        $post = (Inp::method() == 'POST');
        $this->pedidoApi = $post || in_array($uri->two, array('trechos', 'midia', 'miniatura'), true);

        // Em chamada de API a recusa é um JSON com 401/403 — não o
        // redirecionamento pra /login que o chAccess() faz nas páginas.
        if ($this->pedidoApi) {
            $id = empty($_SESSION['id']) ? 0 : intval($_SESSION['id']);
            if (!$id) $this->falha(401, 'Sua sessão expirou. Entre novamente.');
            $u = G2WGestao::q("SELECT access_setup FROM Users WHERE id=?", 'i', array($id));
            if (!$u || !intval($u[0]['access_setup'])) $this->falha(403, 'Só administradores podem fazer isso.');
        }
        $this->chAccess('admin');

        if ($post) {
            $tipo = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';
            if (strpos($tipo, 'application/json') !== 0 || !G2WGestao::csrfConfere()) {
                $this->falha(403, 'Pedido recusado por segurança. Recarregue a página e tente de novo.');
            }
        }
    }

    // ------------------------------------------------------------ páginas

    public function getData()
    {
        $cams = G2WGestao::cameras();
        $bytes_dia = array_sum(array_column($cams, 'bytes_24h'));
        $discos = G2WGestao::discos($bytes_dia);
        $certs = G2WGestao::certificados();
        $motor = G2WGestao::motor();
        $us = G2WGestao::usuarios();

        $resumo = array('total' => count($us['lista']), 'trocar_senha' => 0, 'desativados' => 0, 'online' => 0);
        foreach ($us['lista'] as $u) {
            if ($u['trocar_senha']) $resumo['trocar_senha']++;
            if (!$u['ativo']) $resumo['desativados']++;
            if ($u['online']) $resumo['online']++;
        }

        return $this->pagina('visao', 'Visão geral', array(
            'cams' => $cams, 'discos' => $discos, 'certs' => $certs, 'motor' => $motor,
            'resumo' => $resumo, 'bytes_dia' => $bytes_dia,
            'avisos' => $this->avisos($cams, $discos, $certs, $motor, $resumo),
        ));
    }

    private function avisos($cams, $discos, $certs, $motor, $resumo)
    {
        $a = array();
        if (!$motor['rodando']) {
            $a[] = array('erro', 'O motor de gravação está parado', 'Nenhuma câmera grava nem aparece ao vivo enquanto ele não voltar.', '');
        } elseif ($motor['mensagem'] !== '') {
            $a[] = array('erro', 'O motor de gravação avisou um problema', $motor['mensagem'], '');
        }
        foreach ($cams as $c) {
            if ($c['estado']['nivel'] === 'erro' || $c['estado']['nivel'] === 'alerta') {
                $a[] = array($c['estado']['nivel'], $c['nome'].': '.mb_strtolower($c['estado']['rotulo']), $c['estado']['detalhe'], '/gestao/cameras');
            }
        }
        foreach ($discos as $d) {
            if (!$d['ok']) {
                $a[] = array('erro', 'Disco de gravação inacessível', 'Não foi possível ler '.$d['caminho'].'.', '/gestao/cameras#armazenamento');
            } elseif ($d['uso_pct'] >= 98) {
                $a[] = array('erro', 'Disco de gravação quase cheio ('.number_format($d['uso_pct'], 0).'%)', 'A limpeza automática não está dando conta. Reduza os dias guardados por câmera.', '/gestao/cameras#armazenamento');
            }
        }
        foreach ($certs as $c) {
            if ($c['autoassinado']) continue;
            if ($c['dias'] < 0) {
                $a[] = array('erro', 'Certificado de segurança vencido', $c['rotulo'].': venceu há '.abs($c['dias']).' dias. O aplicativo deixa de conectar.', '');
            } elseif ($c['dias'] <= G2WGestao::CERT_DIAS_ALERTA) {
                $a[] = array('alerta', 'Certificado de segurança vence em '.$c['dias'].' dias', $c['rotulo'].': renove antes de '.date('d/m/Y', $c['vence_em']).' para o aplicativo não parar.', '');
            }
        }
        if ($resumo['trocar_senha'] > 0) {
            $a[] = array('info', $resumo['trocar_senha'].' de '.$resumo['total'].' usuários ainda não definiram a própria senha',
                'Eles só entram depois de criar uma senha nova no primeiro acesso.', '/gestao/usuarios#filtro=trocar');
        }
        return $a;
    }

    public function getCameras()
    {
        $cams = G2WGestao::cameras();
        return $this->pagina('cameras', 'Câmeras', array(
            'cams' => $cams,
            'discos' => G2WGestao::discos(array_sum(array_column($cams, 'bytes_24h'))),
        ));
    }

    public function getUsuarios()
    {
        $us = G2WGestao::usuarios();
        $desde = null;
        if ($us['tem_acessos']) {
            $r = G2WGestao::q("SELECT UNIX_TIMESTAMP(CREATE_TIME) AS t FROM information_schema.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='G2WPainelAcessos'");
            if ($r && $r[0]['t']) $desde = intval($r[0]['t']);
        }
        // hora já formatada no fuso do servidor (o navegador pode estar em outro)
        foreach ($us['lista'] as $k => $u) {
            $us['lista'][$k]['ultimo_acesso_txt'] = $u['ultimo_acesso']
                ? G2WGestao::quando($u['ultimo_acesso']).($u['pelo_app'] ? ' (aplicativo)' : ' (site)') : '';
        }
        $cams = array();
        foreach (G2WGestao::q("SELECT id, device_name FROM Devices ORDER BY device_name") as $c) {
            $cams[] = array('id' => intval($c['id']), 'nome' => (string)$c['device_name']);
        }
        return $this->pagina('usuarios', 'Usuários', array(
            'dados' => array(
                'usuarios' => $us['lista'], 'cameras' => $cams, 'eu' => intval($this->user->info['id']),
                'acessos_desde' => $desde, 'senha_min' => G2WGestao::SENHA_MIN, 'lote_max' => G2WGestao::LOTE_MAX,
            ),
        ));
    }

    public function getGravacoes()
    {
        $cams = array();
        foreach (G2WGestao::q("SELECT id, device_name FROM Devices ORDER BY device_name") as $c) {
            $cams[] = array('id' => intval($c['id']), 'nome' => (string)$c['device_name']);
        }
        return $this->pagina('gravacoes', 'Gravações', array('cams' => $cams, 'hoje' => date('Y-m-d')));
    }

    private function pagina($aba, $titulo, $dados)
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

        $v = new View('gestao.'.$aba);
        foreach ($dados as $k => $val) $v->$k = $val;
        $layout = new View('gestao.layout');
        $layout->conteudo = $v->render();
        $layout->aba = $aba;
        $layout->titulo = $titulo;
        $layout->csrf = G2WGestao::csrfToken();
        $layout->usuario = (string)$this->user->info['username'];
        $layout->agora = time();
        return $layout->render();
    }

    // ------------------------------------------------------- GET de dados

    public function getTrechos()
    {
        $cam = intval(filter_input(INPUT_GET, 'camera', FILTER_VALIDATE_INT));
        $dia = (string)filter_input(INPUT_GET, 'dia', FILTER_UNSAFE_RAW);
        if ($cam <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) $this->falha(400, 'Escolha a câmera e o dia.');
        try {
            $this->json(array('ok' => true) + G2WGestao::gravacoesDoDia($cam, $dia));
        } catch (InvalidArgumentException $e) {
            $this->falha(400, $e->getMessage());
        }
    }

    public function getMiniatura()
    {
        $id = intval(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT));
        $arq = '/var/lib/bluecherry/snapshots/'.$id.'.jpg';
        if ($id <= 0 || !is_file($arq)) {
            http_response_code(404);
            die();
        }
        session_write_close();
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store');
        header('Content-Length: '.filesize($arq));
        readfile($arq);
        die();
    }

    public function getMidia()
    {
        $id = intval(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT));
        $r = ($id > 0) ? G2WGestao::q("SELECT filepath, start, device_id FROM Media WHERE id=? AND archive=0", 'i', array($id)) : array();
        if (!$r) $this->falha(404, 'Gravação não encontrada.');

        // o caminho vem do banco; mesmo assim só serve arquivo que esteja de
        // fato dentro de uma pasta de gravação cadastrada
        $real = realpath($r[0]['filepath']);
        $dentro = false;
        foreach (G2WGestao::q("SELECT path FROM Storage") as $s) {
            $base = realpath($s['path']);
            if ($real && $base && strpos($real, rtrim($base, '/').'/') === 0) { $dentro = true; break; }
        }
        if (!$dentro || !is_file($real) || !is_readable($real)) $this->falha(404, 'O arquivo desta gravação não está mais no disco.');

        session_write_close();
        while (ob_get_level()) ob_end_clean();
        $tam = filesize($real);
        $ini = 0;
        $fim = $tam - 1;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') { $ini = max(0, $tam - intval($m[2])); }
            else { $ini = intval($m[1]); if ($m[2] !== '') $fim = min($fim, intval($m[2])); }
            if ($ini > $fim || $ini >= $tam) {
                http_response_code(416);
                header('Content-Range: bytes */'.$tam);
                die();
            }
            http_response_code(206);
            header('Content-Range: bytes '.$ini.'-'.$fim.'/'.$tam);
        }
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        header('Content-Type: '.($ext === 'mkv' ? 'video/x-matroska' : 'video/mp4'));
        header('Accept-Ranges: bytes');
        header('Content-Length: '.($fim - $ini + 1));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        if (filter_input(INPUT_GET, 'baixar')) {
            $nome = 'camera'.intval($r[0]['device_id']).'_'.date('Y-m-d_H-i-s', intval($r[0]['start'])).'.'.($ext ?: 'mp4');
            header('Content-Disposition: attachment; filename="'.$nome.'"');
        }
        $fp = fopen($real, 'rb');
        fseek($fp, $ini);
        $resta = $fim - $ini + 1;
        set_time_limit(0);
        while ($resta > 0 && !feof($fp) && !connection_aborted()) {
            $bloco = fread($fp, min(262144, $resta));
            if ($bloco === false) break;
            echo $bloco;
            flush();
            $resta -= strlen($bloco);
        }
        fclose($fp);
        die();
    }

    // -------------------------------------------------------------- ações

    public function postData()
    {
        $this->falha(404, 'Ação desconhecida.');
    }

    public function postCamera()
    {
        $e = $this->entrada();
        $id = $this->inteiro($e, 'id', 1, PHP_INT_MAX);
        $atual = G2WGestao::q("SELECT id, protocol, device, schedule FROM Devices WHERE id=?", 'i', array($id));
        if (!$atual) $this->falha(404, 'Câmera não encontrada.');

        $nome = $this->texto($e, 'nome', 1, 100, 'O nome da câmera');
        $dup = G2WGestao::q("SELECT id FROM Devices WHERE device_name=? AND id<>?", 'si', array($nome, $id));
        if ($dup) $this->falha(422, 'Já existe outra câmera com esse nome.');

        $ret = isset($e['retencao_dias']) ? $e['retencao_dias'] : null;
        if ($ret === '' ) $ret = null;
        if ($ret !== null) {
            if (!is_numeric($ret) || intval($ret) != $ret || $ret < 1 || $ret > 3650) $this->falha(422, 'Dias guardados: informe um número de 1 a 3650, ou deixe em branco.');
            $ret = intval($ret);
        }

        $grav = isset($e['gravacao']) ? (string)$e['gravacao'] : 'manter';
        if (!in_array($grav, array('manter', 'padrao', 'sempre', 'nunca'), true)) $this->falha(422, 'Opção de gravação inválida.');

        $sets = array('device_name=?', 'max_age_days=?');
        $tipos = 'si';
        $vals = array($nome, $ret);
        if ($grav === 'padrao') {
            $sets[] = 'schedule_override_global=0';
        } elseif ($grav === 'sempre' || $grav === 'nunca') {
            $sets[] = 'schedule_override_global=1';
            $sets[] = 'schedule=?';
            $tipos .= 's';
            $vals[] = str_repeat($grav === 'sempre' ? 'C' : 'N', 168);
        }

        if (isset($e['fonte']) && $atual[0]['protocol'] === 'IP-RTSP'
            && trim((string)$e['fonte']) !== G2WGestao::fonteLegivel('IP-RTSP', $atual[0]['device'])) {
            $f = $this->fonteRtsp((string)$e['fonte']);
            $dup = G2WGestao::q("SELECT id FROM Devices WHERE device=? AND id<>?", 'si', array($f['device'], $id));
            if ($dup) $this->falha(422, 'Outra câmera já usa esse endereço.');
            $sets[] = 'device=?';
            $tipos .= 's';
            $vals[] = $f['device'];
            if ($f['usuario'] !== null) {
                $sets[] = 'rtsp_username=?';
                $sets[] = 'rtsp_password=?';
                $tipos .= 'ss';
                $vals[] = $f['usuario'];
                $vals[] = $f['senha'];
            }
        }

        $tipos .= 'i';
        $vals[] = $id;
        G2WGestao::q("UPDATE Devices SET ".implode(', ', $sets)." WHERE id=?", $tipos, $vals);
        $this->json(array('ok' => true, 'msg' => 'Câmera salva.'));
    }

    /* rtsp://[usuario:senha@]host[:porta]/caminho -> coluna Devices.device
     * ("host|porta|caminho"). Usuário/senha só mudam se vierem no endereço. */
    private function fonteRtsp($url)
    {
        $url = trim($url);
        if (strlen($url) > 400 || preg_match('/[\s|\x00-\x1f]/', $url)) $this->falha(422, 'Endereço da câmera inválido.');
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || strtolower($p['scheme']) !== 'rtsp' || empty($p['host'])) {
            $this->falha(422, 'O endereço da câmera deve começar com rtsp:// (exemplo: rtsp://192.168.1.50:554/stream1).');
        }
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', $p['host'])) $this->falha(422, 'Endereço da câmera inválido (host).');
        $porta = isset($p['port']) ? intval($p['port']) : 554;
        if ($porta < 1 || $porta > 65535) $this->falha(422, 'Porta da câmera inválida.');
        $caminho = (isset($p['path']) ? $p['path'] : '/').(isset($p['query']) ? '?'.$p['query'] : '');
        $device = $p['host'].'|'.$porta.'|'.$caminho;
        if (strlen($device) > 256) $this->falha(422, 'Endereço da câmera longo demais.');
        $usuario = isset($p['user']) ? rawurldecode($p['user']) : null;
        $senha = isset($p['pass']) ? rawurldecode($p['pass']) : '';
        if ($usuario !== null && (strlen($usuario) > 256 || strlen($senha) > 256)) $this->falha(422, 'Usuário ou senha da câmera longos demais.');
        return array('device' => $device, 'usuario' => $usuario, 'senha' => $senha);
    }

    public function postArmazenamento()
    {
        $e = $this->entrada();
        $pri = $this->inteiro($e, 'prioridade', 0, 1000);
        $max = $this->inteiro($e, 'limite_max', 50, 99, 'O limite para começar a apagar deve ficar entre 50% e 99%.');
        $min = $this->inteiro($e, 'limite_min', 40, 98, 'O limite para parar de apagar deve ficar entre 40% e 98%.');
        if ($min >= $max) $this->falha(422, 'O limite para parar de apagar precisa ser menor que o limite para começar.');
        $n = G2WGestao::q("UPDATE Storage SET max_thresh=?, min_thresh=? WHERE priority=?", 'iii', array($max, $min, $pri));
        if ($n === 0 && !G2WGestao::q("SELECT priority FROM Storage WHERE priority=?", 'i', array($pri))) $this->falha(404, 'Disco não encontrado.');
        $this->json(array('ok' => true, 'msg' => 'Limites do disco salvos.'));
    }

    public function postUsuario()
    {
        $e = $this->entrada();
        $eu = intval($this->user->info['id']);
        $id = empty($e['id']) ? 0 : $this->inteiro($e, 'id', 1, PHP_INT_MAX);
        $novo = ($id === 0);

        $login = isset($e['login']) ? trim((string)$e['login']) : '';
        if (($v = G2WGestao::validarLogin($login)) !== true) $this->falha(422, $v);
        $nome = $this->texto($e, 'nome', 0, 60, 'O nome');
        $email = $this->texto($e, 'email', 0, 60, 'O e-mail');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $this->falha(422, 'E-mail inválido.');
        $tel = $this->texto($e, 'telefone', 0, 15, 'O telefone');
        $obs = $this->texto($e, 'obs', 0, 255, 'A observação');
        $admin = !empty($e['admin']) ? 1 : 0;
        $site = !empty($e['site']) ? 1 : 0;
        $app = !empty($e['app']) ? 1 : 0;
        $grav = !empty($e['gravacoes']) ? 1 : 0;
        $trocar = !empty($e['trocar_senha']) ? 1 : 0;
        $senha = isset($e['senha']) ? (string)$e['senha'] : '';
        if ($novo || $senha !== '') {
            if (($v = G2WGestao::validarSenha($senha)) !== true) $this->falha(422, $v);
        }
        try {
            $bloqueio = G2WGestao::bloqueioDePermitidas(isset($e['cameras']) && is_array($e['cameras']) ? $e['cameras'] : array());
        } catch (InvalidArgumentException $ex) {
            $this->falha(422, $ex->getMessage());
        }

        if (G2WGestao::q("SELECT id FROM Users WHERE username=? AND id<>?", 'si', array($login, $id))) {
            $this->falha(422, 'Já existe um usuário com o login "'.$login.'".');
        }

        if ($novo) {
            $salt = data::getRandomString(4);
            G2WGestao::q("INSERT INTO Users (username, password, salt, name, email, phone, notes, access_setup, access_remote,
                access_web, access_backup, access_relay, access_device_list, access_ptz_list, change_password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, '', ?)", 'sssssssiiiisi',
                array($login, G2WGestao::hashSenha($senha, $salt), $salt, $nome, $email, $tel, $obs, $admin, $app, $site, $grav, $bloqueio, $trocar));
            $this->json(array('ok' => true, 'msg' => 'Usuário '.$login.' criado.'));
        }

        $atual = G2WGestao::q("SELECT id, access_setup FROM Users WHERE id=?", 'i', array($id));
        if (!$atual) $this->falha(404, 'Usuário não encontrado.');
        if ($id === $eu && !$admin) $this->falha(422, 'Você não pode tirar a sua própria permissão de administrador.');
        if (intval($atual[0]['access_setup']) && !$admin && G2WGestao::totalAdminsAtivos(array($id)) < 1) {
            $this->falha(422, 'Este é o único administrador. Promova outro antes de mudar este.');
        }

        G2WGestao::q("UPDATE Users SET username=?, name=?, email=?, phone=?, notes=?, access_setup=?, access_remote=?, access_web=?,
            access_backup=?, access_device_list=?, change_password=? WHERE id=?", 'sssssiiiisii',
            array($login, $nome, $email, $tel, $obs, $admin, $app, $site, $grav, $bloqueio, $trocar, $id));
        if ($senha !== '') {
            $salt = data::getRandomString(4);
            G2WGestao::q("UPDATE Users SET password=?, salt=? WHERE id=?", 'ssi', array(G2WGestao::hashSenha($senha, $salt), $salt, $id));
            if ($id !== $eu) G2WGestao::derrubarSessoes(array($id));
        }
        if (!$admin && !$site && !$app) G2WGestao::derrubarSessoes(array($id));
        $this->json(array('ok' => true, 'msg' => 'Usuário '.$login.' salvo.'));
    }

    public function postUsuariosLote()
    {
        $e = $this->entrada();
        $prefixo = isset($e['prefixo']) ? trim((string)$e['prefixo']) : '';
        if (!preg_match('/^[A-Za-z0-9._-]{1,30}$/', $prefixo)) $this->falha(422, 'Prefixo inválido: use letras sem acento, números, ponto, hífen ou sublinhado (até 30).');
        $de = $this->inteiro($e, 'de', 0, 99999, 'Informe o número inicial.');
        $ate = $this->inteiro($e, 'ate', 0, 99999, 'Informe o número final.');
        if ($ate < $de) $this->falha(422, 'O número final precisa ser maior ou igual ao inicial.');
        if ($ate - $de + 1 > G2WGestao::LOTE_MAX) $this->falha(422, 'Crie no máximo '.G2WGestao::LOTE_MAX.' usuários por vez.');
        $nome_base = $this->texto($e, 'nome_base', 0, 50, 'O nome');
        $senha = isset($e['senha']) ? (string)$e['senha'] : '';
        if (($v = G2WGestao::validarSenha($senha)) !== true) $this->falha(422, $v);
        $trocar = !empty($e['trocar_senha']) ? 1 : 0;
        $site = !empty($e['site']) ? 1 : 0;
        $app = !empty($e['app']) ? 1 : 0;
        $grav = !empty($e['gravacoes']) ? 1 : 0;
        if (!$site && !$app) $this->falha(422, 'Marque pelo menos um jeito de entrar: aplicativo ou site.');
        try {
            $bloqueio = G2WGestao::bloqueioDePermitidas(isset($e['cameras']) && is_array($e['cameras']) ? $e['cameras'] : array());
        } catch (InvalidArgumentException $ex) {
            $this->falha(422, $ex->getMessage());
        }

        $logins = array();
        for ($n = $de; $n <= $ate; $n++) $logins[$n] = $prefixo.$n;
        if (strlen(end($logins)) > 40) $this->falha(422, 'O login ficaria longo demais (máximo 40 caracteres).');

        $marcas = implode(',', array_fill(0, count($logins), '?'));
        $existentes = array_map('strtolower', array_column(
            G2WGestao::q("SELECT username FROM Users WHERE username IN ($marcas)", str_repeat('s', count($logins)), array_values($logins)), 'username'));

        $criados = 0;
        $pulados = array();
        data::query("START TRANSACTION", true);
        try {
            foreach ($logins as $n => $login) {
                if (in_array(strtolower($login), $existentes, true)) { $pulados[] = $login; continue; }
                $salt = data::getRandomString(4);
                G2WGestao::q("INSERT INTO Users (username, password, salt, name, email, phone, notes, access_setup, access_remote,
                    access_web, access_backup, access_relay, access_device_list, access_ptz_list, change_password)
                    VALUES (?, ?, ?, ?, '', '', '', 0, ?, ?, ?, 0, ?, '', ?)", 'ssssiiisi',
                    array($login, G2WGestao::hashSenha($senha, $salt), $salt, ($nome_base !== '' ? $nome_base.' '.$n : ''), $app, $site, $grav, $bloqueio, $trocar));
                $criados++;
            }
            data::query("COMMIT", true);
        } catch (Throwable $ex) {
            data::query("ROLLBACK", true);
            error_log('G2W gestao: criacao em lote: '.$ex->getMessage());
            $this->falha(500, 'Não foi possível criar os usuários. Nada foi gravado.');
        }
        $msg = $criados.' usuário(s) criado(s).';
        if ($pulados) $msg .= ' '.count($pulados).' já existia(m) e ficou(aram) como estava(m).';
        $this->json(array('ok' => true, 'msg' => $msg, 'criados' => $criados, 'pulados' => $pulados));
    }

    public function postUsuariosAcao()
    {
        $e = $this->entrada();
        $eu = intval($this->user->info['id']);
        $acao = isset($e['acao']) ? (string)$e['acao'] : '';
        $ids = array();
        if (isset($e['ids']) && is_array($e['ids'])) {
            foreach ($e['ids'] as $i) { if (is_int($i) && $i > 0) $ids[$i] = $i; }
        }
        $ids = array_values($ids);
        if (!$ids) $this->falha(422, 'Selecione pelo menos um usuário.');
        if (count($ids) > 2000) $this->falha(422, 'Usuários demais de uma vez.');

        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $tipos = str_repeat('i', count($ids));
        $alvo = G2WGestao::q("SELECT id, access_setup FROM Users WHERE id IN ($marcas)", $tipos, $ids);
        if (count($alvo) != count($ids)) $this->falha(409, 'A lista mudou. Recarregue a página e tente de novo.');
        $comuns = array();
        foreach ($alvo as $u) { if (!intval($u['access_setup'])) $comuns[] = intval($u['id']); }
        $pulados = count($ids) - count($comuns);
        $nota = $pulados ? ' '.$pulados.' administrador(es) não foi(ram) alterado(s) — edite um por um.' : '';

        switch ($acao) {
            case 'exigir_troca':
            case 'dispensar_troca':
                // vale também pra administrador, menos pra quem está operando
                $lista = array_values(array_diff($ids, array($eu)));
                if (!$lista) $this->falha(422, 'Essa ação não se aplica à sua própria conta.');
                G2WGestao::q("UPDATE Users SET change_password=? WHERE id IN (".implode(',', array_fill(0, count($lista), '?')).")",
                    'i'.str_repeat('i', count($lista)), array_merge(array($acao === 'exigir_troca' ? 1 : 0), $lista));
                $this->json(array('ok' => true, 'msg' => ($acao === 'exigir_troca'
                    ? count($lista).' usuário(s) vão ter que criar uma senha nova no próximo acesso.'
                    : count($lista).' usuário(s) liberado(s) da troca de senha.')));

            case 'ativar':
            case 'desativar':
                if (!$comuns) $this->falha(422, 'Nenhum usuário comum selecionado.'.$nota);
                $v = ($acao === 'ativar') ? 1 : 0;
                G2WGestao::q("UPDATE Users SET access_web=?, access_remote=? WHERE id IN (".implode(',', array_fill(0, count($comuns), '?')).")",
                    'ii'.str_repeat('i', count($comuns)), array_merge(array($v, $v), $comuns));
                if (!$v) G2WGestao::derrubarSessoes($comuns);
                $this->json(array('ok' => true, 'msg' => count($comuns).' usuário(s) '.($v ? 'ativado(s): voltam a entrar pelo aplicativo e pelo site.' : 'desativado(s): não entram mais.').$nota));

            case 'redefinir_senha':
                if (!$comuns) $this->falha(422, 'Nenhum usuário comum selecionado.'.$nota);
                $senha = isset($e['senha']) ? (string)$e['senha'] : '';
                if (($v = G2WGestao::validarSenha($senha)) !== true) $this->falha(422, $v);
                $trocar = !empty($e['trocar_senha']) ? 1 : 0;
                data::query("START TRANSACTION", true);
                try {
                    foreach ($comuns as $id) {
                        $salt = data::getRandomString(4);
                        G2WGestao::q("UPDATE Users SET password=?, salt=?, change_password=? WHERE id=?", 'ssii',
                            array(G2WGestao::hashSenha($senha, $salt), $salt, $trocar, $id));
                    }
                    data::query("COMMIT", true);
                } catch (Throwable $ex) {
                    data::query("ROLLBACK", true);
                    error_log('G2W gestao: redefinir senha: '.$ex->getMessage());
                    $this->falha(500, 'Não foi possível redefinir as senhas. Nada foi gravado.');
                }
                G2WGestao::derrubarSessoes($comuns);
                $this->json(array('ok' => true, 'msg' => 'Senha redefinida para '.count($comuns).' usuário(s).'.$nota));

            case 'definir_cameras':
                if (!$comuns) $this->falha(422, 'Nenhum usuário comum selecionado.'.$nota);
                try {
                    $bloqueio = G2WGestao::bloqueioDePermitidas(isset($e['cameras']) && is_array($e['cameras']) ? $e['cameras'] : array());
                } catch (InvalidArgumentException $ex) {
                    $this->falha(422, $ex->getMessage());
                }
                G2WGestao::q("UPDATE Users SET access_device_list=? WHERE id IN (".implode(',', array_fill(0, count($comuns), '?')).")",
                    's'.str_repeat('i', count($comuns)), array_merge(array($bloqueio), $comuns));
                $this->json(array('ok' => true, 'msg' => 'Câmeras atualizadas para '.count($comuns).' usuário(s).'.$nota));
        }
        $this->falha(422, 'Ação desconhecida.');
    }

    // --------------------------------------------------------- utilitários

    private function entrada()
    {
        $j = json_decode(file_get_contents('php://input'), true);
        if (!is_array($j)) $this->falha(400, 'Pedido inválido.');
        return $j;
    }

    private function inteiro($e, $campo, $min, $max, $msg = null)
    {
        $v = isset($e[$campo]) ? $e[$campo] : null;
        if (is_string($v) && preg_match('/^\d{1,9}$/', $v)) $v = intval($v);
        if (!is_int($v) || $v < $min || $v > $max) $this->falha(422, $msg ?: 'Valor inválido em "'.$campo.'".');
        return $v;
    }

    /* Texto livre curto. Recusa < > " ' \ e caracteres de controle: o painel
     * original imprime esses campos sem escapar, então eles não podem carregar
     * HTML nem quebrar atributo. */
    private function texto($e, $campo, $min, $max, $rotulo)
    {
        $v = isset($e[$campo]) ? $e[$campo] : '';
        if (!is_string($v)) $this->falha(422, $rotulo.' é inválido.');
        $v = trim($v);
        if (mb_strlen($v) < $min) $this->falha(422, $rotulo.' não pode ficar em branco.');
        if (mb_strlen($v) > $max) $this->falha(422, $rotulo.' pode ter no máximo '.$max.' caracteres.');
        if (!mb_check_encoding($v, 'UTF-8') || preg_match('/[<>"\'\\\\\x00-\x1f\x7f]/', $v)) {
            $this->falha(422, $rotulo.' tem caractere não permitido (< > aspas ou barra invertida).');
        }
        return $v;
    }

    private function json($dados, $codigo = 200)
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        die(json_encode($dados, JSON_UNESCAPED_UNICODE));
    }

    private function falha($codigo, $msg)
    {
        $this->json(array('ok' => false, 'erro' => $msg), $codigo);
    }
}
