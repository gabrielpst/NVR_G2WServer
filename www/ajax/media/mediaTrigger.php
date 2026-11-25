<?php

class mediaTrigger extends Controller {
	
    public function __construct()
    {
        parent::__construct();
		$this->chAccess('basic');
    }

    public function getData()
    {
        # Parameters validation
        if (!isset($_GET['camera_id']) || !is_numeric($_GET['camera_id'])) {
        	header('HTTP/ 400 Bad Request - numeric parameter camera_id is required');
        	die();
        }

        // G2W 2026-11: câmera liberada ao usuário, e texto de uma linha só
        // (antes qualquer usuário disparava evento em qualquer câmera).
        if (!$this->user->camPermission(intval($_GET['camera_id']))) {
        	header('HTTP/1.1 403 Forbidden');
        	die();
        }
        $descricao = isset($_GET['description']) && is_string($_GET['description']) ? $_GET['description'] : '';
        $descricao = substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $descricao)), 0, 200);

        # Form the message
        $msg = intval($_GET['camera_id']) . ' ' . $descricao;

        # Send via unix socket connection
        $socket = fsockopen('unix:///tmp/bluecherry_trigger', -1, $errno, $errstr);
        if (!$socket) {
        	header("HTTP/ 500 Server Error - failed to connect to local socket, reason $errstr ($errno)");
        	die();
        }
        fwrite($socket, $msg);
        $ret_str = fgets($socket);
        fclose($socket);

        # Form HTTP reply
        header('HTTP/ ' . $ret_str);
    }

}

