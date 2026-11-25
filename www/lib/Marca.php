<?php

/*
 * G2W Monitor — marca por instalação (2026-11).
 *
 * Cada cliente roda a mesma imagem na sua LXC. O nome que aparece nas telas
 * pode ser trocado por instalação com um arquivo opcional, fora do www e
 * montado pelo compose:
 *
 *   /etc/g2w/marca.json
 *   { "nome": "K Soluções", "logo": "logo.png", "subtitulo": "...", "rodape": "...", "icone": "/img/arquivo.png" }
 *
 * "logo" é o nome de um arquivo de imagem (PNG, JPG ou SVG) na mesma pasta
 * /etc/g2w/, servido em /marca/logo (ajax/marca.php). Com logo, as telas
 * mostram a imagem no lugar do ícone e do nome em texto.
 *
 * Todos os campos são opcionais. Sem o arquivo, com JSON inválido ou com um
 * campo fora do formato, vale o padrão daquele campo — as telas saem como
 * sempre saíram. O conteúdo é tratado como dado: tamanho limitado aqui e
 * escape na saída (use G2WMarca::h()).
 *
 * Não muda: o que o aplicativo lê (realm do Basic, mensagens, nome do arquivo
 * de trecho) e o painel antigo em /monitor.
 */
class G2WMarca
{
    const PASTA = '/etc/g2w/';
    const ARQUIVO = '/etc/g2w/marca.json';
    const LOGO_MAX = 524288; // 512 KB
    const ARQUIVO_MAX = 4096;
    const NOME_PADRAO = 'G2W Monitor';
    const SUBTITULO_PADRAO = 'Entre para ver as câmeras';
    const RODAPE_PADRAO = 'G2W Tecnologia';
    const ICONE_PADRAO = '/img/icone-g2w.png';

    private static $dados = null;

    private static function ler()
    {
        if (self::$dados !== null) return self::$dados;
        $d = array('nome' => self::NOME_PADRAO, 'subtitulo' => self::SUBTITULO_PADRAO,
            'rodape' => self::RODAPE_PADRAO, 'icone' => self::ICONE_PADRAO, 'propria' => false, 'logo' => null);
        $arq = self::ARQUIVO;
        if (@is_file($arq) && @is_readable($arq) && @filesize($arq) <= self::ARQUIVO_MAX) {
            $j = json_decode((string)@file_get_contents($arq, false, null, 0, self::ARQUIVO_MAX), true);
            if (is_array($j)) {
                foreach (array('nome' => 40, 'subtitulo' => 80, 'rodape' => 60) as $campo => $max) {
                    $v = self::texto(isset($j[$campo]) ? $j[$campo] : null, $max);
                    if ($v !== null) {
                        $d[$campo] = $v;
                        if ($campo === 'nome') $d['propria'] = ($v !== self::NOME_PADRAO);
                    }
                }
                // ícone: só arquivo de imagem dentro de /img do próprio servidor
                if (isset($j['icone']) && is_string($j['icone'])
                    && preg_match('#^/img/[A-Za-z0-9][A-Za-z0-9._-]{0,60}\.(png|jpg|jpeg|webp|svg)$#', $j['icone'])
                    && strpos($j['icone'], '..') === false) {
                    $d['icone'] = $j['icone'];
                }
                if (isset($j['logo']) && is_string($j['logo'])) $d['logo'] = self::conferirLogo($j['logo']);
            }
        }
        return self::$dados = $d;
    }

    /* O nome vem do arquivo de marca, nunca de um pedido. Só nome simples de
     * arquivo, dentro de /etc/g2w/, com tamanho limitado, e o tipo é decidido
     * pelo conteúdo, não pela extensão. Devolve null se algo não confere. */
    private static function conferirLogo($nome)
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,60}$/', $nome) || strpos($nome, '..') !== false) return null;
        $arq = @realpath(self::PASTA.$nome);
        if ($arq === false || strncmp($arq, self::PASTA, strlen(self::PASTA)) !== 0 || !@is_file($arq) || !@is_readable($arq)) return null;
        $tam = @filesize($arq);
        if (!$tam || $tam > self::LOGO_MAX) return null;
        $ini = (string)@file_get_contents($arq, false, null, 0, 1024);
        $larg = $alt = null;
        if (strncmp($ini, "\x89PNG\r\n\x1a\n", 8) === 0) $tipo = 'image/png';
        elseif (strncmp($ini, "\xFF\xD8\xFF", 3) === 0) $tipo = 'image/jpeg';
        elseif (preg_match('/^(\xEF\xBB\xBF)?\s*(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $ini)) {
            // SVG pode carregar script; como <img> ele não roda, e a rota ainda
            // responde com uma política que proíbe. Mesmo assim, recusa o óbvio.
            $todo = (string)@file_get_contents($arq, false, null, 0, self::LOGO_MAX);
            if (preg_match('/<script|<foreignObject|\son[a-z]+\s*=|javascript:/i', $todo)) return null;
            $tipo = 'image/svg+xml';
        } else return null;
        if ($tipo !== 'image/svg+xml') {
            $dim = @getimagesize($arq);
            if (!$dim || $dim[0] < 1 || $dim[1] < 1 || $dim[0] > 4000 || $dim[1] > 4000) return null;
            $larg = intval($dim[0]); $alt = intval($dim[1]);
        }
        return array('arquivo' => $arq, 'tipo' => $tipo, 'bytes' => intval($tam), 'largura' => $larg, 'altura' => $alt,
            'url' => '/marca/logo?v='.intval(@filemtime($arq)));
    }

    /* Texto de uma linha, sem caracteres de controle, até $max caracteres. */
    private static function texto($v, $max)
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) return null;
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
        if ($v === '' || mb_strlen($v, 'UTF-8') > $max) return null;
        return $v;
    }

    public static function nome() { $d = self::ler(); return $d['nome']; }
    public static function subtitulo() { $d = self::ler(); return $d['subtitulo']; }
    public static function rodape() { $d = self::ler(); return $d['rodape']; }
    public static function icone() { $d = self::ler(); return $d['icone']; }

    /* null, ou os dados da logo já conferida (arquivo, tipo, largura, altura, url) */
    public static function logo() { $d = self::ler(); return $d['logo']; }

    /* <img> da logo cabendo em $maxL x $maxA, com largura e altura declaradas
     * quando conhecidas (PNG/JPG); o alt é o nome da instalação. */
    public static function logoImg($maxL, $maxA, $classe)
    {
        $l = self::logo();
        if (!$l) return '';
        $dim = '';
        if ($l['largura'] && $l['altura']) {
            $f = min($maxL / $l['largura'], $maxA / $l['altura'], 1);
            $dim = ' width="'.max(1, intval(round($l['largura'] * $f))).'" height="'.max(1, intval(round($l['altura'] * $f))).'"';
        }
        return '<img class="'.self::h($classe).'" src="'.self::h($l['url']).'" alt="'.self::h(self::nome()).'"'.$dim.'>';
    }

    /* true quando a instalação definiu um nome diferente do padrão */
    public static function propria() { $d = self::ler(); return $d['propria']; }

    public static function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
