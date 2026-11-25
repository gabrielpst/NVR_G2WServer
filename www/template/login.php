<?php defined('INDVR') or exit();
$g2w_v = @filemtime(__DIR__.'/dist/css/entrar.css').'-'.@filemtime(__DIR__.'/dist/js/entrar.js');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0A1F1A">
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" href="<?php echo G2WMarca::h(G2WMarca::icone()); ?>">
    <title>Entrar · <?php echo G2WMarca::h(G2WMarca::nome()); ?></title>
    <link href="/template/dist/css/entrar.css?v=<?php echo $g2w_v; ?>" rel="stylesheet">
</head>
<body>
    <main class="entrar">
        <div class="entrar-marca">
<?php if (G2WMarca::logo()) { ?>
            <h1 class="entrar-logo"><?php echo G2WMarca::logoImg(260, 96, 'logo-marca'); ?></h1>
<?php } else { ?>
            <img src="<?php echo G2WMarca::h(G2WMarca::icone()); ?>" alt="" width="76" height="76">
            <h1><?php if (G2WMarca::propria()) { echo G2WMarca::h(G2WMarca::nome()); } else { ?>G2W <span>Monitor</span><?php } ?></h1>
<?php } ?>
            <p><?php echo G2WMarca::h(G2WMarca::subtitulo()); ?></p>
        </div>

<?php if (isset($_GET['motivo']) && $_GET['motivo'] === 'outro-aparelho') { ?>
        <p class="aviso-entrada" role="status">Sua conta foi acessada em outro aparelho. Entre de novo para continuar neste.</p>
<?php } ?>
        <form method="post" action="/login" data-entrar novalidate>
            <div class="campo">
                <label for="login">Usuário</label>
                <input id="login" name="login" type="text" placeholder=" " autocomplete="username"
                       autocapitalize="none" autocorrect="off" spellcheck="false" required autofocus
                       data-vazio="Informe o usuário.">
            </div>
            <div class="campo">
                <label for="password">Senha</label>
                <div class="campo-senha">
                    <input id="password" name="password" type="password" placeholder=" "
                           autocomplete="current-password" required data-vazio="Informe a senha.">
                    <button type="button" class="ver-senha" aria-controls="password" aria-pressed="false">Mostrar</button>
                </div>
            </div>

            <p class="erro-form" role="alert" hidden></p>

            <button type="submit" class="botao-entrar" data-ocupado="Entrando…">Entrar</button>
        </form>
    </main>

    <p class="entrar-rodape"><?php echo G2WMarca::h(G2WMarca::rodape()); ?></p>

    <script src="/template/dist/js/entrar.js?v=<?php echo $g2w_v; ?>"></script>
</body>
</html>
