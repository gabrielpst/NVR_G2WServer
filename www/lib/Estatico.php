<?php
/* G2W 2026-11: versão dos arquivos estáticos do painel (?v=).
 *
 * Era a data do arquivo (filemtime). Dentro da imagem Docker todo arquivo do
 * .deb sai com a mesma data (a do debian/changelog, via SOURCE_DATE_EPOCH do
 * debhelper), em qualquer build: o ?v= não mudava entre imagens e o navegador
 * seguia com o CSS/JS antigo em cache. Agora é um resumo do conteúdo, que
 * muda quando o arquivo muda, seja qual for a data.
 *
 * $rel é relativo à raiz do www (ex.: 'template/dist/css/gestao.css').
 * Arquivo que não existe devolve '0' (como antes). */
if (!function_exists('g2w_v')) {
    function g2w_v($rel)
    {
        static $memo = array();
        $rel = ltrim((string)$rel, '/');
        if (isset($memo[$rel])) return $memo[$rel];
        $arq = dirname(__DIR__).'/'.$rel;
        $h = is_file($arq) ? @md5_file($arq) : false;
        return $memo[$rel] = ($h ? substr($h, 0, 12) : '0');
    }
}
