<?php

/*
 * G2W Monitor — logo da instalação (2026-11): /marca/logo
 *
 * Serve a imagem configurada em /etc/g2w/marca.json ("logo"), que fica fora
 * do www. Sem login, porque a tela de entrada usa. Não há parâmetro que
 * escolha arquivo: o caminho vem só do arquivo de marca e é conferido em
 * lib/Marca.php (pasta, tamanho, tipo pelo conteúdo). O "?v=" é só para o
 * navegador buscar de novo quando a imagem muda.
 */
class marca extends Controller
{
    public $uri;

    public function getData()
    {
        $this->nada();
    }

    public function postData()
    {
        http_response_code(405);
        exit;
    }

    public function getLogo()
    {
        $l = G2WMarca::logo();
        if (!$l) $this->nada();
        session_write_close();
        while (ob_get_level() > 0) ob_end_clean();
        header_remove('Pragma');
        header_remove('Expires');
        header_remove('Set-Cookie'); // imagem pública: não distribui cookie de sessão
        header('Content-Type: '.$l['tipo']);
        header('Content-Length: '.$l['bytes']);
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        // um SVG aberto direto no navegador não roda script nem busca nada
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        readfile($l['arquivo']);
        exit;
    }

    private function nada()
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        die('Sem logo configurada.');
    }
}
