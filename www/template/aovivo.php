<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$v = function ($arq) { return intval(@filemtime(SITE_PATH.$arq)); };
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="icon" href="/favicon.ico">
    <title>Ao vivo · G2W Monitor</title>
    <link href="/template/dist/css/gestao.css?v=<?php echo $v('template/dist/css/gestao.css'); ?>" rel="stylesheet">
    <link href="/template/dist/css/aovivo.css?v=<?php echo $v('template/dist/css/aovivo.css'); ?>" rel="stylesheet">
</head>
<body data-aba="aovivo">
    <a class="pular" href="#conteudo">Pular para o conteúdo</a>
    <header class="topo">
        <div class="topo-linha">
            <a class="marca" href="/aovivo" aria-label="G2W Monitor — ao vivo" translate="no">
                <img src="/img/logo.png" alt="G2W Monitor" width="109" height="46" fetchpriority="high">
            </a>
            <div class="topo-acoes">
                <?php if ($admin) { ?><a class="link-topo" href="/gestao">Painel</a><?php } ?>
                <?php if ($gravacoes) { ?><a class="link-topo" href="<?php echo $admin ? '/gestao/gravacoes' : '/playback'; ?>">Gravações</a><?php } ?>
                <span class="quem" title="Usuário conectado"><?php echo $h($usuario); ?></span>
                <a class="link-topo" href="/logout">Sair</a>
            </div>
        </div>
    </header>

    <main id="conteudo" tabindex="-1" class="aovivo">
        <script type="application/json" id="dados-aovivo"><?php echo $json; ?></script>

        <div class="av-barra">
            <h1 class="titulo-pagina">Ao vivo</h1>
            <div class="av-controles">
                <div class="av-quantas" role="group" aria-label="Câmeras por tela">
                    <span class="av-rotulo" aria-hidden="true">Câmeras por tela</span>
                    <button type="button" class="filtro" data-quantas="todas" aria-pressed="true">Todas</button>
                    <button type="button" class="filtro" data-quantas="1" aria-pressed="false">1</button>
                    <button type="button" class="filtro" data-quantas="2" aria-pressed="false">2</button>
                    <button type="button" class="filtro" data-quantas="4" aria-pressed="false">4</button>
                    <button type="button" class="filtro" data-quantas="6" aria-pressed="false">6</button>
                    <button type="button" class="filtro" data-quantas="9" aria-pressed="false">9</button>
                </div>
                <button type="button" class="botao" id="av-tela-cheia">Tela cheia</button>
            </div>
        </div>

        <p class="faixa-velha" id="av-aviso" role="alert" hidden></p>
        <p class="vazio" id="av-sem-cameras" hidden>Nenhuma câmera está liberada para o seu usuário. Fale com o administrador.</p>

        <div class="av-grade" id="av-grade" data-quantas="todas"></div>

        <p class="nota" id="av-nota">Clique num quadro para trocar a câmera dele. A imagem ao vivo chega com 10 a 20 segundos de atraso.</p>

        <dialog id="av-escolher" class="dialogo dialogo-pequeno" aria-labelledby="av-escolher-t">
            <form method="dialog">
                <h2 id="av-escolher-t">Qual câmera neste quadro?</h2>
                <div class="av-lista" id="av-lista"></div>
                <div class="dialogo-acoes">
                    <button type="button" class="botao" data-fechar>Cancelar</button>
                </div>
            </form>
        </dialog>
    </main>


    <div id="avisos-flutuantes" class="toasts" role="status" aria-live="polite"></div>
    <script src="/template/dist/js/vendor/hls-1.6.13.min.js?v=<?php echo $v('template/dist/js/vendor/hls-1.6.13.min.js'); ?>" defer></script>
    <script src="/template/dist/js/aovivo.js?v=<?php echo $v('template/dist/js/aovivo.js'); ?>" defer></script>
</body>
</html>
