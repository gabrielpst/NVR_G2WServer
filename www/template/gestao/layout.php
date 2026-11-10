<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$abas = array(
    'visao' => array('/gestao', 'Visão geral'),
    'cameras' => array('/gestao/cameras', 'Câmeras'),
    'usuarios' => array('/gestao/usuarios', 'Usuários'),
    'gravacoes' => array('/gestao/gravacoes', 'Gravações'),
);
$v_css = @filemtime(SITE_PATH.'template/dist/css/gestao.css');
$v_js = @filemtime(SITE_PATH.'template/dist/js/gestao.js');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#FFFFFF">
    <meta name="g2w-csrf" content="<?php echo $h($csrf); ?>">
    <link rel="icon" href="/favicon.ico">
    <title><?php echo $h($titulo); ?> · G2W Monitor</title>
    <link href="/template/dist/css/gestao.css?v=<?php echo intval($v_css); ?>" rel="stylesheet">
</head>
<body data-aba="<?php echo $h($aba); ?>">
    <a class="pular" href="#conteudo">Pular para o conteúdo</a>
    <header class="topo">
        <div class="topo-linha">
            <a class="marca" href="/gestao" aria-label="G2W Monitor — visão geral" translate="no">
                <img src="/img/logo.png" alt="G2W Monitor" width="109" height="46" fetchpriority="high">
            </a>
            <div class="topo-acoes">
                <a class="link-topo" href="/aovivo">Ao vivo</a>
                <a class="link-topo" href="/monitor" title="Painel completo, com todas as configurações">Modo avançado</a>
                <span class="quem" title="Usuário conectado"><?php echo $h($usuario); ?></span>
                <a class="link-topo" href="/logout">Sair</a>
            </div>
        </div>
        <nav class="abas" aria-label="Seções do painel">
            <?php foreach ($abas as $k => $a) { ?>
                <a href="<?php echo $a[0]; ?>"<?php echo ($k === $aba) ? ' class="ativa" aria-current="page"' : ''; ?>><?php echo $a[1]; ?></a>
            <?php } ?>
        </nav>
    </header>

    <main id="conteudo" tabindex="-1">
        <?php echo $conteudo; ?>
    </main>

    <footer class="rodape">
        <span>G2W Monitor · atualizado às <time id="hora-pagina" datetime="<?php echo date('c', $agora); ?>"><?php echo date('H:i', $agora); ?></time></span>
        <a href="/monitor">Abrir o modo avançado</a>
    </footer>

    <dialog id="dlg-confirma" class="dialogo dialogo-pequeno" aria-labelledby="dlg-confirma-t">
        <form method="dialog">
            <h2 id="dlg-confirma-t">Confirmar</h2>
            <p data-texto></p>
            <div class="dialogo-acoes">
                <button type="button" class="botao" data-fechar>Cancelar</button>
                <button type="submit" class="botao botao-principal" data-ok>Confirmar</button>
            </div>
        </form>
    </dialog>

    <div id="avisos-flutuantes" class="toasts" role="status" aria-live="polite"></div>
    <script src="/template/dist/js/gestao.js?v=<?php echo intval($v_js); ?>" defer></script>
</body>
</html>
