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
 *   GET  /gestao/modelos            (JSON) modelos de um fabricante, com o caminho do vídeo
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
        try {
            $this->autorizar($uri);
            return parent::start($uri);
        } catch (Throwable $e) {
            // falha inesperada (em geral o banco): registra o detalhe no log do
            // servidor e responde algo que a tela sabe mostrar
            error_log('G2W gestao: '.get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
            if ($this->pedidoApi) $this->falha(500, 'O servidor não conseguiu concluir o pedido. Tente de novo em instantes.');
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            return '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.G2WMarca::h(G2WMarca::nome()).'</title>'
                .'<body style="font:16px system-ui,sans-serif;margin:40px auto;max-width:520px;padding:0 16px"><h1>O painel não conseguiu carregar</h1>'
                .'<p>Pode ser uma falha momentânea do banco de dados. <a href="">Tente de novo</a> ou abra o <a href="/monitor">modo avançado</a>.</p></body></html>';
        }
    }

    private function autorizar($uri)
    {
        G2WGestao::aplicarFuso();
        $post = (Inp::method() == 'POST');
        $this->pedidoApi = $post || in_array($uri->two, array('trechos', 'midia', 'miniatura', 'buscarEndereco', 'modelos'), true);

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
        return $this->pagina('visao', 'Visão geral', $this->dadosVisao());
    }

    /* Aba "Avisos": a lista completa que a visão geral só resume. */
    public function getAvisos()
    {
        return $this->pagina('avisos', 'Avisos', $this->dadosVisao());
    }

    private function dadosVisao()
    {
        $cams = G2WGestao::cameras();
        $bytes_dia = G2WGestao::bytesPorDia($cams);
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

        return array(
            'cams' => $cams, 'discos' => $discos, 'certs' => $certs, 'motor' => $motor,
            'resumo' => $resumo, 'bytes_dia' => $bytes_dia,
            'avisos' => $this->avisos($cams, $discos, $certs, $motor, $resumo),
        );
    }

    /* Avisos: [nível, título, detalhe, link do "Ver"]. Nível 'info' é só
     * informativo: aparece na tela de avisos, não conta como ponto de atenção. */

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
                $a[] = array($c['estado']['nivel'], $c['nome'].': '.mb_strtolower($c['estado']['rotulo']), $c['estado']['detalhe'], '/gestao/cameras#so='.intval($c['id']));
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
        $cams = $this->camerasComLocal();
        return $this->pagina('cameras', 'Câmeras', array(
            'cams' => $cams,
            'fabricantes' => G2WGestao::fabricantes(),
            'removidas' => G2WGestao::removidas(),
            'padrao_servidor' => G2WGestao::padraoDoServidor(),
            'discos' => G2WGestao::discos(G2WGestao::bytesPorDia($cams)),
        ), true);
    }

    /* Aba "Mapa": todas as câmeras com localização (o administrador vê todas). */
    public function getMapa()
    {
        return $this->pagina('mapa', 'Mapa', array('cams' => $this->camerasComLocal()), true);
    }

    private function camerasComLocal()
    {
        G2WGestao::limparLocaisOrfaos();
        $locais = G2WGestao::locais();
        $cams = G2WGestao::cameras();
        foreach ($cams as $k => $c) {
            $cams[$k]['local'] = isset($locais[$c['id']]) ? $locais[$c['id']] : null;
        }
        return $cams;
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
        $fora = G2WGestao::idsRemovidas();
        foreach (G2WGestao::q("SELECT id, device_name FROM Devices ORDER BY device_name") as $c) {
            if (in_array(intval($c['id']), $fora, true)) continue;
            $cams[] = array('id' => intval($c['id']), 'nome' => (string)$c['device_name']);
        }
        return $this->pagina('usuarios', 'Usuários', array(
            'sessao_unica' => G2WGestao::sessaoUnicaLigada(),
            'dados' => array(
                'usuarios' => $us['lista'], 'cameras' => $cams, 'eu' => intval($this->user->info['id']),
                'acessos_desde' => $desde, 'senha_min' => G2WGestao::SENHA_MIN, 'lote_max' => G2WGestao::LOTE_MAX,
            ),
        ));
    }

    public function getGravacoes()
    {
        $cams = array();
        $fora = G2WGestao::idsRemovidas();
        foreach (G2WGestao::q("SELECT id, device_name FROM Devices ORDER BY device_name") as $c) {
            if (in_array(intval($c['id']), $fora, true)) continue;
            $cams[] = array('id' => intval($c['id']), 'nome' => (string)$c['device_name']);
        }
        // câmeras removidas com gravação guardada: só o administrador chega nelas
        foreach (G2WGestao::removidas() as $r) {
            if ($r['apagando']) continue;
            $cams[] = array('id' => $r['id'], 'nome' => $r['nome'].' (removida)');
        }
        return $this->pagina('gravacoes', 'Gravações', array('cams' => $cams, 'hoje' => date('Y-m-d')));
    }

    // imagens do mapa: servidor público do OpenStreetMap (política em
    // https://operations.osmfoundation.org/policies/tiles/)
    const MAPA_TILES_ORIGEM = 'https://tile.openstreetmap.org';

    private function pagina($aba, $titulo, $dados, $comMapa = false)
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        // Nas telas com mapa o navegador busca as imagens direto no servidor
        // de mapas, que exige saber de que site vem o pedido: vai só a origem
        // (https://endereço:porta), nunca o caminho nem parâmetros. Nas outras
        // telas nada sai para fora.
        header('Referrer-Policy: '.($comMapa ? 'strict-origin-when-cross-origin' : 'same-origin'));
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:".($comMapa ? ' '.self::MAPA_TILES_ORIGEM : '')."; media-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

        $v = new View('gestao.'.$aba);
        foreach ($dados as $k => $val) $v->$k = $val;
        $layout = new View('gestao.layout');
        $layout->conteudo = $v->render();
        $layout->aba = $aba;
        $layout->titulo = $titulo;
        $layout->csrf = G2WGestao::csrfToken();
        $layout->usuario = (string)$this->user->info['username'];
        $layout->agora = time();
        $layout->com_mapa = $comMapa;
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
        $arq = G2WGestao::arquivoMiniatura($id);
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
        $atual = G2WGestao::q("SELECT id, protocol, device, substream_path FROM Devices WHERE id=?", 'i', array($id));
        if (!$atual || in_array($id, G2WGestao::idsRemovidas(), true)) $this->falha(404, 'Câmera não encontrada.');

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
            // o segundo stream mora no mesmo aparelho ("host|porta|caminho"):
            // acompanha o host e a porta novos, mantém o caminho dele
            $sub = explode('|', (string)$atual[0]['substream_path'], 3);
            $sets[] = 'substream_path=?';
            $tipos .= 's';
            $vals[] = $f['host'].'|'.$f['porta'].'|'.(isset($sub[2]) ? $sub[2] : '');
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
        return array('device' => $device, 'host' => $p['host'], 'porta' => $porta, 'usuario' => $usuario, 'senha' => $senha);
    }

    // ------------------------------------ cadastro e remoção de câmera

    /* GET /gestao/modelos?fabricante=<id> -> modelos com caminho RTSP, da base
     * local do servidor. */
    public function getModelos()
    {
        session_write_close();
        $f = intval(filter_input(INPUT_GET, 'fabricante', FILTER_VALIDATE_INT));
        if ($f <= 0) $this->falha(422, 'Escolha o fabricante.');
        $this->json(array('ok' => true, 'modelos' => G2WGestao::modelosDoFabricante($f)));
    }

    private function enderecoDoPedido($e)
    {
        try {
            return G2WGestao::enderecoCamera($e);
        } catch (InvalidArgumentException $ex) {
            $this->falha(422, $ex->getMessage());
        }
    }

    /* POST /gestao/camera-testar
     *   {host, porta, caminho, usuario, senha}  testa o que está no formulário
     *   {id}                                    testa a câmera já cadastrada, com a senha guardada
     * Responde só o resultado classificado (ver G2WGestao::testarRtsp). */
    public function postCameraTestar()
    {
        $e = $this->entrada();
        if (isset($e['id'])) {
            $id = $this->inteiro($e, 'id', 1, PHP_INT_MAX);
            $c = G2WGestao::q("SELECT protocol, device, rtsp_username, rtsp_password FROM Devices WHERE id=?", 'i', array($id));
            if (!$c || in_array($id, G2WGestao::idsRemovidas(), true)) $this->falha(404, 'Câmera não encontrada.');
            $partes = explode('|', (string)$c[0]['device'], 3);
            if ($c[0]['protocol'] !== 'IP-RTSP' || count($partes) < 3) $this->falha(422, 'Este tipo de câmera só é testado no modo avançado.');
            $alvo = array('host' => G2WGestao::hostCamera($partes[0]), 'porta' => intval($partes[1]),
                'caminho' => G2WGestao::caminhoRtsp($partes[2]), 'usuario' => (string)$c[0]['rtsp_username'], 'senha' => (string)$c[0]['rtsp_password']);
            if ($alvo['host'] === null || $alvo['caminho'] === null || $alvo['porta'] < 1 || $alvo['porta'] > 65535) {
                $this->falha(422, 'O endereço guardado desta câmera tem formato que o teste não aceita. Confira no modo avançado.');
            }
        } else {
            $alvo = $this->enderecoDoPedido($e);
        }
        $uid = intval($_SESSION['id']);
        session_write_close();
        $vaga = G2WGestao::vagaDeTeste($uid);
        if (!is_array($vaga)) $this->falha(429, $vaga);
        $r = G2WGestao::testarRtsp($alvo['host'], $alvo['porta'], $alvo['caminho'], $alvo['usuario'], $alvo['senha']);
        foreach ($vaga as $t) fclose($t);
        error_log('g2w-teste-camera usuario='.$uid.' resultado='.($r['conectou'] ? 'ok' : $r['motivo']));
        $this->json(array('ok' => true, 'teste' => $r));
    }

    /* POST /gestao/camera-nova. A criação mora em G2WGestao::criarCamera(),
     * a mesma que o importador de linha de comando usa. */
    public function postCameraNova()
    {
        $e = $this->entrada();
        try {
            $id = G2WGestao::criarCamera($e);
        } catch (InvalidArgumentException $ex) {
            $this->falha(422, $ex->getMessage());
        }
        error_log('g2w-camera-nova usuario='.intval($_SESSION['id']).' camera='.$id);
        $this->json(array('ok' => true, 'id' => $id, 'msg' => 'Câmera adicionada. Ela aparece ao vivo em até 1 minuto.'));
    }

    /* POST /gestao/camera-remover {id, apagar_gravacoes: bool}
     *
     * A câmera sai de uso (ver G2WGestao::removerCamera). As gravações NÃO
     * são apagadas, a menos que o pedido diga: ficam visíveis só para
     * administrador, em Gravações, até vencerem ou serem apagadas. */
    public function postCameraRemover()
    {
        $e = $this->entrada();
        $id = $this->inteiro($e, 'id', 1, PHP_INT_MAX);
        if (in_array($id, G2WGestao::idsRemovidas(), true)) $this->falha(404, 'Câmera não encontrada.');
        $apagar = !empty($e['apagar_gravacoes']);
        $nome = G2WGestao::removerCamera($id, $apagar);
        if ($nome === false) $this->falha(404, 'Câmera não encontrada.');
        error_log('g2w-camera-removida usuario='.intval($_SESSION['id']).' camera='.$id.' apagar_gravacoes='.($apagar ? 1 : 0));
        $this->json(array('ok' => true, 'id' => $id,
            'msg' => $apagar ? 'Câmera removida.' : 'Câmera removida. As gravações dela continuam guardadas.'));
    }

    /* POST /gestao/removida-apagar {id}: apaga o conteúdo das gravações de uma
     * câmera já removida, em voltas de até 20 s. A tela repete até restam = 0;
     * a exclusão dos arquivos vazios fica com o motor (ver a biblioteca). */
    public function postRemovidaApagar()
    {
        $e = $this->entrada();
        $id = $this->inteiro($e, 'id', 1, PHP_INT_MAX);
        if (!in_array($id, G2WGestao::idsRemovidas(), true)) {
            // já saiu de vez (não tinha gravação, ou o motor já terminou): nada a fazer
            if (!empty($e['continuar'])) $this->json(array('ok' => true, 'restam' => 0, 'msg' => 'Gravações apagadas.'));
            $this->falha(404, 'Não há gravações guardadas dessa câmera.');
        }
        if (empty($e['continuar'])) error_log('g2w-removida-apagar usuario='.intval($_SESSION['id']).' camera='.$id);
        session_write_close();
        $resta = G2WGestao::apagarGravacoesDaRemovida($id, 20);
        $this->json(array('ok' => true, 'restam' => $resta, 'msg' => $resta ? 'Apagando…' : 'Gravações apagadas.'));
    }

    /* Localização da câmera no mapa. {id, latitude, longitude, endereco} grava;
     * {id, remover: true} apaga. */
    public function postCameraLocal()
    {
        $e = $this->entrada();
        $id = $this->inteiro($e, 'id', 1, PHP_INT_MAX);
        if (!in_array($id, G2WGestao::idsCameras(), true)) $this->falha(404, 'Câmera não encontrada.');

        if (!empty($e['remover'])) {
            G2WGestao::removerLocal($id);
            $this->json(array('ok' => true, 'msg' => 'Localização removida.', 'local' => null));
        }
        try {
            list($lat, $lon) = G2WGestao::coordenada(isset($e['latitude']) ? $e['latitude'] : null, isset($e['longitude']) ? $e['longitude'] : null);
            $endereco = G2WGestao::endereco(isset($e['endereco']) ? $e['endereco'] : '');
        } catch (InvalidArgumentException $ex) {
            $this->falha(422, $ex->getMessage());
        }
        G2WGestao::salvarLocal($id, $lat, $lon, $endereco);
        $this->json(array('ok' => true, 'msg' => 'Localização salva.',
            'local' => array('latitude' => $lat, 'longitude' => $lon, 'endereco' => $endereco)));
    }

    // Busca de endereço: serviço público Nominatim, do OpenStreetMap
    // (política em https://operations.osmfoundation.org/policies/nominatim/:
    // no máximo 1 pedido por segundo por aplicação, identificar-se, sem
    // autocompletar, guardar respostas).
    const BUSCA_URL = 'https://nominatim.openstreetmap.org/search';
    const BUSCA_AGENTE = 'G2WMonitor/1.0 (painel de cameras; +https://g2wtecnologia.net)';

    /* GET /gestao/buscar-endereco?q=texto -> até 5 lugares com coordenada.
     *
     * Quem fala com o serviço de fora é este servidor, não o navegador: para
     * lá vão só o texto digitado e o IP do servidor. Não vai usuário, câmera,
     * cookie nem o endereço do painel. Só roda quando o administrador aperta
     * "Buscar" (o pedido precisa do token da página). */
    public function getBuscarEndereco()
    {
        if (!G2WGestao::csrfConfere()) $this->falha(403, 'Pedido recusado por segurança. Recarregue a página e tente de novo.');
        session_write_close();

        $q = (string)filter_input(INPUT_GET, 'q', FILTER_UNSAFE_RAW);
        try {
            $q = G2WGestao::endereco($q);
        } catch (InvalidArgumentException $ex) {
            $this->falha(422, $ex->getMessage());
        }
        if (mb_strlen($q) < 3) $this->falha(422, 'Escreva pelo menos 3 letras do endereço.');

        // mesma busca em menos de um dia: responde do que já está guardado
        $dir = sys_get_temp_dir();
        $cache = $dir.'/g2w-gestao-busca-'.sha1(mb_strtolower($q)).'.json';
        if (is_readable($cache) && (time() - filemtime($cache)) < 86400) {
            $guardado = json_decode(file_get_contents($cache), true);
            if (is_array($guardado)) $this->json(array('ok' => true, 'resultados' => $guardado, 'servico' => 'Nominatim (OpenStreetMap)'));
        }

        // um pedido por segundo para a instalação inteira, não por administrador
        $trava = fopen($dir.'/g2w-gestao-busca.trava', 'c+');
        if (!$trava || !flock($trava, LOCK_EX)) $this->falha(503, 'A busca está ocupada. Tente de novo em instantes.');
        $ultima = floatval(stream_get_contents($trava));
        $espera = 1.1 - (microtime(true) - $ultima);
        if ($espera > 0 && $espera <= 1.1) usleep(intval($espera * 1000000));

        $url = self::BUSCA_URL.'?'.http_build_query(array(
            'format' => 'jsonv2', 'limit' => 5, 'countrycodes' => 'br', 'accept-language' => 'pt-BR', 'q' => $q,
        ));
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => self::BUSCA_AGENTE, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => array('Accept: application/json'),
        ));
        $corpo = curl_exec($ch);
        $codigo = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);
        ftruncate($trava, 0);
        rewind($trava);
        fwrite($trava, sprintf('%.3f', microtime(true)));
        flock($trava, LOCK_UN);
        fclose($trava);

        $lista = ($corpo !== false && $codigo === 200) ? json_decode($corpo, true) : null;
        if (!is_array($lista)) {
            error_log('G2W gestao: busca de endereco falhou (http '.$codigo.')');
            $this->falha(502, 'O serviço de busca de endereço não respondeu. Você pode marcar o ponto direto no mapa.');
        }
        $resultados = array();
        foreach ($lista as $item) {
            if (!is_array($item) || !isset($item['lat'], $item['lon'], $item['display_name'])) continue;
            try {
                list($lat, $lon) = G2WGestao::coordenada($item['lat'], $item['lon']);
            } catch (InvalidArgumentException $ex) {
                continue;
            }
            $resultados[] = array('nome' => mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string)$item['display_name'])), 0, 300),
                'latitude' => $lat, 'longitude' => $lon);
        }
        @file_put_contents($cache, json_encode($resultados), LOCK_EX);
        $this->json(array('ok' => true, 'resultados' => $resultados, 'servico' => 'Nominatim (OpenStreetMap)'));
    }

    /* POST /gestao/sessao-unica {ligado: bool}: liga ou desliga "um aparelho
     * por vez" para os usuários comuns desta instalação. Vale no pedido
     * seguinte; ligar não desliga ninguém na hora (docs/api-sessao-unica.md). */
    public function postSessaoUnica()
    {
        $e = $this->entrada();
        if (!isset($e['ligado']) || !is_bool($e['ligado'])) $this->falha(422, 'Informe se a opção fica ligada ou desligada.');
        G2WGestao::ligarSessaoUnica($e['ligado']);
        error_log('g2w-sessao-unica usuario='.intval($_SESSION['id']).' ligado='.($e['ligado'] ? 1 : 0));
        $this->json(array('ok' => true, 'ligado' => $e['ligado'], 'msg' => $e['ligado']
            ? 'Um aparelho por vez: ligado. Vale a partir do próximo login de cada usuário.'
            : 'Um aparelho por vez: desligado.'));
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
        $bloqueio = $this->bloqueioDoPedido($e);

        if (G2WGestao::q("SELECT id FROM Users WHERE username=? AND id<>?", 'si', array($login, $id))) {
            $this->falha(422, 'Já existe um usuário com o login "'.$login.'".');
        }

        if ($novo) {
            if ($bloqueio === null) $bloqueio = '';
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
            access_backup=?, change_password=? WHERE id=?", 'sssssiiiiii',
            array($login, $nome, $email, $tel, $obs, $admin, $app, $site, $grav, $trocar, $id));
        if ($bloqueio !== null) {
            G2WGestao::q("UPDATE Users SET access_device_list=? WHERE id=?", 'si', array($bloqueio, $id));
        }
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
        $bloqueio = $this->bloqueioDoPedido($e);
        if ($bloqueio === null) $bloqueio = '';

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
                $marcasC = implode(',', array_fill(0, count($comuns), '?'));
                $tiposC = str_repeat('i', count($comuns));
                if ($acao === 'ativar') {
                    // só mexe em quem está de fato desativado: quem já entra só
                    // pelo aplicativo (ou só pelo site) continua como estava
                    $n = G2WGestao::q("UPDATE Users SET access_web=1, access_remote=1
                        WHERE id IN ($marcasC) AND access_web=0 AND access_remote=0", $tiposC, $comuns);
                    $this->json(array('ok' => true, 'msg' => ($n > 0
                        ? $n.' usuário(s) ativado(s): voltam a entrar pelo aplicativo e pelo site.'
                        : 'Nenhum dos selecionados estava desativado.').$nota));
                }
                G2WGestao::q("UPDATE Users SET access_web=0, access_remote=0 WHERE id IN ($marcasC)", $tiposC, $comuns);
                G2WGestao::derrubarSessoes($comuns);
                $this->json(array('ok' => true, 'msg' => count($comuns).' usuário(s) desativado(s): não entram mais.'.$nota));

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
                $bloqueio = $this->bloqueioDoPedido($e);
                if ($bloqueio === null) $this->falha(422, 'Informe as câmeras.');
                G2WGestao::q("UPDATE Users SET access_device_list=? WHERE id IN (".implode(',', array_fill(0, count($comuns), '?')).")",
                    's'.str_repeat('i', count($comuns)), array_merge(array($bloqueio), $comuns));
                $this->json(array('ok' => true, 'msg' => 'Câmeras atualizadas para '.count($comuns).' usuário(s).'.$nota));
        }
        $this->falha(422, 'Ação desconhecida.');
    }

    // --------------------------------------------------------- utilitários

    /* Lista "pode ver" do pedido -> lista de bloqueio. null quando o pedido
     * não trouxe a chave 'cameras' (quem chama decide: manter ou liberar). */
    private function bloqueioDoPedido($e)
    {
        if (!array_key_exists('cameras', $e)) return null;
        if (!is_array($e['cameras'])) $this->falha(422, 'Lista de câmeras inválida.');
        try {
            return G2WGestao::bloqueioDePermitidas($e['cameras']);
        } catch (InvalidArgumentException $ex) {
            $this->falha(422, $ex->getMessage());
        }
    }

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
