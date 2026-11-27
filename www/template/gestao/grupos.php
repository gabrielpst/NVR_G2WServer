<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<div class="titulo-com-acao">
    <h1 class="titulo-pagina">Grupos de câmeras</h1>
    <?php if ($ligado) { ?><button type="button" class="botao botao-principal" id="novo-grupo">Novo grupo</button><?php } ?>
</div>

<?php if (!$ligado) { ?>
<section class="bloco">
    <p class="intro">Os grupos de câmeras ainda não foram ligados nesta instalação. Hoje cada usuário vê todas as câmeras, menos as que foram bloqueadas para ele em Usuários.</p>
    <p class="intro">Ligar os grupos é feito pelo suporte, uma vez, sem mudar o que cada usuário vê no dia.</p>
</section>
<?php } else { ?>
<p class="intro">Cada usuário vê as câmeras dos grupos marcados no cadastro dele, mais as câmeras avulsas. Câmera nova só aparece para quem tem o grupo dela ou a recebe avulsa. Mudar um grupo vale na hora para todos os usuários dele.</p>

<script type="application/json" id="dados-grupos"><?php echo $json; ?></script>

<section class="bloco" aria-label="Grupos">
    <p class="vazio" id="sem-grupos" hidden>Nenhum grupo ainda. Use “Novo grupo”.</p>
    <ul class="lista-grupos" id="lista-grupos"></ul>
</section>

<dialog id="dlg-grupo" class="dialogo" aria-labelledby="dlg-grupo-t">
    <form method="dialog" data-form-grupo autocomplete="off" novalidate>
        <h2 id="dlg-grupo-t">Grupo</h2>
        <input type="hidden" name="id">
        <div class="campo">
            <label for="g-nome">Nome do grupo</label>
            <input id="g-nome" name="nome" type="text" required maxlength="<?php echo G2WGestao::GRUPO_NOME_MAX; ?>" autocomplete="off" placeholder="Ex.: Portarias, Área comum…">
        </div>
        <fieldset class="opcoes">
            <legend>Câmeras deste grupo</legend>
            <div class="lista-cameras" id="g-cameras"></div>
        </fieldset>
        <p class="nota" id="g-usuarios" aria-live="polite"></p>
        <p class="erro-form" role="alert" hidden></p>
        <div class="dialogo-acoes">
            <button type="button" class="botao botao-perigo" id="g-apagar" hidden>Apagar grupo</button>
            <button type="button" class="botao" data-fechar>Cancelar</button>
            <button type="submit" class="botao botao-principal">Salvar</button>
        </div>
    </form>
</dialog>
<?php } ?>
