<?php

/*
 * G2W Monitor — GET /media/sessao (2026-11, "um aparelho por vez").
 *
 * Pergunta barata para o aplicativo saber se ainda é o aparelho da vez, com
 * cookie de sessão ou com Basic + X-G2W-Aparelho (docs/api-sessao-unica.md).
 * Quem não é o dono nem chega aqui: recebe o 401 "sessao_substituida" na
 * conferência de acesso. Não devolve dado nenhum além do estado.
 */
class mediaSessao extends Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->chAccess('basic');
    }

    public function getData()
    {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        die(json_encode(array(
            'ok' => true,
            'sessao_unica' => (G2WGestao::sessaoUnicaLigada() && empty($this->user->info['access_setup'])),
            'aparelho_reconhecido' => is_string(G2WGestao::aparelhoDoPedido()),
        )));
    }

    public function postData()
    {
        http_response_code(405);
        die();
    }
}
