<?php

class mediaHls extends Controller {
	
    public function __construct()
    {
        parent::__construct();
		$this->chAccess('basic');
    }

    public function getData()
    {
		$status = true;
		$message = '';

        if (!isset($_GET['id']) or !$this->user->camPermission($_GET['id'])) {
			$status = false;
			$message = str_replace('%ID%', $_GET['id'], MJPEG_NO_PERMISSION);
			$data = "no_permission";
		}

        $this_camera = data::getObject('Devices', 'id', $_GET['id']);
        if ($this_camera[0]['disabled'] == true){
			$status = false;
			$message = MJPEG_DISABLED;
			$data = "disabled";
        } elseif (!$this_camera){
			$status = false;
			$message = str_replace('%ID%', $_GET['id'], MJPEG_DEVICE_NOT_FOUND);
			$data = "not_found";
        }
        
        if (!isset($_GET['id'])) {
			$status = false;
			$message = MJPEG_DEVICE_ID_NOT_SUPPLIED;
			$data = "not_id";
		}

		if ($status === true) {

            // a chave do vídeo nasce aqui, antes de fechar a sessão (depois do
            // session_write_close() nada mais é gravado nela)
            $this->hlsTokenDaSessao();
            session_write_close();

		    if (!empty($_GET['tokenOnly'])) {
		        return json_encode([
		            'success' => true,
                    'token' => $this->createHlsToken()
                ]);
            }

			$id = intval($_GET['id']);
			$message = $this->createLink($id);
		}

		data::responseJSON($status, $message);
    }

    function createLink($id) {

        // Generate auth token for DB, insert into DB

        $hostname = $_GET['hostname'];
        $port = $_GET['port'];

        $token = $this->createHlsToken();
        return "https://$hostname:$port/hls/$id/0/playlist.m3u8?authtoken=$token";
    }

    /* G2W 2026-11: a chave do ao vivo era o próprio session_id() — quem visse o
     * endereço do vídeo (log, histórico) ganhava a sessão do painel. Agora é um
     * valor aleatório à parte, um por sessão: 40 caracteres [0-9a-f], que é o
     * que o motor, a trava do nginx e o vigia aceitam. Continua o mesmo
     * enquanto a sessão durar, então renovar não derruba quem está assistindo. */
    function hlsTokenDaSessao() {
        if (empty($_SESSION['g2w_hls_token']) || !preg_match('/^[0-9a-f]{40}$/', $_SESSION['g2w_hls_token'])) {
            $_SESSION['g2w_hls_token'] = bin2hex(random_bytes(20));
        }
        return $_SESSION['g2w_hls_token'];
    }

    function createHlsToken() {
        $token = $this->hlsTokenDaSessao();

        $user_id = $this->user->info['id'];
        data::query("INSERT INTO HlsAuthTokens (user_id, token, date) VALUES ($user_id, '$token', now()) ON DUPLICATE KEY UPDATE date = now()", true);
        // Cleanup old tokens from DB
        data::query("DELETE FROM HlsAuthTokens WHERE date < now() - INTERVAL 5 MINUTE", true);

        return $token;
    }
}


