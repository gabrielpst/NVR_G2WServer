<?php

/*
 * G2W Monitor — baixar um trecho de gravação num arquivo só (2026-11)
 *
 *   GET /media/trecho?id=<câmera>&inicio=<epoch s>&fim=<epoch s>
 *
 * Junta, sem recodificar, os arquivos gravados (~5 min cada) que cobrem a
 * janela pedida e devolve um MP4. Contrato em docs/api-trecho-gravacao.md.
 *
 * Cuidados que valem para qualquer mudança aqui:
 *  - nada que vem do usuário entra em linha de comando: os três parâmetros são
 *    inteiros, os caminhos vêm do banco (e só valem dentro das pastas de
 *    gravação cadastradas) e o ffmpeg é chamado sem shell;
 *  - o arquivo temporário some no fim, em erro e quando o cliente desiste;
 *  - um pedido por usuário e poucos ao mesmo tempo no servidor todo: cada
 *    download ocupa um processo do PHP (são poucos) e o disco do host já
 *    sofre com I/O.
 */
class mediaTrecho extends Controller
{
    const JANELA_MAX = 1200;        // 20 minutos, limite duro
    const SIMULTANEOS = 2;          // downloads ao mesmo tempo no servidor todo
    const TEMPO_MONTAGEM = 45;      // segundos para o ffmpeg; o nginx espera 60
    const FOLGA_DISCO = 524288000;  // 500 MB livres além do tamanho estimado
    const SOBRA_VELHA = 3600;       // temporário com mais de 1 h é sobra de queda
    const FFMPEG = '/usr/lib/bluecherry/ffmpeg';
    const FFPROBE = '/usr/lib/bluecherry/ffprobe';

    private $apagar = array();
    private $travas = array();
    private $processo = null;

    public function __construct()
    {
        parent::__construct();
        // Sem sessão e sem Basic: 401 em JSON, com o desafio Basic de sempre.
        // cookie de sessão substituída ("um aparelho por vez"): resposta própria
        if (empty($_SESSION['id']) && !empty($_SESSION['g2w_substituida'])) G2WGestao::sessaoRecusar();
        if (empty($_SESSION['id']) && !isset($_SERVER['PHP_AUTH_USER'])) {
            header('WWW-Authenticate: Basic realm="G2W Monitor"');
            $this->erro(401, 'Entre com usuário e senha.');
        }
        $this->chAccess('basic');
    }

    public function postData()
    {
        $this->erro(405, 'Esta rota só aceita leitura (GET).');
    }

    public function getData()
    {
        session_write_close();
        $u = $this->user->info;
        $usuario = intval($u['id']);

        // --- entrada: três inteiros, nada mais -------------------------------
        $id = $this->inteiro('id');
        $inicio = $this->inteiro('inicio');
        $fim = $this->inteiro('fim');
        if ($id === null || $inicio === null || $fim === null || $id < 1) {
            $this->erro(422, 'Informe id, inicio e fim como números inteiros.');
        }
        if ($fim <= $inicio) $this->erro(422, 'O fim precisa ser depois do início.');
        if ($fim - $inicio > self::JANELA_MAX) $this->erro(422, 'O trecho pode ter no máximo 20 minutos.');

        // --- permissão: direito de ver gravações e câmera liberada ----------
        // O Basic herdado não olha se o usuário foi desativado no painel (sem
        // site e sem aplicativo); aqui olha.
        $admin = (bool)intval($u['access_setup']);
        if (!$admin && !intval($u['access_web']) && !intval($u['access_remote'])) {
            $this->erro(403, 'Seu usuário está desativado.');
        }
        if (!$admin && !intval($u['access_backup'])) {
            $this->erro(403, 'Seu usuário não tem acesso às gravações.');
        }
        // Câmera que não existe e câmera bloqueada dão a mesma resposta, para
        // não revelar quais ids existem.
        $cam = G2WGestao::q("SELECT id, device_name FROM Devices WHERE id=?", 'i', array($id));
        if (empty($cam) || !$this->user->camPermission($id)) {
            $this->erro(403, 'Câmera não liberada para o seu usuário.');
        }

        // --- arquivos gravados que tocam a janela ----------------------------
        // end=0 é o arquivo ainda em gravação (ou sobra de reinício): não tem
        // índice e o ffmpeg não lê; fica de fora.
        $linhas = G2WGestao::q("SELECT id, start, end, size, filepath FROM Media
            WHERE device_id=? AND filepath<>'' AND end>0 AND start<? AND end>? AND start>?
            ORDER BY start, id LIMIT 60", 'iiii', array($id, $fim, $inicio, $inicio - 86400));
        $pastas = $this->pastasDeGravacao();
        $partes = array();
        $bytes = 0;
        $cursor = $inicio;
        $completo = true;
        foreach ($linhas as $l) {
            $s = intval($l['start']);
            $e = intval($l['end']);
            $real = realpath((string)$l['filepath']);
            if ($e <= $s || $real === false || !is_file($real) || !$this->dentroDe($real, $pastas)) continue;
            if (!preg_match('/\.(mp4|mkv)$/i', $real) || strpbrk($real, "'\n\r\\") !== false) continue;
            if ($e <= $cursor) continue; // sobreposto a um arquivo já usado
            if ($s > $cursor + 2) $completo = false; // buraco antes deste arquivo
            $de = max($s, $cursor);
            $ate = min($e, $fim);
            $partes[] = array('arquivo' => $real, 'inicio' => $s, 'de' => $de, 'ate' => $ate,
                'corta_fim' => ($e > $fim));
            $bytes += @filesize($real);
            $cursor = $ate;
        }
        if (!$partes) $this->erro(404, 'Não há gravação desta câmera nesse horário.');
        $inicio_real = $partes[0]['de'];
        $fim_real = $cursor;
        if ($fim_real < $fim - 2) $completo = false;

        // --- um pedido por usuário, poucos no servidor -----------------------
        ignore_user_abort(true);
        register_shutdown_function(array($this, 'limpar'));
        $dir = rtrim(sys_get_temp_dir(), '/').'/g2w-trecho';
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) $this->erro(500, 'Sem pasta temporária no servidor.');
        $this->varrerSobras($dir);

        if (!$this->travar($dir.'/usuario-'.$usuario.'.lock')) {
            header('Retry-After: 30');
            $this->erro(429, 'Você já tem um trecho sendo baixado. Espere terminar.');
        }
        $vaga = false;
        for ($i = 0; $i < self::SIMULTANEOS && !$vaga; $i++) $vaga = $this->travar($dir.'/vaga-'.$i.'.lock');
        if (!$vaga) {
            header('Retry-After: 60');
            $this->erro(503, 'O servidor está montando outros trechos. Tente de novo em um minuto.');
        }

        // Sem recodificar, a saída nunca passa da soma dos arquivos de origem.
        $livre = @disk_free_space($dir);
        if ($livre === false || $livre < $bytes + self::FOLGA_DISCO) {
            $this->erro(507, 'Sem espaço no servidor para montar o trecho agora.');
        }

        // --- lista do concat em arquivo temporário ---------------------------
        $base = $dir.'/t-'.$usuario.'-'.bin2hex(random_bytes(8));
        $lista = $base.'.txt';
        $saida = $base.'.mp4';
        $this->apagar = array($lista, $saida);
        $txt = "ffconcat version 1.0\n";
        foreach ($partes as $p) {
            $txt .= "file '".$p['arquivo']."'\n";
            // inpoint com cópia direta recua até o quadro-chave anterior: o
            // vídeo pode começar um pouco antes do pedido, nunca depois.
            if ($p['de'] > $p['inicio']) $txt .= 'inpoint '.($p['de'] - $p['inicio'])."\n";
            if ($p['corta_fim']) $txt .= 'outpoint '.($p['ate'] - $p['inicio'])."\n";
        }
        if (@file_put_contents($lista, $txt, LOCK_EX) === false) $this->erro(500, 'Não foi possível preparar o trecho.');
        @chmod($lista, 0600);

        // --- montagem --------------------------------------------------------
        // "timeout" é a rede de segurança: se este processo do PHP morrer sem
        // limpar (SIGKILL, falta de memória), o ffmpeg não fica rodando sozinho.
        $cmd = array('nice', '-n', '10', 'ionice', '-c', '3',
            'timeout', '-k', '2', (string)(self::TEMPO_MONTAGEM + 5), self::FFMPEG,
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'concat', '-safe', '0', '-i', $lista,
            '-map', '0:v:0', '-map', '0:a?', '-c', 'copy',
            '-avoid_negative_ts', 'make_zero', '-f', 'mp4', $saida);
        $ok = $this->rodar($cmd, self::TEMPO_MONTAGEM);
        if ($ok === null) $this->erro(504, 'O servidor demorou demais para montar o trecho. Tente um trecho menor.');
        clearstatcache();
        $tam = @filesize($saida);
        if (!$ok || !$tam) $this->erro(500, 'Não foi possível montar o trecho.');
        @chmod($saida, 0600);
        $duracao = $this->duracao($saida);

        // --- entrega ---------------------------------------------------------
        if (connection_aborted()) exit;
        G2WGestao::aplicarFuso();
        $nome = 'G2W-Monitor_'.$this->slug($cam[0]['device_name'], $id).'_'.date('Y-m-d_H-i-s', $inicio_real)
            .'_a_'.date('H-i-s', $fim_real).'.mp4';
        error_log('g2w-trecho usuario='.$usuario.' camera='.$id.' inicio='.$inicio_real.' fim='.$fim_real.' bytes='.$tam);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="'.$nome.'"');
        header('Content-Length: '.$tam);
        header('Accept-Ranges: none');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        // Sem isto o nginx copia a resposta inteira para um temporário dele
        // (o dobro de escrita em disco). Em troca, este processo do PHP fica
        // ocupado até o fim do download: por isso a vaga do servidor só é
        // solta no fim.
        header('X-Accel-Buffering: no');
        header('X-G2W-Trecho-Inicio: '.$inicio_real);
        header('X-G2W-Trecho-Fim: '.$fim_real);
        header('X-G2W-Trecho-Duracao: '.($duracao === null ? ($fim_real - $inicio_real) : $duracao));
        header('X-G2W-Trecho-Completo: '.($completo ? '1' : '0'));

        $fp = @fopen($saida, 'rb');
        if (!$fp) exit;
        while (!feof($fp) && !connection_aborted()) {
            set_time_limit(60);
            $bloco = fread($fp, 262144);
            if ($bloco === false) break;
            print($bloco);
            flush();
        }
        fclose($fp);
        exit;
    }

    // ------------------------------------------------------------- auxiliares

    private function inteiro($nome)
    {
        $v = isset($_GET[$nome]) && is_string($_GET[$nome]) ? $_GET[$nome] : '';
        if ($v === '' || strlen($v) > 10 || !ctype_digit($v)) return null;
        return intval($v);
    }

    private function pastasDeGravacao()
    {
        $res = array();
        foreach (G2WGestao::q("SELECT path FROM Storage") as $s) {
            $p = realpath((string)$s['path']);
            if ($p !== false && $p !== '/') $res[] = rtrim($p, '/').'/';
        }
        return $res;
    }

    private function dentroDe($arquivo, $pastas)
    {
        foreach ($pastas as $p) {
            if (strncmp($arquivo, $p, strlen($p)) === 0) return true;
        }
        return false;
    }

    private function travar($arquivo)
    {
        $fp = @fopen($arquivo, 'c');
        if (!$fp) return false;
        if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return false; }
        $this->travas[$arquivo] = $fp;
        return true;
    }

    /* Temporário antigo = pedido que morreu sem limpar (queda do PHP). */
    private function varrerSobras($dir)
    {
        $velhos = glob($dir.'/t-*');
        if (!$velhos) return;
        foreach ($velhos as $f) {
            if (is_file($f) && time() - intval(@filemtime($f)) > self::SOBRA_VELHA) @unlink($f);
        }
    }

    /* true = terminou bem; false = erro; null = estourou o tempo. Sem shell. */
    private function rodar($cmd, $limite)
    {
        $tubos = array();
        $p = @proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'),
            2 => array('file', '/dev/null', 'w')), $tubos, '/', array('LD_LIBRARY_PATH' => '/usr/lib/bluecherry',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'));
        if (!is_resource($p)) return false;
        $this->processo = $p;
        $prazo = microtime(true) + $limite;
        $saiu = null;
        while (true) {
            $st = proc_get_status($p);
            if (!$st['running']) { $saiu = $st['exitcode']; break; }
            if (microtime(true) > $prazo || connection_aborted()) break;
            usleep(100000);
        }
        if ($saiu === null) {
            $this->matar();
            return connection_aborted() ? false : null;
        }
        proc_close($p);
        $this->processo = null;
        return $saiu === 0;
    }

    private function matar()
    {
        if (!is_resource($this->processo)) return;
        // O processo é "nice", que vira "ionice" e depois "timeout" sem criar
        // filho; o ffmpeg é filho do timeout. SIGTERM o timeout repassa ao
        // ffmpeg (e mata de vez 2 s depois); SIGKILL direto deixaria o ffmpeg
        // órfão, por isso só vem depois.
        @proc_terminate($this->processo, 15);
        for ($i = 0; $i < 30; $i++) {
            $st = @proc_get_status($this->processo);
            if (!$st || !$st['running']) break;
            usleep(100000);
        }
        if (!empty($st['running'])) @proc_terminate($this->processo, 9);
        @proc_close($this->processo);
        $this->processo = null;
    }

    private function duracao($arquivo)
    {
        $tubos = array();
        $p = @proc_open(array(self::FFPROBE, '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $arquivo),
            array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
            $tubos, '/', array('LD_LIBRARY_PATH' => '/usr/lib/bluecherry'));
        if (!is_resource($p)) return null;
        $txt = trim((string)stream_get_contents($tubos[1]));
        fclose($tubos[1]);
        proc_close($p);
        return is_numeric($txt) ? round(floatval($txt), 1) : null;
    }

    private function slug($nome, $id)
    {
        $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$nome);
        $s = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$s), '-');
        return $s === '' ? 'camera-'.intval($id) : substr($s, 0, 40);
    }

    public function limpar()
    {
        $this->matar();
        foreach ($this->apagar as $f) {
            if (is_file($f)) @unlink($f);
        }
        $this->apagar = array();
        foreach ($this->travas as $fp) { @flock($fp, LOCK_UN); @fclose($fp); }
        $this->travas = array();
    }

    private function erro($status, $mensagem)
    {
        $this->limpar();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        die(json_encode(array('ok' => false, 'erro' => $mensagem), JSON_UNESCAPED_UNICODE));
    }
}
