<?php

/*
 * G2W Monitor — marca por instalação (2026-11).
 *
 * Cada cliente roda a mesma imagem na sua LXC. O nome que aparece nas telas
 * pode ser trocado por instalação com um arquivo opcional, fora do www e
 * montado pelo compose:
 *
 *   /etc/g2w/marca.json
 *   { "nome": "Ksolucoes", "subtitulo": "...", "rodape": "...", "icone": "/img/arquivo.png" }
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
    const ARQUIVO = '/etc/g2w/marca.json';
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
            'rodape' => self::RODAPE_PADRAO, 'icone' => self::ICONE_PADRAO, 'propria' => false);
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
            }
        }
        return self::$dados = $d;
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

    /* true quando a instalação definiu um nome diferente do padrão */
    public static function propria() { $d = self::ler(); return $d['propria']; }

    public static function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
