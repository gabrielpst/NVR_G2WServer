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
    <title>Nova senha · G2W Monitor</title>
    <link href="/template/dist/css/entrar.css?v=<?php echo $g2w_v; ?>" rel="stylesheet">
</head>
<body>
    <main class="entrar">
        <div class="entrar-marca">
            <img src="/img/icone-g2w.png" alt="" width="76" height="76">
            <h1>Crie sua senha</h1>
            <p>No primeiro acesso é preciso trocar a senha recebida</p>
        </div>

        <form method="post" action="/changepwd" data-entrar novalidate>
            <div class="campo">
                <label for="password">Nova senha</label>
                <div class="campo-senha">
                    <input id="password" name="password" type="password" placeholder=" "
                           autocomplete="new-password" required autofocus data-vazio="Informe a nova senha.">
                    <button type="button" class="ver-senha" aria-controls="password" aria-pressed="false">Mostrar</button>
                </div>
            </div>
            <div class="campo">
                <label for="confirm_password">Repita a nova senha</label>
                <div class="campo-senha">
                    <input id="confirm_password" name="confirm_password" type="password" placeholder=" "
                           autocomplete="new-password" required data-vazio="Repita a nova senha.">
                    <button type="button" class="ver-senha" aria-controls="confirm_password" aria-pressed="false">Mostrar</button>
                </div>
            </div>

            <p class="erro-form" role="alert" hidden></p>

            <button type="submit" class="botao-entrar" data-ocupado="Salvando…">Salvar e entrar</button>
        </form>
    </main>

    <p class="entrar-rodape">G2W Tecnologia</p>

    <script src="/template/dist/js/entrar.js?v=<?php echo $g2w_v; ?>"></script>
</body>
</html>
