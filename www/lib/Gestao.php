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
            if (count(self::q("SHOW TABLES LIKE 'G2WPainel%'")) >= 2) return self::$tabelasOk = true;
            self::q("CREATE TABLE IF NOT EXISTS G2WPainelAcessos (
                user_id INT NOT NULL PRIMARY KEY,
                ultimo_acesso INT NOT NULL,
                pelo_app TINYINT(1) NOT NULL DEFAULT 0,
                total INT NOT NULL DEFAULT 0
            ) DEFAULT CHARSET=utf8");
            self::q("CREATE TABLE IF NOT EXISTS G2WPainelMotor (
                iniciado_em INT NOT NULL PRIMARY KEY,
                visto_em INT NOT NULL
            ) DEFAULT CHARSET=utf8");
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

    // ------------------------------------------------------------ câmeras

    public static function idsCameras()
    {
        return array_map('intval', array_column(self::q("SELECT id FROM Devices ORDER BY id"), 'id'));
    }

    public static function agendaGlobal()
    {
        $r = self::q("SELECT value FROM GlobalSettings WHERE parameter='G_DEV_SCED'");
        $s = $r ? (string)$r[0]['value'] : '';
        return (strlen($s) == 168) ? $s : str_repeat('C', 168);
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

        $linhas = self::q("SELECT id, device_name, protocol, device, (rtsp_username IS NOT NULL AND rtsp_username<>'') AS tem_credencial,
            disabled, max_age_days, schedule, schedule_override_global FROM Devices ORDER BY device_name");

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
                'retencao_dias' => ($l['max_age_days'] === null) ? null : intval($l['max_age_days']),
                'gravacao' => $segue_padrao ? 'padrao' : self::modoAgenda($l['schedule']),
                'padrao_servidor' => self::modoAgenda($global),
                'deve_gravar' => $deve_gravar,
                'gravando' => $gravando,
                'ultima_gravacao' => $ultima ?: null,
                'min_sem_gravar' => $min_sem_gravar,
                'imagem_ok' => (bool)$imagem_ok,
                'imagem_ts' => $snap_ts ?: null,
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
            foreach (array('HlsAuthTokens', 'RtspAuthTokens') as $t) {
                try { self::q("DELETE FROM {$t} WHERE user_id=?", 'i', array($id)); }
                catch (Throwable $e) { /* tabela ausente em versão antiga do banco */ }
            }
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
