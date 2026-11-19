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
    <link rel="apple-touch-icon" href="/img/icone-g2w.png">
    <title>Entrar · G2W Monitor</title>
    <link href="/template/dist/css/entrar.css?v=<?php echo $g2w_v; ?>" rel="stylesheet">
</head>
<body>
    <main class="entrar">
        <div class="entrar-marca">
            <img src="/img/icone-g2w.png" alt="" width="76" height="76">
            <h1>G2W <span>Monitor</span></h1>
            <p>Entre para ver as câmeras</p>
        </div>

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

    <p class="entrar-rodape">G2W Tecnologia</p>

    <script src="/template/dist/js/entrar.js?v=<?php echo $g2w_v; ?>"></script>
</body>
</html>
