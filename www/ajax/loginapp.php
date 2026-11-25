<?php

class loginapp extends Controller {

    const UUID_PARAM_NAME = 'G_SERVER_UUID';

    public function getData()
    {
        return json_encode([
            'success' => false,
            'message' => 'this request type is not allowed'
        ]);
    }

    public function postData()
    {
        // G2W 2026-11, "um aparelho por vez" (docs/api-sessao-unica.md): o
        // identificador do aparelho, quando vem, tem de estar no formato
        if (G2WGestao::aparelhoDoPedido() === false) {
            return json_encode([
                'success' => false,
                'codigo' => 'aparelho_invalido',
                'message' => 'Identificador do aparelho inválido.'
            ], JSON_UNESCAPED_UNICODE);
        }

        $user_to_login = new user('username', $_POST['login']);
        $result = $user_to_login->doLogin($_POST['password'], true);

        if ($result === 'OK') {
            return json_encode([
                'success' => true,
                'server_uuid' => $this->getServerUuid(),
                'sessao_unica' => (G2WGestao::sessaoUnicaLigada() && empty($user_to_login->info['access_setup'])),
                'aparelho_reconhecido' => is_string(G2WGestao::aparelhoDoPedido())
            ]);
        }

        return json_encode([
            'success' => false,
            'message' => $result
        ]);
    }

    protected function getServerUuid($generate = true)
    {
        $result = data::query('SELECT `value` FROM `GlobalSettings` WHERE `parameter` = \'' .
            self::UUID_PARAM_NAME . '\' LIMIT 1');

        if (!empty($result)) {
            return $result[0]['value'];
        }

        // to break infinite loop if mysql server do unexpected behaviour
        if (!$generate) {
            return false;
        }

        data::query('INSERT INTO `GlobalSettings`(`parameter`, `value`) VALUES(\'' .
            self::UUID_PARAM_NAME . '\', UUID())', true);

        return $this->getServerUuid(false);
    }

}


?>
