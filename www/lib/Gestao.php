<?php defined('INDVR') or exit();

/*
 * G2W Monitor — painel de gestão (2026-11).
 *
 * Camada de dados das telas /gestao (ajax/gestao.php). Regras da casa:
 *  - toda consulta passa por database::prepared() (valores nunca entram no
 *    texto do SQL);
 *  - nada aqui devolve senha, salt ou credencial de câmera;
 *  - as tabelas próprias (prefixo G2WPainel) são criadas sob demanda e o
 *    painel segue funcionando sem elas (só perde "último acesso" e o
 *    histórico de reinícios).
 */
class G2WGestao
{
    const SENHA_MIN = 8;
    const LOTE_MAX = 500;
    // sem arquivo novo há mais que isso, com a agenda mandando gravar = alerta
    const MIN_SEM_GRAVAR_ALERTA = 10;
    // miniatura mais velha que isso = câmera sem imagem (o atualizador passa
    // por cada câmera a cada ~2 min e só troca o arquivo quando consegue)
    const MIN_SEM_IMAGEM = 10;
    const CERT_DIAS_ALERTA = 21;
    // portas HTTPS conferidas na visão geral (certificado)
    const PORTAS_CERT = array(7000 => 'Aplicativo (porta 7000)', 7001 => 'Painel web (porta 7001)');

    private static $tabelasOk = null;

    public static function q($sql, $types = '', $params = array())
    {
        return database::getInstance()->prepared($sql, $types, $params);
    }

    /* PHP do container roda em UTC (o php-fpm limpa as variáveis de ambiente,
     * então o TZ do compose não chega aqui) e o motor de gravação usa a hora
     * local do cliente — na agenda e no nome dos arquivos. As telas de gestão
     * mostram e interpretam essa mesma hora local. Ordem: TZ do ambiente, se
     * um dia chegar; senão o deslocamento medido na gravação mais recente
     * (caminho .../AAAA/MM/DD/<câmera>/HH-MM-SS.mp4 contra o início em UTC);
     * senão o fuso do sistema. */
    public static function aplicarFuso()
    {
        $tz = getenv('TZ');
        if (!$tz || !in_array($tz, timezone_identifiers_list(), true)) $tz = self::fusoPelasGravacoes();
        if (!$tz && is_readable('/etc/timezone')) $tz = trim(file_get_contents('/etc/timezone'));
        if ($tz && in_array($tz, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)) {
            date_default_timezone_set($tz);
        }
        return date_default_timezone_get();
    }

    private static function fusoPelasGravacoes()
    {
        try {
            $r = self::q("SELECT start, filepath FROM Media ORDER BY id DESC LIMIT 1");
        } catch (Throwable $e) {
            return null;
        }
        if (!$r || !preg_match('#/(\d{4})/(\d{2})/(\d{2})/\d+/(\d{2})-(\d{2})-(\d{2})\.\w+$#', $r[0]['filepath'], $m)) return null;
        $local = gmmktime(intval($m[4]), intval($m[5]), intval($m[6]), intval($m[2]), intval($m[3]), intval($m[1]));
        $horas = intval(round(($local - intval($r[0]['start'])) / 3600));
        if ($horas < -12 || $horas > 14) return null;
        // nomes Etc/GMT têm o sinal invertido: UTC-4 é "Etc/GMT+4"
        return ($horas == 0) ? 'UTC' : 'Etc/GMT'.($horas < 0 ? '+' : '-').abs($horas);
    }

    // ---------------------------------------------------------------- CSRF

    public static function csrfToken()
    {
        if (empty($_SESSION['g2w_csrf'])) {
            $_SESSION['g2w_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['g2w_csrf'];
    }

    public static function csrfConfere()
    {
        $enviado = isset($_SERVER['HTTP_X_G2W_CSRF']) ? (string)$_SERVER['HTTP_X_G2W_CSRF'] : '';
        return !empty($_SESSION['g2w_csrf']) && $enviado !== '' && hash_equals($_SESSION['g2w_csrf'], $enviado);
    }

    // ------------------------------------------------- tabelas do painel

    public static function garantirTabelas()
    {
        if (self::$tabelasOk !== null) return self::$tabelasOk;
        try {
            // caminho normal: as duas já existem e nenhum CREATE é executado
            if (count(self::q("SHOW TABLES LIKE 'G2WPainel%'")) >= 6) return self::$tabelasOk = true;
            self::q("CREATE TABLE IF NOT EXISTS G2WPainelAcessos (
                user_id INT NOT NULL PRIMARY KEY,
                ultimo_acesso INT NOT NULL,
                pelo_app TINYINT(1) NOT NULL DEFAULT 0,
                total INT NOT NULL DEFAULT 0
            ) DEFAULT CHARSET=utf8");
            // Localização das câmeras (mapa). Tabela própria, e não colunas em
            // Devices, para não brigar com as migrações do projeto original.
            // Câmera sem linha aqui = sem localização.
            self::q("CREATE TABLE IF NOT EXISTS G2WPainelLocais (
                device_id INT NOT NULL PRIMARY KEY,
                latitude DECIMAL(8,6) NOT NULL,
                longitude DECIMAL(9,6) NOT NULL,
                endereco VARCHAR(200) NOT NULL DEFAULT '',
                atualizado_em INT NOT NULL
            ) DEFAULT CHARSET=utf8mb4");
            self::q("CREATE TABLE IF NOT EXISTS G2WPainelMotor (
                iniciado_em INT NOT NULL PRIMARY KEY,
                visto_em INT NOT NULL
            ) DEFAULT CHARSET=utf8");
            // Câmeras removidas pelo painel cujas gravações ficaram guardadas.
            // O banco apaga as linhas de Media junto com a de Devices (chave
            // estrangeira em cascata), então a câmera removida continua em
            // Devices, desativada e sem endereço nem senha, até a última
            // gravação sair. Aqui ficam o nome original e a data.
            self::q("CREATE TABLE IF NOT EXISTS G2WPainelRemovidas (
                device_id INT NOT NULL PRIMARY KEY,
                nome VARCHAR(100) NOT NULL,
                removida_em INT NOT NULL,
                apagando TINYINT(1) NOT NULL DEFAULT 0
            ) DEFAULT CHARSET=utf8mb4");
            // "Um aparelho por vez": o dono atual de cada usuário comum. Só o
            // SHA-256 da identidade (aparelho ou sessão); sem IP, sem histórico.
            self::q("CREATE TABLE IF NOT EXISTS G2WPainelSessoes (
                user_id INT NOT NULL PRIMARY KEY,
                dono CHAR(64) NOT NULL,
                identificado TINYINT(1) NOT NULL DEFAULT 0,
                pelo_app TINYINT(1) NOT NULL DEFAULT 0,
                desde INT NOT NULL
            ) DEFAULT CHARSET=ascii");
            // "Formato da imagem" por câmera. Sem linha = automático. Só a
            // exibição muda (painel, ao vivo, miniatura, app): a gravação não.
            self::q("CREATE TABLE IF NOT EXISTS G2WPainelFormatos (
                device_id INT NOT NULL PRIMARY KEY,
                formato VARCHAR(10) NOT NULL,
                alterado_em INT NOT NULL
            ) DEFAULT CHARSET=ascii");
            self::$tabelasOk = true;
        } catch (Throwable $e) {
            error_log('G2W gestao: tabelas do painel indisponiveis: '.$e->getMessage());
            self::$tabelasOk = false;
        }
        return self::$tabelasOk;
    }

    /* Chamado pelo user::doLogin() a cada login certo (site e app). Guarda só
     * QUANDO foi o último acesso e por onde — sem IP, sem histórico. Nunca
     * pode derrubar o login: qualquer falha é engolida. */
    public static function registrarLogin($userId, $peloApp)
    {
        // Quem acabou de provar a senha não pode continuar marcado para ser
        // derrubado: a marca (ActiveUsers.kick) é renovada a cada tentativa e,
        // sem isto, redefinir a senha ou reativar alguém deixava a pessoa sem
        // conseguir entrar enquanto insistisse.
        try {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
            self::q("UPDATE ActiveUsers SET kick=0 WHERE id=? AND ip=? AND kick=1", 'is', array(intval($userId), $ip));
        } catch (Throwable $e) {
            error_log('G2W gestao: limpar kick: '.$e->getMessage());
        }
        try {
            if (!self::garantirTabelas()) return;
            self::q("INSERT INTO G2WPainelAcessos (user_id, ultimo_acesso, pelo_app, total) VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE ultimo_acesso=VALUES(ultimo_acesso), pelo_app=VALUES(pelo_app), total=total+1",
                'iii', array(intval($userId), time(), $peloApp ? 1 : 0));
        } catch (Throwable $e) {
            error_log('G2W gestao: registrarLogin: '.$e->getMessage());
        }
    }

    // ------------------------------------- um aparelho por vez (sessão única)
    //
    // Contrato em docs/api-sessao-unica.md. Opção da instalação, desligada por
    // padrão. Ligada, cada usuário comum tem um "dono": o aparelho (ou a
    // sessão) do login mais recente. Pedido de quem não é o dono recebe 401
    // com X-G2W-Sessao: substituida. Nunca usa o IP.

    const SESSAO_UNICA_PARAM = 'G2W_SESSAO_UNICA';
    private static $sessaoUnica = null;

    public static function sessaoUnicaLigada()
    {
        if (self::$sessaoUnica !== null) return self::$sessaoUnica;
        try {
            $r = self::q("SELECT value FROM GlobalSettings WHERE parameter=?", 's', array(self::SESSAO_UNICA_PARAM));
            self::$sessaoUnica = ($r && (string)$r[0]['value'] === '1');
        } catch (Throwable $e) {
            error_log('G2W gestao: sessao unica: '.$e->getMessage());
            self::$sessaoUnica = false;   // na dúvida, não derruba ninguém
        }
        return self::$sessaoUnica;
    }

    /* Liga ou desliga. Ligar apaga os donos: ninguém é desligado por um login
     * antigo; a regra vale a partir do próximo login de cada um. */
    public static function ligarSessaoUnica($ligar)
    {
        if (!self::garantirTabelas()) throw new RuntimeException('tabelas indisponiveis');
        $v = $ligar ? '1' : '0';
        if (self::q("SELECT parameter FROM GlobalSettings WHERE parameter=?", 's', array(self::SESSAO_UNICA_PARAM))) {
            self::q("UPDATE GlobalSettings SET value=? WHERE parameter=?", 'ss', array($v, self::SESSAO_UNICA_PARAM));
        } else {
            self::q("INSERT INTO GlobalSettings (parameter, value) VALUES (?, ?)", 'ss', array(self::SESSAO_UNICA_PARAM, $v));
        }
        self::q("DELETE FROM G2WPainelSessoes");
        self::$sessaoUnica = (bool)$ligar;
    }

    /* Identidade mandada pelo aplicativo no cabeçalho X-G2W-Aparelho.
     * null = não veio; false = veio fora do formato; senão o SHA-256. */
    public static function aparelhoDoPedido()
    {
        if (!isset($_SERVER['HTTP_X_G2W_APARELHO'])) return null;
        $v = (string)$_SERVER['HTTP_X_G2W_APARELHO'];
        if (!preg_match('/^[A-Za-z0-9._\-]{16,128}$/', $v)) return false;
        return hash('sha256', 'aparelho:'.$v);
    }

    /* Login certo: este aparelho passa a ser o dono. Se o dono anterior era
     * outro, as chaves do ao vivo do usuário são apagadas (as do aparelho novo
     * ainda não existem: ele pede depois do login). Chamado pelo
     * user::doLogin(); nunca pode derrubar o login. */
    public static function sessaoAssumir($userId, $admin, $peloApp)
    {
        try {
            unset($_SESSION['g2w_dono'], $_SESSION['g2w_substituida'], $_SESSION['g2w_substituida_app']);
            if ($admin || !self::sessaoUnicaLigada() || !self::garantirTabelas()) return;
            $userId = intval($userId);
            $h = self::aparelhoDoPedido();
            $identificado = is_string($h);
            // sem cabeçalho (site, app antigo, desktop): a identidade é esta sessão
            $dono = $identificado ? $h : hash('sha256', 'sessao:'.bin2hex(random_bytes(32)));
            $antes = self::q("SELECT dono FROM G2WPainelSessoes WHERE user_id=?", 'i', array($userId));
            if ($antes && !hash_equals((string)$antes[0]['dono'], $dono)) self::apagarChavesDeVideo($userId);
            self::q("REPLACE INTO G2WPainelSessoes (user_id, dono, identificado, pelo_app, desde) VALUES (?, ?, ?, ?, ?)",
                'isiii', array($userId, $dono, $identificado ? 1 : 0, $peloApp ? 1 : 0, time()));
            $_SESSION['g2w_dono'] = $dono;
        } catch (Throwable $e) {
            error_log('G2W gestao: sessaoAssumir: '.$e->getMessage());
        }
    }

    /* Este pedido vem do dono? $comSessao: autenticado pelo cookie (true) ou
     * por Basic sem sessão (false). Falha de banco nunca derruba ninguém. */
    public static function sessaoConfere($userId, $admin, $comSessao)
    {
        if ($admin || !self::sessaoUnicaLigada()) return true;
        try {
            if (!self::q("SHOW TABLES LIKE 'G2WPainelSessoes'")) return true;
            $r = self::q("SELECT dono, identificado FROM G2WPainelSessoes WHERE user_id=?", 'i', array(intval($userId)));
        } catch (Throwable $e) {
            error_log('G2W gestao: sessaoConfere: '.$e->getMessage());
            return true;
        }
        if (!$r) return true;   // ainda sem dono: a regra começa no próximo login
        $dono = (string)$r[0]['dono'];
        if ($comSessao) {
            return isset($_SESSION['g2w_dono']) && is_string($_SESSION['g2w_dono']) && hash_equals($dono, $_SESSION['g2w_dono']);
        }
        $h = self::aparelhoDoPedido();
        if (is_string($h)) return hash_equals($dono, $h);
        if ($h === false) return false;
        // Basic sem cabeçalho não diz de que aparelho vem: só passa enquanto o
        // dono também for um cliente sem identificação (limitação no contrato)
        return !intval($r[0]['identificado']);
    }

    /* Resposta ao aparelho que deixou de ser o dono. Encerra a sessão dele. */
    public static function sessaoRecusar()
    {
        $pelo_app = !empty($_SESSION['from_client']) || !empty($_SESSION['g2w_substituida_app']);
        // A sessão perde tudo, menos a marca: os próximos pedidos com este
        // mesmo cookie (inclusive os que já estavam a caminho, em paralelo)
        // recebem esta mesma resposta, e não o redirecionamento de sessão
        // expirada, que faria o aplicativo antigo refazer o login sozinho.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = array('g2w_substituida' => 1, 'g2w_substituida_app' => $pelo_app ? 1 : 0);
        }
        $aceita = isset($_SERVER['HTTP_ACCEPT']) ? (string)$_SERVER['HTTP_ACCEPT'] : '';
        $metodo = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        header('Cache-Control: no-store');
        header('X-G2W-Sessao: substituida');
        // página aberta no navegador: vai para a tela de entrada, com o motivo
        if (!$pelo_app && $metodo === 'GET' && strpos($aceita, 'text/html') !== false && !isset($_SERVER['PHP_AUTH_USER'])) {
            header('Location: /login?motivo=outro-aparelho');
            exit;
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        $msg = 'Sua conta foi acessada em outro aparelho.';
        die(json_encode(array('ok' => false, 'success' => false, 'codigo' => 'sessao_substituida', 'erro' => $msg, 'message' => $msg), JSON_UNESCAPED_UNICODE));
    }

    /* Sair pelo aparelho que é o dono libera a vez. */
    public static function sessaoLiberarSeDono($userId)
    {
        try {
            if (empty($_SESSION['g2w_dono']) || !self::q("SHOW TABLES LIKE 'G2WPainelSessoes'")) return;
            self::q("DELETE FROM G2WPainelSessoes WHERE user_id=? AND dono=?", 'is', array(intval($userId), (string)$_SESSION['g2w_dono']));
        } catch (Throwable $e) {
            error_log('G2W gestao: sessaoLiberar: '.$e->getMessage());
        }
    }

    private static function apagarChavesDeVideo($userId)
    {
        foreach (array('HlsAuthTokens', 'RtspAuthTokens') as $t) {
            try { self::q("DELETE FROM {$t} WHERE user_id=?", 'i', array(intval($userId))); }
            catch (Throwable $e) { /* tabela ausente em versão antiga do banco */ }
        }
    }

    // ------------------------------------------------------------ câmeras

    /* Câmeras em uso. As removidas pelo painel (ver removerCamera) não contam. */
    public static function idsCameras()
    {
        $ids = array_map('intval', array_column(self::q("SELECT id FROM Devices ORDER BY id"), 'id'));
        return array_values(array_diff($ids, self::idsRemovidas()));
    }

    private static $removidasIds = null;

    /* ids das câmeras removidas pelo painel que ainda estão guardadas. Usado
     * por user::camPermission() em toda rota: não pode falhar nem custar caro. */
    public static function idsRemovidas()
    {
        if (self::$removidasIds !== null) return self::$removidasIds;
        try {
            if (!self::q("SHOW TABLES LIKE 'G2WPainelRemovidas'")) return self::$removidasIds = array();
            self::$removidasIds = array_map('intval', array_column(self::q("SELECT device_id FROM G2WPainelRemovidas"), 'device_id'));
        } catch (Throwable $e) {
            error_log('G2W gestao: idsRemovidas: '.$e->getMessage());
            self::$removidasIds = array();
        }
        return self::$removidasIds;
    }

    public static function agendaGlobal()
    {
        $r = self::q("SELECT value FROM GlobalSettings WHERE parameter='G_DEV_SCED'");
        $s = $r ? (string)$r[0]['value'] : '';
        return (strlen($s) == 168) ? $s : str_repeat('C', 168);
    }

    public static function padraoDoServidor()
    {
        return self::modoAgenda(self::agendaGlobal());
    }

    private static function modoAgenda($agenda)
    {
        if ($agenda === str_repeat('C', 168)) return 'sempre';
        if ($agenda === str_repeat('N', 168)) return 'nunca';
        return 'personalizada';
    }

    /* "host|porta|caminho" (coluna Devices.device) -> rtsp://host:porta/caminho,
     * sem usuário nem senha. */
    public static function fonteLegivel($protocolo, $device)
    {
        $partes = explode('|', (string)$device, 3);
        if (count($partes) < 2 || substr($protocolo, 0, 2) !== 'IP') return (string)$device;
        $caminho = isset($partes[2]) ? $partes[2] : '';
        if ($caminho !== '' && $caminho[0] !== '/') $caminho = '/'.$caminho;
        $esquema = ($protocolo === 'IP-MJPEG') ? 'http' : 'rtsp';
        return $esquema.'://'.$partes[0].':'.$partes[1].$caminho;
    }

    public static function cameras()
    {
        $agora = time();
        $global = self::agendaGlobal();
        $slot = intval(date('G')) + intval(date('w')) * 24;

        // miniaturas em horário fixo nesta instalação? (ver misc/snapshots/snapshot-lib.php)
        $agenda_min = null; $corte_min = 0;
        if (is_file('/usr/share/bluecherry/snapshots/snapshot-lib.php')) {
            require_once '/usr/share/bluecherry/snapshots/snapshot-lib.php';
            if (function_exists('bc_snap_agenda') && ($agenda_min = bc_snap_agenda())) {
                $corte_min = bc_snap_ultimo_horario($agenda_min, $agora);
            }
        }

        $linhas = self::q("SELECT id, device_name, protocol, device, (rtsp_username IS NOT NULL AND rtsp_username<>'') AS tem_credencial,
            disabled, max_age_days, schedule, schedule_override_global FROM Devices ORDER BY device_name");
        $removidas = self::idsRemovidas();

        $agg = array();
        foreach (self::q("SELECT device_id, MIN(IF(archive=0, start, NULL)) AS antiga, MAX(end) AS ultimo_fim,
                SUM(IF(archive=0, size, 0)) AS bytes, SUM(IF(start>?, size, 0)) AS bytes_24h,
                MIN(IF(start>?, start, NULL)) AS ini_24h
                FROM Media GROUP BY device_id", 'ii', array($agora - 86400, $agora - 86400)) as $a) {
            $agg[intval($a['device_id'])] = $a;
        }
        $ultimo = array();
        foreach (self::q("SELECT m.device_id, m.start, m.end, m.filepath FROM Media m
                JOIN (SELECT device_id, MAX(id) AS id FROM Media GROUP BY device_id) x ON x.id=m.id") as $u) {
            $ultimo[intval($u['device_id'])] = $u;
        }

        clearstatcache();
        $cams = array();
        foreach ($linhas as $l) {
            $id = intval($l['id']);
            if (in_array($id, $removidas, true)) continue;
            $segue_padrao = !intval($l['schedule_override_global']) || strlen((string)$l['schedule']) != 168;
            $agenda = $segue_padrao ? $global : $l['schedule'];
            $letra = $agenda[$slot];
            $deve_gravar = !intval($l['disabled']) && $letra === 'C';

            // última escrita em disco: fim do último arquivo fechado, ou a
            // data de modificação do arquivo que está aberto agora
            $ultima = isset($agg[$id]) ? intval($agg[$id]['ultimo_fim']) : 0;
            $gravando = false;
            if (isset($ultimo[$id]) && intval($ultimo[$id]['end']) == 0) {
                $mt = @filemtime($ultimo[$id]['filepath']);
                $ultima = max($ultima, $mt ? $mt : intval($ultimo[$id]['start']));
                $gravando = $mt && ($agora - $mt) < 120;
            }
            $min_sem_gravar = $ultima ? intval(floor(($agora - $ultima) / 60)) : null;

            $snap_ts = @filemtime(self::arquivoMiniatura($id));
            $imagem_ok = $snap_ts && ($agora - $snap_ts) < self::MIN_SEM_IMAGEM * 60;
            // instalação com miniatura em horário fixo (/etc/g2w/miniaturas.json):
            // a imagem "em dia" é a do último horário agendado; 20 min de folga
            // para a volta de captura terminar.
            if ($agenda_min) {
                $imagem_ok = $snap_ts && ($snap_ts >= $corte_min || ($agora - $corte_min) < 1200);
            }

            $antiga = (isset($agg[$id]) && $agg[$id]['antiga']) ? intval($agg[$id]['antiga']) : null;

            if (intval($l['disabled'])) {
                $estado = array('desativada', 'Desativada', 'A câmera está desligada no sistema.');
            } elseif ($gravando) {
                $estado = array('ok', 'Gravando', 'Gravação em andamento.');
            } elseif ($deve_gravar && ($min_sem_gravar === null || $min_sem_gravar >= self::MIN_SEM_GRAVAR_ALERTA)) {
                $quanto = ($min_sem_gravar === null) ? 'Nunca gravou' : 'Sem gravar há '.self::duracao($min_sem_gravar * 60);
                $estado = $imagem_ok
                    ? array('erro', $quanto, 'A câmera responde, mas não há gravação nova. Deveria estar gravando agora.')
                    : array('erro', 'Fora do ar', $quanto.' e sem imagem. Verifique a câmera, a energia e a rede.');
            } elseif (!$imagem_ok) {
                $estado = array('alerta', 'Sem imagem', 'A câmera não está entregando imagem.');
            } elseif ($deve_gravar && $min_sem_gravar >= 3) {
                $estado = array('alerta', 'Sem gravar há '.self::duracao($min_sem_gravar * 60),
                    'Nada novo foi gravado nos últimos minutos. Se continuar, vira alerta de câmera parada.');
            } elseif ($deve_gravar) {
                $estado = array('ok', 'Gravando', 'Última gravação há '.self::duracao($min_sem_gravar * 60).'.');
            } elseif ($letra === 'N') {
                $estado = array('neutro', 'Só ao vivo', 'A agenda não manda gravar neste horário.');
            } else {
                $estado = array('neutro', 'Grava por evento', 'Grava quando há movimento ou acionamento.');
            }

            $cams[] = array(
                'id' => $id,
                'nome' => (string)$l['device_name'],
                'protocolo' => (string)$l['protocol'],
                'fonte' => self::fonteLegivel($l['protocol'], $l['device']),
                'fonte_editavel' => ($l['protocol'] === 'IP-RTSP'),
                'tem_credencial' => (bool)intval($l['tem_credencial']),
                'desativada' => (bool)intval($l['disabled']),
                'formato' => self::formatoDe($id),
                'retencao_dias' => ($l['max_age_days'] === null) ? null : intval($l['max_age_days']),
                'gravacao' => $segue_padrao ? 'padrao' : self::modoAgenda($l['schedule']),
                'padrao_servidor' => self::modoAgenda($global),
                'deve_gravar' => $deve_gravar,
                'gravando' => $gravando,
                'ultima_gravacao' => $ultima ?: null,
                'min_sem_gravar' => $min_sem_gravar,
                'imagem_ok' => (bool)$imagem_ok,
                'imagem_ts' => $snap_ts ?: null,
                'imagem_agendada' => (bool)$agenda_min,
                'mais_antiga' => $antiga,
                'dias_guardados' => $antiga ? max(0, intval(floor(($agora - $antiga) / 86400))) : 0,
                'bytes' => isset($agg[$id]) ? floatval($agg[$id]['bytes']) : 0.0,
                'bytes_24h' => isset($agg[$id]) ? floatval($agg[$id]['bytes_24h']) : 0.0,
                'ini_24h' => (isset($agg[$id]) && $agg[$id]['ini_24h']) ? intval($agg[$id]['ini_24h']) : null,
                'estado' => array('nivel' => $estado[0], 'rotulo' => $estado[1], 'detalhe' => $estado[2]),
            );
        }
        return $cams;
    }

    // ------------------------------------- cadastro e remoção de câmera

    const BASE_MODELOS = '/usr/share/bluecherry/sqlite/cameras.db';
    const FFPROBE = '/usr/lib/bluecherry/ffprobe';
    const TESTE_SEGUNDOS = 10;
    const TESTE_SIMULTANEOS = 2;

    private static function baseModelos()
    {
        if (!is_readable(self::BASE_MODELOS)) return null;
        try {
            $db = new PDO('sqlite:'.self::BASE_MODELOS, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
            $db->exec('PRAGMA query_only = ON');
            return $db;
        } catch (Throwable $e) {
            error_log('G2W gestao: base de modelos: '.$e->getMessage());
            return null;
        }
    }

    /* Fabricantes que têm ao menos um modelo com caminho RTSP conhecido, da
     * base local que já vem no servidor (a mesma do modo avançado). Nada é
     * buscado na internet. */
    public static function fabricantes()
    {
        $db = self::baseModelos();
        if (!$db) return array();
        $res = array();
        try {
            foreach ($db->query("SELECT m.id, m.manufacturer AS nome, COUNT(*) AS n FROM cameras c JOIN manufacturers m ON m.id=c.manufacturer
                    WHERE c.h264_url<>'' GROUP BY m.id, m.manufacturer ORDER BY m.manufacturer COLLATE NOCASE") as $l) {
                $res[] = array('id' => intval($l['id']), 'nome' => (string)$l['nome'], 'modelos' => intval($l['n']));
            }
        } catch (Throwable $e) {
            error_log('G2W gestao: fabricantes: '.$e->getMessage());
        }
        return $res;
    }

    public static function modelosDoFabricante($fabricanteId)
    {
        $db = self::baseModelos();
        if (!$db) return array();
        $res = array();
        try {
            $st = $db->prepare("SELECT api_id, model, h264_url FROM cameras WHERE manufacturer=? AND h264_url<>'' ORDER BY model COLLATE NOCASE");
            $st->execute(array(intval($fabricanteId)));
            foreach ($st as $l) {
                $caminho = self::caminhoRtsp('/'.ltrim(trim((string)$l['h264_url']), '/'));
                if ($caminho === null) continue;   // caminho da base que o nosso cadastro não aceitaria
                $res[] = array('id' => (string)$l['api_id'], 'modelo' => (string)$l['model'], 'caminho' => $caminho);
            }
        } catch (Throwable $e) {
            error_log('G2W gestao: modelos: '.$e->getMessage());
        }
        return $res;
    }

    public static function modeloExiste($apiId)
    {
        $db = self::baseModelos();
        if (!$db) return false;
        $st = $db->prepare("SELECT 1 FROM cameras WHERE api_id=?");
        $st->execute(array((string)$apiId));
        return (bool)$st->fetchColumn();
    }

    /* Endereço da câmera: IPv4 ou nome de host. Devolve o texto normalizado ou
     * null. "|" é o separador da coluna Devices.device, então nunca passa. */
    public static function hostCamera($h)
    {
        if (!is_string($h)) return null;
        $h = strtolower(trim($h));
        if ($h === '' || strlen($h) > 253) return null;
        if (!preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $h) || strpos($h, '..') !== false) return null;
        if (preg_match('/^[0-9.]+$/', $h)) {
            if (filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return null;
            if (!self::ipDeCameraAceito($h)) return null;
        }
        return $h;
    }

    /* Recusa só o que nunca é câmera: 0.x, link-local (169.254.x, onde moram
     * serviços de metadados), multicast e broadcast. Rede interna e o próprio
     * servidor passam: o administrador já pode cadastrar câmera em qualquer
     * endereço, e há instalação com repetidor de vídeo na mesma máquina. */
    public static function ipDeCameraAceito($ip)
    {
        $n = ip2long($ip);
        if ($n === false) return false;
        $a = ($n >> 24) & 0xff;
        $b = ($n >> 16) & 0xff;
        if ($a === 0 || $a >= 224 || ($a === 169 && $b === 254)) return false;
        return true;
    }

    /* Caminho do vídeo: começa com "/", sem espaço, sem "|" e sem os
     * caracteres que o painel original imprimiria como HTML. */
    public static function caminhoRtsp($c)
    {
        if (!is_string($c)) return null;
        $c = trim($c);
        if ($c === '') return null;
        if ($c[0] !== '/') $c = '/'.$c;
        if (strlen($c) > 200 || preg_match('/[\s|<>"\'\\\\\x00-\x1f\x7f-\xff]/', $c)) return null;
        return $c;
    }

    /* Endereço de câmera em campos separados: host, porta, caminho, usuario,
     * senha. Devolve o conjunto normalizado ou lança InvalidArgumentException
     * com texto para a tela. A senha nunca entra em mensagem. */
    public static function enderecoCamera($e)
    {
        $host = self::hostCamera(isset($e['host']) ? $e['host'] : null);
        if ($host === null) throw new InvalidArgumentException('Endereço da câmera inválido. Use o número IP (exemplo: 192.168.1.50) ou o nome dela na rede, sem rtsp:// e sem barras.');
        $porta = isset($e['porta']) ? $e['porta'] : null;
        if (is_string($porta) && preg_match('/^\d{1,5}$/', $porta)) $porta = intval($porta);
        if (!is_int($porta) || $porta < 1 || $porta > 65535) throw new InvalidArgumentException('Porta RTSP inválida: informe um número de 1 a 65535 (o comum é 554).');
        $caminho = self::caminhoRtsp(isset($e['caminho']) ? $e['caminho'] : null);
        if ($caminho === null) throw new InvalidArgumentException('Caminho do vídeo inválido. Exemplo: /cam/realmonitor?channel=1&subtype=0. Não pode ter espaço, aspas nem a barra vertical.');
        $usuario = isset($e['usuario']) ? $e['usuario'] : '';
        $senha = isset($e['senha']) ? $e['senha'] : '';
        if (!is_string($usuario) || !is_string($senha)) throw new InvalidArgumentException('Usuário ou senha da câmera inválidos.');
        $usuario = trim($usuario);
        if (strlen($usuario) > 64 || preg_match('/[\s<>"\'\\\\\x00-\x1f\x7f]/', $usuario)) throw new InvalidArgumentException('O usuário da câmera tem caractere não permitido (espaço, < > aspas ou barra invertida) ou passa de 64 caracteres.');
        if (strlen($senha) > 128 || !mb_check_encoding($senha, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $senha)) throw new InvalidArgumentException('A senha da câmera passa de 128 caracteres ou tem caractere de controle.');
        if ($usuario === '' && $senha !== '') throw new InvalidArgumentException('Informe o usuário da câmera junto com a senha.');
        $device = $host.'|'.$porta.'|'.$caminho;
        if (strlen($device) > 256) throw new InvalidArgumentException('Endereço da câmera longo demais.');
        return array('host' => $host, 'porta' => $porta, 'caminho' => $caminho, 'usuario' => $usuario, 'senha' => $senha, 'device' => $device);
    }

    /* Nome de câmera: recusa < > " ' \ e caracteres de controle (o painel
     * original imprime o nome sem escapar). */
    public static function nomeCamera($v)
    {
        if (!is_string($v)) throw new InvalidArgumentException('O nome da câmera é inválido.');
        $v = trim($v);
        if ($v === '') throw new InvalidArgumentException('O nome da câmera não pode ficar em branco.');
        if (mb_strlen($v) > 100) throw new InvalidArgumentException('O nome da câmera pode ter no máximo 100 caracteres.');
        if (!mb_check_encoding($v, 'UTF-8') || preg_match('/[<>"\'\\\\\x00-\x1f\x7f]/', $v)) {
            throw new InvalidArgumentException('O nome da câmera tem caractere não permitido (< > aspas ou barra invertida).');
        }
        return $v;
    }

    /* Cria uma câmera RTSP. É a única porta de entrada: a tela "Adicionar
     * câmera" e o importador (tools/importar-cameras.php) chamam aqui.
     *
     * $e: nome, host, porta, caminho, usuario, senha, retencao_dias (ou null),
     *     gravacao (padrao|sempre|nunca), gravar_som, modelo (id da base
     *     local, opcional), desativada, local {latitude, longitude, endereco}.
     * Mesmas colunas e padrões do cadastro do modo avançado
     * (ipCamera::create), com consulta parametrizada. O motor acha a linha
     * nova sozinho, na conferência periódica dele. Devolve o id; entrada
     * recusada vira InvalidArgumentException com texto para a tela. */
    public static function criarCamera($e)
    {
        if (!is_array($e)) throw new InvalidArgumentException('Pedido inválido.');
        $nome = self::nomeCamera(isset($e['nome']) ? $e['nome'] : '');
        $a = self::enderecoCamera($e);
        if (self::q("SELECT id FROM Devices WHERE device_name=?", 's', array($nome))) throw new InvalidArgumentException('Já existe outra câmera com esse nome.');
        if (self::q("SELECT id FROM Devices WHERE device=?", 's', array($a['device']))) throw new InvalidArgumentException('Outra câmera já usa esse endereço.');

        $ret = isset($e['retencao_dias']) ? $e['retencao_dias'] : null;
        if ($ret === '') $ret = null;
        if ($ret !== null) {
            if (!is_numeric($ret) || intval($ret) != $ret || $ret < 1 || $ret > 3650) throw new InvalidArgumentException('Dias guardados: informe um número de 1 a 3650, ou deixe em branco.');
            $ret = intval($ret);
        }
        $grav = isset($e['gravacao']) ? (string)$e['gravacao'] : 'padrao';
        if (!in_array($grav, array('padrao', 'sempre', 'nunca'), true)) throw new InvalidArgumentException('Opção de gravação inválida.');
        $som = !empty($e['gravar_som']);

        $modelo = 'Generic';
        if (isset($e['modelo']) && is_string($e['modelo']) && $e['modelo'] !== '') {
            if (strlen($e['modelo']) > 100 || !self::modeloExiste($e['modelo'])) throw new InvalidArgumentException('Modelo de câmera desconhecido.');
            $modelo = $e['modelo'];
        }

        $local = null;
        if (isset($e['local']) && is_array($e['local'])) {
            list($lat, $lon) = self::coordenada(isset($e['local']['latitude']) ? $e['local']['latitude'] : null, isset($e['local']['longitude']) ? $e['local']['longitude'] : null);
            $local = array($lat, $lon, self::endereco(isset($e['local']['endereco']) ? $e['local']['endereco'] : ''));
        }

        self::q("INSERT INTO Devices (device_name, protocol, device, driver, rtsp_username, rtsp_password, resolutionX, resolutionY,
                mjpeg_path, model, rtsp_rtp_prefer_tcp, onvif_port, substream_path, hls_window_size, hls_segment_size, hls_segment_duration,
                max_age_days, audio_disabled, schedule_override_global, schedule, disabled)
            VALUES (?, 'IP-RTSP', ?, '', ?, ?, 640, 480, ?, ?, 1, 80, ?, 5, 0, 0, ?, ?, ?, ?, ?)",
            'sssssssiiisi', array($nome, $a['device'], $a['usuario'], $a['senha'], $a['host'].'||', $modelo, $a['host'].'|'.$a['porta'].'|',
                $ret, $som ? 0 : 1, ($grav !== 'padrao') ? 1 : 0, str_repeat($grav === 'nunca' ? 'N' : 'C', 168), empty($e['desativada']) ? 0 : 1));
        $novo = self::q("SELECT id FROM Devices WHERE device_name=?", 's', array($nome));
        if (!$novo) throw new RuntimeException('camera nao gravada');
        $id = intval($novo[0]['id']);
        self::removerLocal($id);   // id reaproveitado depois de restauração de banco não herda posição antiga
        if ($local) self::salvarLocal($id, $local[0], $local[1], $local[2]);
        return $id;
    }

    /* Abre o vídeo da câmera por RTSP e diz o que veio. Nunca devolve texto
     * da câmera nem do ffprobe: só categorias e os campos conferidos abaixo.
     *
     * - O nome de host é resolvido UMA vez aqui e o ffprobe recebe o IP (o
     *   endereço conferido é o endereço conectado).
     * - A senha não vai na linha de comando (qualquer processo do servidor
     *   leria em /proc): o endereço completo vai num arquivo temporário 0600,
     *   lido pelo ffprobe e apagado em seguida.
     * - Sem shell; só os protocolos de RTSP são aceitos pelo ffprobe.
     */
    public static function testarRtsp($host, $porta, $caminho, $usuario, $senha)
    {
        $ip = $host;
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $ip = gethostbyname($host.(strpos($host, '.') === false ? '' : '.'));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return array('conectou' => false, 'motivo' => 'nome',
                    'texto' => 'O servidor não achou esse nome na rede. Confira o endereço ou use o número IP da câmera.');
            }
        }
        if (!self::ipDeCameraAceito($ip)) {
            return array('conectou' => false, 'motivo' => 'endereco', 'texto' => 'Esse endereço não pode ser de uma câmera.');
        }

        $credencial = ($usuario !== '') ? rawurlencode($usuario).':'.rawurlencode($senha).'@' : '';
        $url = 'rtsp://'.$credencial.$ip.':'.intval($porta).$caminho;

        $dir = sys_get_temp_dir();
        $lista = tempnam($dir, 'g2w-teste-cam-');
        if ($lista === false) return array('conectou' => false, 'motivo' => 'interno', 'texto' => 'O servidor não conseguiu preparar o teste.');
        chmod($lista, 0600);
        // formato "ffconcat": aspas simples, com a aspa do conteúdo escapada
        file_put_contents($lista, "ffconcat version 1.0\nfile '".str_replace("'", "'\\''", $url)."'\n");

        $cmd = array('timeout', '-k', '2', (string)self::TESTE_SEGUNDOS, self::FFPROBE,
            '-v', 'error', '-f', 'concat', '-safe', '0', '-protocol_whitelist', 'file,rtsp,tcp,rtp,udp',
            '-show_entries', 'stream=codec_type,codec_name,width,height', '-of', 'json', '-i', $lista);
        $saida = '';
        $erros = '';
        $rc = -1;
        $p = @proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $dir, array('PATH' => '/usr/bin:/bin'));
        if (is_resource($p)) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $limite = microtime(true) + self::TESTE_SEGUNDOS + 4;
            do {
                $saida .= (string)stream_get_contents($pipes[1], 65536);
                $erros .= (string)stream_get_contents($pipes[2], 65536);
                if (strlen($saida) > 262144 || strlen($erros) > 262144) break;
                $st = proc_get_status($p);
                if (!$st['running']) { $rc = $st['exitcode']; break; }
                usleep(100000);
            } while (microtime(true) < $limite);
            $saida .= (string)stream_get_contents($pipes[1], 65536);
            $erros .= (string)stream_get_contents($pipes[2], 65536);
            $st = proc_get_status($p);
            if ($st['running']) { proc_terminate($p, 15); usleep(300000); proc_terminate($p, 9); $rc = 124; }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
        }
        @unlink($lista);

        $j = json_decode($saida, true);
        $video = null;
        $audio = null;
        if (is_array($j) && isset($j['streams']) && is_array($j['streams'])) {
            foreach ($j['streams'] as $s) {
                if (!is_array($s) || !isset($s['codec_type'])) continue;
                $codec = isset($s['codec_name']) ? strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string)$s['codec_name'])) : '';
                if ($s['codec_type'] === 'video' && !$video) {
                    $video = array('codec' => $codec, 'largura' => isset($s['width']) ? intval($s['width']) : 0, 'altura' => isset($s['height']) ? intval($s['height']) : 0);
                } elseif ($s['codec_type'] === 'audio' && !$audio) {
                    $audio = array('codec' => $codec);
                }
            }
        }
        if ($video) return self::resultadoDoTeste($video, $audio);

        // sem vídeo: classifica pelo que o ffprobe reclamou, sem repassar o texto
        if ($rc === 124 || $rc === 137 || stripos($erros, 'timed out') !== false || stripos($erros, 'No route to host') !== false
                || stripos($erros, 'Network is unreachable') !== false) {
            return array('conectou' => false, 'motivo' => 'sem_resposta',
                'texto' => 'Sem resposta nesse endereço. Confira o IP, se a câmera está ligada e se está na mesma rede do servidor.');
        }
        // resposta RTSP da câmera, nas duas formas em que o ffprobe a escreve
        // ("method DESCRIBE failed: 404 Not Found", "Server returned 404 ...")
        $codigos = array();
        if (preg_match_all('/(?:failed:|returned)\s*(\d{3})\b/', $erros, $m)) $codigos = array_map('intval', $m[1]);
        if (array_intersect($codigos, array(401, 403))) {
            return array('conectou' => false, 'motivo' => 'senha', 'texto' => ($usuario === '')
                ? 'A câmera respondeu, mas pede usuário e senha.'
                : 'A câmera respondeu, mas recusou o usuário ou a senha.');
        }
        if (array_intersect($codigos, array(400, 404, 406, 451, 454, 455, 459))) {
            return array('conectou' => false, 'motivo' => 'caminho',
                'texto' => 'A câmera respondeu, mas não tem vídeo nesse caminho. Confira o caminho do vídeo (ou escolha outro modelo).');
        }
        if (stripos($erros, 'Connection refused') !== false) {
            return array('conectou' => false, 'motivo' => 'porta',
                'texto' => 'O endereço existe, mas nada atende nessa porta. Confira a porta RTSP (o comum é 554) e se o RTSP está ligado na câmera.');
        }
        if (stripos($erros, 'Invalid data') !== false) {
            return array('conectou' => false, 'motivo' => 'nao_rtsp',
                'texto' => 'Algo atende nesse endereço e porta, mas não é o vídeo de uma câmera (RTSP). Confira a porta.');
        }
        return array('conectou' => false, 'motivo' => 'falha',
            'texto' => 'Não foi possível abrir o vídeo dessa câmera. Confira endereço, porta, caminho, usuário e senha.');
    }

    private static function resultadoDoTeste($video, $audio)
    {
        $nomes_v = array('h264' => 'H.264', 'hevc' => 'H.265', 'mjpeg' => 'MJPEG', 'mpeg4' => 'MPEG-4');
        $nomes_a = array('aac' => 'AAC', 'pcm_alaw' => 'G.711 (PCMA)', 'pcm_mulaw' => 'G.711 (PCMU)', 'opus' => 'Opus',
            'adpcm_g726' => 'G.726', 'adpcm_g726le' => 'G.726', 'mp2' => 'MP2', 'mp3' => 'MP3');
        $v = isset($nomes_v[$video['codec']]) ? $nomes_v[$video['codec']] : 'formato não reconhecido';
        $avisos = array();
        if ($video['codec'] === 'hevc') {
            $avisos[] = 'O vídeo é H.265: grava normalmente, mas não toca em todo navegador (no aplicativo toca). Se puder, mude a câmera para H.264.';
        } elseif ($video['codec'] !== 'h264') {
            $avisos[] = 'O vídeo não é H.264 nem H.265: a gravação pode não funcionar. Mude a câmera para H.264.';
        }
        $a = null;
        if ($audio) {
            $a = isset($nomes_a[$audio['codec']]) ? $nomes_a[$audio['codec']] : 'formato não reconhecido';
            if ($audio['codec'] !== 'aac') {
                $avisos[] = 'O som da câmera é '.$a.', não AAC: a gravação fica sem som. Para gravar som, mude o áudio da câmera para AAC.';
            }
        }
        $resolucao = ($video['largura'] > 0 && $video['altura'] > 0) ? $video['largura'].' × '.$video['altura'] : null;
        $texto = 'Conectou. Vídeo '.$v.($resolucao ? ', '.$resolucao : '').'; '.($a ? 'som '.$a : 'sem som').'.';
        return array('conectou' => true, 'texto' => $texto, 'avisos' => $avisos,
            'video' => array('formato' => $v, 'largura' => $video['largura'], 'altura' => $video['altura']),
            'som' => $a);
    }

    /* Uma vaga de teste: um por administrador e TESTE_SIMULTANEOS no servidor.
     * Devolve os arquivos travados (soltam ao fechar) ou um texto de recusa. */
    public static function vagaDeTeste($userId)
    {
        $dir = sys_get_temp_dir();
        $meu = @fopen($dir.'/g2w-teste-cam-u'.intval($userId).'.trava', 'c');
        if (!$meu || !flock($meu, LOCK_EX | LOCK_NB)) return 'Já há um teste seu em andamento. Espere terminar.';
        for ($i = 0; $i < self::TESTE_SIMULTANEOS; $i++) {
            $vaga = @fopen($dir.'/g2w-teste-cam-vaga'.$i.'.trava', 'c');
            if ($vaga && flock($vaga, LOCK_EX | LOCK_NB)) return array($meu, $vaga);
            if ($vaga) fclose($vaga);
        }
        fclose($meu);
        return 'O servidor já está testando outras câmeras. Tente de novo em instantes.';
    }

    public static function cameraExiste($id)
    {
        return (bool)self::q("SELECT id FROM Devices WHERE id=?", 'i', array(intval($id)));
    }

    /* Tira a câmera de uso sem apagar nada: desativa (o motor fecha o arquivo
     * em gravação e para de conectar), troca o nome e o endereço por marcas
     * (libera os dois para um cadastro novo) e apaga usuário e senha
     * guardados. As gravações continuam em Media, presas a esta linha. */
    public static function removerCamera($id, $apagar)
    {
        $id = intval($id);
        $c = self::q("SELECT device_name FROM Devices WHERE id=?", 'i', array($id));
        if (!$c || !self::garantirTabelas()) return false;
        $nome = mb_substr((string)$c[0]['device_name'], 0, 100);
        self::q("REPLACE INTO G2WPainelRemovidas (device_id, nome, removida_em, apagando) VALUES (?, ?, ?, ?)", 'isii', array($id, $nome, time(), $apagar ? 1 : 0));
        self::$removidasIds = null;
        self::q("UPDATE Devices SET disabled=1, device_name=?, device=?, rtsp_username='', rtsp_password='', substream_path='', mjpeg_path='', substream_mode=0 WHERE id=?",
            'ssi', array(mb_substr($nome, 0, 70).' (removida '.$id.')', 'removida|0|/'.$id, $id));
        // sem a lista de eventos a câmera some do histórico do aplicativo; os vídeos ficam
        self::q("DELETE FROM EventsCam WHERE device_id=?", 'i', array($id));
        self::removerLocal($id);
        // nunca gravou: não há o que guardar, sai de vez agora
        if (!self::q("SELECT id FROM Media WHERE device_id=? LIMIT 1", 'i', array($id))) self::excluirRemovida($id);
        return $nome;
    }

    /* Câmeras removidas que ainda têm gravação no disco. Quando a última
     * gravação sai (apagada aqui, pelo prazo em dias da câmera ou pela limpeza
     * por espaço), a câmera é excluída de vez. */
    public static function removidas()
    {
        if (!self::garantirTabelas()) return array();
        $res = array();
        try {
            foreach (self::q("SELECT r.device_id, r.nome, r.removida_em, r.apagando, d.id AS existe, d.max_age_days,
                    COUNT(m.id) AS arquivos, COALESCE(SUM(m.size), 0) AS bytes, MIN(m.start) AS antiga, MAX(m.start) AS recente
                    FROM G2WPainelRemovidas r
                    LEFT JOIN Devices d ON d.id=r.device_id
                    LEFT JOIN Media m ON m.device_id=r.device_id AND m.archive=0 AND m.filepath<>''
                    GROUP BY r.device_id, r.nome, r.removida_em, r.apagando, d.id, d.max_age_days ORDER BY r.removida_em DESC") as $l) {
                $id = intval($l['device_id']);
                if ($l['existe'] === null) {   // excluída por fora (modo avançado)
                    self::q("DELETE FROM G2WPainelRemovidas WHERE device_id=?", 'i', array($id));
                    continue;
                }
                if (!intval($l['arquivos'])) {
                    if (time() - intval($l['removida_em']) > 120) self::excluirRemovida($id);
                    continue;
                }
                $res[] = array('id' => $id, 'nome' => (string)$l['nome'], 'removida_em' => intval($l['removida_em']),
                    'apagando' => (bool)intval($l['apagando']), 'arquivos' => intval($l['arquivos']), 'bytes' => floatval($l['bytes']),
                    'antiga' => intval($l['antiga']), 'recente' => intval($l['recente']),
                    'retencao_dias' => ($l['max_age_days'] === null) ? null : intval($l['max_age_days']));
            }
        } catch (Throwable $e) {
            error_log('G2W gestao: removidas: '.$e->getMessage());
        }
        return $res;
    }

    /* Exclusão definitiva de uma câmera removida que já não tem gravação. */
    private static function excluirRemovida($id)
    {
        $id = intval($id);
        if (!in_array($id, self::idsRemovidas(), true)) return;
        self::q("DELETE FROM OnvifEvents WHERE device_id=?", 'i', array($id));
        self::q("DELETE FROM PTZPresets WHERE device_id=?", 'i', array($id));
        self::q("DELETE FROM Devices WHERE id=? AND disabled=1", 'i', array($id));   // Media e EventsCam vão em cascata
        self::q("DELETE FROM G2WPainelRemovidas WHERE device_id=?", 'i', array($id));
        self::removerLocal($id);
        self::$removidasIds = null;
    }

    /* Apaga as gravações de uma câmera JÁ removida, por até $segundos.
     *
     * O painel (PHP) não consegue excluir arquivo: as pastas de gravação são
     * do motor e o painel só tem leitura nelas. Mas tem escrita nos arquivos.
     * Então são dois passos:
     *   1. aqui, cada vídeo (e o quadro .jpg ao lado) é esvaziado: o conteúdo
     *      some e o espaço volta na hora;
     *   2. a linha é marcada como vencida (prazo de 1 dia e início recuado), e
     *      a limpeza por prazo do próprio motor, que roda a cada 5 minutos,
     *      exclui o arquivo vazio. Quando a última linha sai, removidas()
     *      exclui a câmera de vez.
     * Devolve quantas gravações ainda faltam esvaziar (0 = passo 1 completo).
     * O arquivo que o motor ainda está fechando fica para a volta seguinte. */
    public static function apagarGravacoesDaRemovida($deviceId, $segundos)
    {
        $deviceId = intval($deviceId);
        if (!in_array($deviceId, self::idsRemovidas(), true)) throw new InvalidArgumentException('Câmera não removida.');
        self::q("UPDATE G2WPainelRemovidas SET apagando=1 WHERE device_id=?", 'i', array($deviceId));
        self::q("UPDATE Devices SET max_age_days=1 WHERE id=? AND disabled=1", 'i', array($deviceId));
        $fim = microtime(true) + $segundos;
        $vencido = time() - 3 * 86400;
        $pendente = "device_id=? AND archive=0 AND filepath<>'' AND start>?";
        $pulados = array();
        do {
            $lote = self::q("SELECT id, filepath, end FROM Media WHERE $pendente ORDER BY id LIMIT 200", 'ii', array($deviceId, $vencido));
            $ids = array();
            clearstatcache();
            foreach ($lote as $m) {
                if (isset($pulados[$m['id']])) continue;
                $real = self::arquivoDeGravacao((string)$m['filepath']);
                if ($real !== false) {
                    // aberto e mexido há menos de 1 min: o motor ainda escreve nele
                    if (intval($m['end']) === 0 && (time() - intval(@filemtime($real))) < 60) { $pulados[$m['id']] = true; continue; }
                    self::esvaziar($real);
                    $foto = preg_replace('/\.[A-Za-z0-9]+$/', '.jpg', $real);   // quadro que o motor grava ao lado
                    if ($foto !== $real && is_file($foto)) self::esvaziar($foto);
                }
                $ids[] = intval($m['id']);
            }
            if (!$ids) break;
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            self::q("UPDATE Media SET size=0, start=LEAST(start, ?), end=IF(end=0, LEAST(start, ?)+1, end) WHERE id IN ($marcas)",
                'ii'.str_repeat('i', count($ids)), array_merge(array($vencido, $vencido), $ids));
        } while (microtime(true) < $fim);
        $resta = self::q("SELECT COUNT(*) AS n FROM Media WHERE $pendente", 'ii', array($deviceId, $vencido));
        return $resta ? intval($resta[0]['n']) : 0;
    }

    private static function esvaziar($arquivo)
    {
        $f = @fopen($arquivo, 'r+');
        if (!$f) { error_log('G2W gestao: nao foi possivel esvaziar '.basename($arquivo)); return false; }
        $ok = ftruncate($f, 0);
        fclose($f);
        return $ok;
    }

    // ------------------------------------------- localização (mapa)

    const ENDERECO_MAX = 200;

    /* id da câmera => array(latitude, longitude, endereco). Só quem tem
     * localização cadastrada aparece. Quem chama é que decide para quem
     * mostrar: localização de câmera é dado sensível (onde ela fica). */
    public static function locais()
    {
        if (!self::garantirTabelas()) return array();
        $res = array();
        try {
            foreach (self::q("SELECT device_id, latitude, longitude, endereco FROM G2WPainelLocais") as $l) {
                $res[intval($l['device_id'])] = array(
                    'latitude' => floatval($l['latitude']),
                    'longitude' => floatval($l['longitude']),
                    'endereco' => (string)$l['endereco'],
                );
            }
        } catch (Throwable $e) {
            error_log('G2W gestao: locais: '.$e->getMessage());
        }
        return $res;
    }

    /* Confere e normaliza o que veio de fora. Devolve array(lat, lon) com 6
     * casas (cerca de 11 cm) ou lança InvalidArgumentException. */
    public static function coordenada($lat, $lon)
    {
        foreach (array($lat, $lon) as $v) {
            if (is_string($v)) $v = trim($v);
            if (!(is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d{1,3}(\.\d{1,15})?$/', $v)))) {
                throw new InvalidArgumentException('Latitude e longitude precisam ser números.');
            }
        }
        $lat = floatval($lat);
        $lon = floatval($lon);
        if (!is_finite($lat) || !is_finite($lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            throw new InvalidArgumentException('Posição fora do mapa: a latitude vai de -90 a 90 e a longitude de -180 a 180.');
        }
        return array(round($lat, 6), round($lon, 6));
    }

    /* Texto livre de uma linha. Não leva HTML para lugar nenhum (toda saída é
     * escapada), então só tira caractere de controle e limita o tamanho. */
    public static function endereco($texto)
    {
        if (!is_string($texto) || !mb_check_encoding($texto, 'UTF-8')) throw new InvalidArgumentException('Endereço inválido.');
        $texto = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $texto));
        // a conexão com o banco é utf8 de 3 bytes: emoji e afins dariam erro ao gravar
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $texto)) {
            throw new InvalidArgumentException('O endereço tem um símbolo que não é aceito (emoji, por exemplo).');
        }
        if (mb_strlen($texto) > self::ENDERECO_MAX) {
            throw new InvalidArgumentException('O endereço pode ter no máximo '.self::ENDERECO_MAX.' caracteres.');
        }
        return $texto;
    }

    public static function salvarLocal($cameraId, $lat, $lon, $endereco)
    {
        if (!self::garantirTabelas()) throw new RuntimeException('tabela de localizacao indisponivel');
        self::q("INSERT INTO G2WPainelLocais (device_id, latitude, longitude, endereco, atualizado_em) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE latitude=VALUES(latitude), longitude=VALUES(longitude), endereco=VALUES(endereco), atualizado_em=VALUES(atualizado_em)",
            'iddsi', array(intval($cameraId), $lat, $lon, $endereco, time()));
    }

    public static function removerLocal($cameraId)
    {
        if (!self::garantirTabelas()) return;
        self::q("DELETE FROM G2WPainelLocais WHERE device_id=?", 'i', array(intval($cameraId)));
    }

    // ------------------------------------------------- formato da imagem

    /* Formato de exibição por câmera (docs/api-formato-imagem.md).
     * automatico: 960x1080 e outras 8:9 (canal de gravador que manda a
     * imagem espremida pela metade) vira 16:9; o resto, como chega.
     * proporção: largura/altura em que a imagem deve ser mostrada, esticada. */
    const FORMATOS = array(
        'auto' => array('Automático', null),
        '16:9' => array('16:9', '16:9'),
        '4:3' => array('4:3', '4:3'),
        'empe' => array('Em pé (9:16)', '9:16'),
        'original' => array('Como chega', null),
    );

    private static $formatosCache = null;

    public static function formatos()
    {
        if (self::$formatosCache !== null) return self::$formatosCache;
        $res = array();
        try {
            if (self::garantirTabelas()) {
                foreach (self::q("SELECT device_id, formato FROM G2WPainelFormatos") as $f) {
                    if (isset(self::FORMATOS[$f['formato']])) $res[intval($f['device_id'])] = (string)$f['formato'];
                }
            }
        } catch (Throwable $e) {
            error_log('G2W gestao: formatos: '.$e->getMessage());
        }
        return self::$formatosCache = $res;
    }

    public static function formatoDe($cameraId)
    {
        $f = self::formatos();
        return isset($f[intval($cameraId)]) ? $f[intval($cameraId)] : 'auto';
    }

    /* Proporção fixa do formato ("16:9", "4:3", "9:16") ou null (automático
     * e "como chega" dependem do tamanho real da imagem). */
    public static function proporcaoDoFormato($formato)
    {
        return isset(self::FORMATOS[$formato]) ? self::FORMATOS[$formato][1] : null;
    }

    public static function salvarFormato($cameraId, $formato)
    {
        if (!isset(self::FORMATOS[$formato])) throw new InvalidArgumentException('Formato da imagem inválido.');
        if (!self::garantirTabelas()) throw new RuntimeException('tabela de formatos indisponivel');
        if ($formato === 'auto') {
            // sem linha = automático; a data fica para a miniatura ser refeita
            self::q("INSERT INTO G2WPainelFormatos (device_id, formato, alterado_em) VALUES (?, 'auto', ?)
                ON DUPLICATE KEY UPDATE formato='auto', alterado_em=VALUES(alterado_em)", 'ii', array(intval($cameraId), time()));
        } else {
            self::q("INSERT INTO G2WPainelFormatos (device_id, formato, alterado_em) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE formato=VALUES(formato), alterado_em=VALUES(alterado_em)", 'isi', array(intval($cameraId), $formato, time()));
        }
        self::$formatosCache = null;
    }

    /* Câmera excluída no modo avançado não deixa a localização para trás. */
    public static function limparLocaisOrfaos()
    {
        if (!self::garantirTabelas()) return;
        try {
            self::q("DELETE l FROM G2WPainelLocais l LEFT JOIN Devices d ON d.id=l.device_id WHERE d.id IS NULL");
            self::q("DELETE f FROM G2WPainelFormatos f LEFT JOIN Devices d ON d.id=f.device_id WHERE d.id IS NULL");
        } catch (Throwable $e) {
            error_log('G2W gestao: locais orfaos: '.$e->getMessage());
        }
    }

    /* Quanto o servidor grava por dia, pelas últimas 24 h. Com menos de 24 h
     * de gravação a soma é extrapolada pelo tempo que de fato foi coberto; com
     * menos de 1 h não há base e devolve 0 (sem previsão). */
    public static function bytesPorDia(array $cams)
    {
        $soma = array_sum(array_column($cams, 'bytes_24h'));
        $inicios = array_filter(array_column($cams, 'ini_24h'));
        if (!$inicios || $soma <= 0) return 0.0;
        $janela = min(86400, time() - min($inicios));
        return ($janela < 3600) ? 0.0 : $soma * 86400 / $janela;
    }

    public static function arquivoMiniatura($cameraId)
    {
        return '/var/lib/bluecherry/snapshots/'.intval($cameraId).'.jpg';
    }

    // -------------------------------------------------------------- disco

    public static function discos($bytes_dia)
    {
        $res = array();
        foreach (self::q("SELECT priority, path, max_thresh, min_thresh FROM Storage ORDER BY priority") as $s) {
            $total = @disk_total_space($s['path']);
            $livre = @disk_free_space($s['path']);
            $d = array(
                'prioridade' => intval($s['priority']),
                'caminho' => (string)$s['path'],
                'limite_max' => floatval($s['max_thresh']),
                'limite_min' => floatval($s['min_thresh']),
                'ok' => ($total !== false && $livre !== false && $total > 0),
            );
            if ($d['ok']) {
                $d['total'] = $total;
                $d['livre'] = $livre;
                $d['uso_pct'] = round(($total - $livre) / $total * 100, 1);
                // espaço que ainda pode ser ocupado antes de a limpeza começar
                $folga = $livre - $total * (1 - $d['limite_max'] / 100);
                $d['no_limite'] = ($folga <= 0);
                // previsão só quando faz sentido (pouco histórico dá número absurdo)
                $dias = (!$d['no_limite'] && $bytes_dia > 0) ? $folga / $bytes_dia : null;
                $d['dias_ate_limite'] = ($dias !== null && $dias <= 3650) ? round($dias, 1) : null;
            }
            $res[] = $d;
        }
        return $res;
    }

    // -------------------------------------------------------- certificado

    public static function certificados()
    {
        $res = array();
        foreach (self::PORTAS_CERT as $porta => $rotulo) {
            $c = self::certificadoDaPorta($porta);
            if ($c) { $c['rotulo'] = $rotulo; $c['porta'] = $porta; $res[] = $c; }
        }
        return $res;
    }

    /* Lê o certificado que a porta local está servindo (não depende de
     * permissão de leitura em /etc/letsencrypt). Guarda o resultado por 10 min. */
    private static function certificadoDaPorta($porta)
    {
        $cache = sys_get_temp_dir().'/g2w-gestao-cert-'.intval($porta).'.json';
        if (is_readable($cache) && (time() - filemtime($cache)) < 600) {
            $c = json_decode(file_get_contents($cache), true);
            if (is_array($c)) return empty($c['ausente']) ? self::certComDias($c) : null;
        }
        $ctx = stream_context_create(array('ssl' => array(
            'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false)));
        $sock = @stream_socket_client('ssl://127.0.0.1:'.intval($porta), $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $ctx);
        $c = array('ausente' => true);
        if ($sock) {
            $par = stream_context_get_params($sock);
            fclose($sock);
            $info = isset($par['options']['ssl']['peer_certificate'])
                ? openssl_x509_parse($par['options']['ssl']['peer_certificate']) : false;
            if ($info) {
                $c = array(
                    'nome' => isset($info['subject']['CN']) ? (string)$info['subject']['CN'] : '',
                    'vence_em' => intval($info['validTo_time_t']),
                    'autoassinado' => ($info['subject'] == $info['issuer']),
                );
            }
        }
        @file_put_contents($cache, json_encode($c), LOCK_EX);
        return empty($c['ausente']) ? self::certComDias($c) : null;
    }

    private static function certComDias($c)
    {
        $c['dias'] = intval(floor(($c['vence_em'] - time()) / 86400));
        return $c;
    }

    // -------------------------------------------------------------- motor

    public static function motor()
    {
        $m = array('rodando' => false, 'iniciado_em' => null, 'mensagem' => '', 'historico' => array());
        $st = self::q("SELECT pid, timestamp, message FROM ServerStatus LIMIT 1");
        if ($st) {
            $pid = intval($st[0]['pid']);
            $m['mensagem'] = trim(strtok((string)$st[0]['message'], "\r\n") ?: '');
            // (comm não serve: o processo renomeia a thread principal pra "MAIN")
            $cmd = ($pid > 0) ? @file_get_contents('/proc/'.$pid.'/cmdline') : false;
            if ($cmd !== false && strpos($cmd, 'bc-server') !== false) {
                $m['rodando'] = true;
                $m['iniciado_em'] = self::inicioDoProcesso($pid);
            }
        }
        // Histórico de reinícios: o motor não registra isso no banco, então o
        // painel anota cada "iniciado em" diferente que enxerga. Se o motor
        // reiniciar duas vezes sem ninguém abrir o painel, só a última aparece.
        if (self::garantirTabelas()) {
            try {
                if ($m['iniciado_em']) {
                    self::q("INSERT INTO G2WPainelMotor (iniciado_em, visto_em) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE visto_em=VALUES(visto_em)", 'ii', array($m['iniciado_em'], time()));
                }
                $m['historico'] = array_map('intval', array_column(
                    self::q("SELECT iniciado_em FROM G2WPainelMotor ORDER BY iniciado_em DESC LIMIT 5"), 'iniciado_em'));
            } catch (Throwable $e) {
                error_log('G2W gestao: historico do motor: '.$e->getMessage());
            }
        }
        return $m;
    }

    /* Hora em que o processo começou: campo 22 de /proc/<pid>/stat (ticks desde
     * o boot) somado ao btime de /proc/stat. A data do diretório /proc/<pid>
     * não serve: muda quando o kernel recria o inode, sem o processo reiniciar. */
    private static function inicioDoProcesso($pid)
    {
        $stat = @file_get_contents('/proc/'.intval($pid).'/stat');
        $geral = @file_get_contents('/proc/stat');
        if ($stat === false || $geral === false || !preg_match('/^btime\s+(\d+)/m', $geral, $b)) return null;
        // o nome do processo (2º campo) pode ter espaços e parênteses: conta a partir do último ")"
        $campos = explode(' ', trim(substr($stat, strrpos($stat, ')') + 1)));
        if (!isset($campos[19]) || !ctype_digit($campos[19])) return null;
        $hz = intval(@shell_exec('getconf CLK_TCK 2>/dev/null')) ?: 100;
        $ini = intval($b[1]) + intdiv(intval($campos[19]), $hz);
        return ($ini > 0 && $ini <= time() + 5) ? $ini : null;
    }

    // ----------------------------------------------------------- usuários

    public static function usuarios()
    {
        $tem_acessos = self::garantirTabelas();
        if ($tem_acessos) {
            try {
                // LGPD: não guardar registro de acesso de quem já foi excluído
                self::q("DELETE a FROM G2WPainelAcessos a LEFT JOIN Users u ON u.id=a.user_id WHERE u.id IS NULL");
            } catch (Throwable $e) { $tem_acessos = false; }
        }
        $sql = "SELECT u.id, u.username, u.name, u.email, u.phone, u.notes, u.access_setup, u.access_remote, u.access_web,
                u.access_backup, u.access_device_list, u.change_password"
            .($tem_acessos ? ", a.ultimo_acesso, a.pelo_app FROM Users u LEFT JOIN G2WPainelAcessos a ON a.user_id=u.id"
                           : ", NULL AS ultimo_acesso, NULL AS pelo_app FROM Users u")
            ." ORDER BY u.username";
        $online = array_flip(array_column(self::q("SELECT DISTINCT id FROM ActiveUsers WHERE time > ?", 'i', array(time() - 300)), 'id'));
        $cams = self::idsCameras();
        $res = array();
        foreach (self::q($sql) as $u) {
            $bloq = self::listaIds($u['access_device_list']);
            $admin = (bool)intval($u['access_setup']);
            $res[] = array(
                'id' => intval($u['id']),
                'login' => (string)$u['username'],
                'nome' => (string)$u['name'],
                'email' => (string)$u['email'],
                'telefone' => (string)$u['phone'],
                'obs' => (string)$u['notes'],
                'admin' => $admin,
                'site' => (bool)intval($u['access_web']),
                'app' => (bool)intval($u['access_remote']),
                'gravacoes' => (bool)intval($u['access_backup']),
                // desativado = não entra nem pelo site nem pelo app
                'ativo' => $admin || intval($u['access_web']) || intval($u['access_remote']),
                'trocar_senha' => (bool)intval($u['change_password']),
                'cameras' => array_values(array_diff($cams, $bloq)),
                'ultimo_acesso' => $u['ultimo_acesso'] === null ? null : intval($u['ultimo_acesso']),
                'pelo_app' => $u['pelo_app'] === null ? null : (bool)intval($u['pelo_app']),
                'online' => isset($online[$u['id']]),
            );
        }
        return array('lista' => $res, 'tem_acessos' => $tem_acessos);
    }

    public static function listaIds($csv)
    {
        $ids = array();
        foreach (explode(',', (string)$csv) as $p) {
            $p = trim($p);
            if ($p !== '' && ctype_digit($p)) $ids[] = intval($p);
        }
        return $ids;
    }

    /* "Pode ver" (lista de permitidas) -> access_device_list (lista de
     * BLOQUEIO, que é o que o motor e o app entendem). Devolve a string CSV ou
     * lança exceção se não couber na coluna (varchar 255). */
    public static function bloqueioDePermitidas(array $permitidas)
    {
        $todas = self::idsCameras();
        $permitidas = array_intersect($todas, array_map('intval', $permitidas));
        $csv = implode(',', array_diff($todas, $permitidas));
        if (strlen($csv) > 255) {
            throw new InvalidArgumentException('São câmeras demais bloqueadas para este usuário. Libere mais câmeras ou fale com o suporte.');
        }
        return $csv;
    }

    public static function hashSenha($senha, $salt)
    {
        // formato que o motor de gravação (C++) também confere — não mudar só aqui
        return md5($senha.$salt);
    }

    public static function validarSenha($senha)
    {
        if (!is_string($senha) || strlen($senha) < self::SENHA_MIN) return 'A senha precisa ter pelo menos '.self::SENHA_MIN.' caracteres.';
        if (strlen($senha) > 64) return 'A senha pode ter no máximo 64 caracteres.';
        if (preg_match('/[\x00-\x1f]/', $senha)) return 'A senha tem caractere inválido.';
        return true;
    }

    public static function validarLogin($login)
    {
        if (!is_string($login) || !preg_match('/^[A-Za-z0-9._-]{2,40}$/', $login)) {
            return 'O login deve ter de 2 a 40 caracteres: letras sem acento, números, ponto, hífen ou sublinhado.';
        }
        return true;
    }

    public static function totalAdminsAtivos($excetoIds = array())
    {
        $n = 0;
        foreach (self::q("SELECT id FROM Users WHERE access_setup=1") as $r) {
            if (!in_array(intval($r['id']), $excetoIds, true)) $n++;
        }
        return $n;
    }

    /* Encerra o que o usuário tiver aberto agora (site, app, ao vivo). */
    public static function derrubarSessoes(array $ids)
    {
        foreach ($ids as $id) {
            $id = intval($id);
            self::q("UPDATE ActiveUsers SET kick=1 WHERE id=?", 'i', array($id));
            self::apagarChavesDeVideo($id);
            // libera a vez no "um aparelho por vez": o próximo login é o dono
            try { if (self::garantirTabelas()) self::q("DELETE FROM G2WPainelSessoes WHERE user_id=?", 'i', array($id)); }
            catch (Throwable $e) { error_log('G2W gestao: liberar vez: '.$e->getMessage()); }
        }
    }

    // ---------------------------------------------------------- gravações

    public static function gravacoesDoDia($cameraId, $dia)
    {
        $ini = DateTime::createFromFormat('!Y-m-d', $dia);
        if (!$ini || $ini->format('Y-m-d') !== $dia) throw new InvalidArgumentException('Data inválida.');
        $t0 = $ini->getTimestamp();
        $fim = clone $ini;
        $t1 = $fim->modify('+1 day')->getTimestamp();
        $agora = time();
        $linhas = self::q("SELECT id, start, end, size FROM Media
            WHERE device_id=? AND archive=0 AND filepath<>'' AND start < ? AND (end > ? OR end = 0) AND start > ?
            ORDER BY start", 'iiii', array(intval($cameraId), $t1, $t0, $t0 - 86400));
        $res = array();
        foreach ($linhas as $l) {
            $s = intval($l['start']);
            $e = intval($l['end']);
            $aberto = ($e == 0);
            // arquivo aberto que não é o mais recente = sobra de reinício; ignora
            if ($aberto) {
                if ($agora - $s > 3600) continue;
                $e = $agora;
            }
            if ($e <= $t0) continue;
            $res[] = array('id' => intval($l['id']), 'inicio' => $s, 'fim' => $e,
                'bytes' => $l['size'] === null ? null : floatval($l['size']), 'aberto' => $aberto);
        }
        return array('inicio_dia' => $t0, 'fim_dia' => $t1, 'trechos' => $res);
    }

    /* Caminho real do arquivo de uma gravação, ou false. O caminho vem do
     * banco; mesmo assim só vale se estiver dentro de uma pasta de gravação
     * cadastrada e existir. */
    public static function arquivoDeGravacao($caminho)
    {
        $real = realpath((string)$caminho);
        if ($real === false || !is_file($real) || !is_readable($real)) return false;
        foreach (self::q("SELECT path FROM Storage") as $s) {
            $base = realpath((string)$s['path']);
            if ($base && $base !== '/' && strpos($real, rtrim($base, '/').'/') === 0) return $real;
        }
        return false;
    }

    /* Entrega um arquivo de vídeo com suporte a Range (o tocador do navegador
     * pede pedaços para pular no tempo) e encerra o pedido. */
    public static function enviarVideo($real, $nomeParaBaixar = null)
    {
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
        if ($nomeParaBaixar !== null) header('Content-Disposition: attachment; filename="'.$nomeParaBaixar.'"');
        $fp = fopen($real, 'rb');
        if (!$fp) die();
        fseek($fp, $ini);
        $resta = $fim - $ini + 1;
        while ($resta > 0 && !feof($fp) && !connection_aborted()) {
            set_time_limit(60);
            $bloco = fread($fp, min(262144, $resta));
            if ($bloco === false) break;
            echo $bloco;
            flush();
            $resta -= strlen($bloco);
        }
        fclose($fp);
        die();
    }

    // --------------------------------------------------------- utilitários

    public static function duracao($seg)
    {
        $seg = max(0, intval($seg));
        if ($seg < 90) return 'menos de 2 min';
        if ($seg < 5400) return round($seg / 60).' min';
        if ($seg < 172800) return round($seg / 3600).' h';
        return round($seg / 86400).' dias';
    }

    public static function tamanho($bytes)
    {
        $bytes = floatval($bytes);
        // espaço não separável entre o número e a unidade (não quebra linha no meio)
        $e = "\u{00A0}";
        if ($bytes >= 1099511627776) return number_format($bytes / 1099511627776, 2, ',', '.').$e.'TB';
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 1, ',', '.').$e.'GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 0, ',', '.').$e.'MB';
        return number_format($bytes / 1024, 0, ',', '.').$e.'KB';
    }

    public static function quando($ts)
    {
        if (!$ts) return '—';
        $hoje = date('Y-m-d');
        $dia = date('Y-m-d', $ts);
        if ($dia === $hoje) return 'hoje às '.date('H:i', $ts);
        if ($dia === date('Y-m-d', time() - 86400)) return 'ontem às '.date('H:i', $ts);
        return date('d/m/Y H:i', $ts);
    }

    public static function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
