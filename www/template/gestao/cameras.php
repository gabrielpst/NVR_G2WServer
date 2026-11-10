<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$rotulo_padrao = array('sempre' => 'grava sempre', 'nunca' => 'não grava', 'personalizada' => 'por agenda');
?>
<h1 class="titulo-pagina">Câmeras</h1>
<p class="intro">Mude o nome, por quantos dias guardar e se a câmera grava. Para adicionar ou remover câmeras e ajustes finos de imagem, use o <a href="/monitor">modo avançado</a>.</p>

<section class="bloco" aria-label="Lista de câmeras">
    <?php if (!$cams) { ?><p class="vazio">Nenhuma câmera cadastrada.</p><?php } ?>
    <?php foreach ($cams as $c) { $f = 'cam'.$c['id']; ?>
    <form class="cartao linha-camera" data-form-camera data-id="<?php echo $c['id']; ?>" autocomplete="off" novalidate>
        <div class="linha-camera-imagem">
            <?php if ($c['imagem_ts']) { ?>
                <img src="/gestao/miniatura?id=<?php echo $c['id']; ?>&amp;t=<?php echo intval($c['imagem_ts']); ?>" alt="" loading="lazy" width="640" height="360">
            <?php } else { ?><span class="sem-imagem">Sem imagem</span><?php } ?>
            <p class="estado estado-<?php echo $h($c['estado']['nivel']); ?>"><span class="ponto" aria-hidden="true"></span><?php echo $h($c['estado']['rotulo']); ?></p>
        </div>
        <div class="linha-camera-campos">
            <div class="campo">
                <label for="<?php echo $f; ?>-nome">Nome</label>
                <input id="<?php echo $f; ?>-nome" name="nome" type="text" required maxlength="100" value="<?php echo $h($c['nome']); ?>">
            </div>
            <div class="campo">
                <label for="<?php echo $f; ?>-ret">Guardar gravações por</label>
                <div class="com-sufixo">
                    <input id="<?php echo $f; ?>-ret" name="retencao_dias" type="number" inputmode="numeric" min="1" max="3650" step="1"
                        value="<?php echo $c['retencao_dias'] === null ? '' : intval($c['retencao_dias']); ?>" placeholder="sem limite" aria-describedby="<?php echo $f; ?>-ret-ajuda">
                    <span>dias</span>
                </div>
                <small id="<?php echo $f; ?>-ret-ajuda">Em branco: guarda até o disco encher. Hoje há <?php
                    echo $c['mais_antiga'] ? ($c['dias_guardados'] < 1 ? 'menos de 1 dia' : $c['dias_guardados'].' dia'.($c['dias_guardados'] > 1 ? 's' : '')).', '.G2WGestao::tamanho($c['bytes']) : 'nada gravado'; ?>.</small>
            </div>
            <div class="campo">
                <label for="<?php echo $f; ?>-grav">Gravação</label>
                <select id="<?php echo $f; ?>-grav" name="gravacao">
                    <option value="padrao"<?php echo $c['gravacao'] === 'padrao' ? ' selected' : ''; ?>>Padrão (<?php echo $rotulo_padrao[$c['padrao_servidor']]; ?>)</option>
                    <option value="sempre"<?php echo $c['gravacao'] === 'sempre' ? ' selected' : ''; ?>>Gravar o tempo todo</option>
                    <option value="nunca"<?php echo $c['gravacao'] === 'nunca' ? ' selected' : ''; ?>>Não gravar (só ao vivo)</option>
                    <?php if ($c['gravacao'] === 'personalizada') { ?>
                        <option value="manter" selected>Agenda própria (modo avançado)</option>
                    <?php } ?>
                </select>
            </div>
            <div class="campo campo-largo">
                <label for="<?php echo $f; ?>-fonte">Endereço da câmera</label>
                <?php if ($c['fonte_editavel']) { ?>
                    <input id="<?php echo $f; ?>-fonte" name="fonte" type="text" inputmode="url" spellcheck="false" autocapitalize="off" maxlength="400"
                        value="<?php echo $h($c['fonte']); ?>" aria-describedby="<?php echo $f; ?>-fonte-ajuda">
                    <small id="<?php echo $f; ?>-fonte-ajuda"><?php echo $c['tem_credencial']
                        ? 'Usuário e senha da câmera estão salvos e não aparecem aqui. Para trocá-los, escreva rtsp://usuario:senha@endereço.'
                        : 'Formato: rtsp://endereço:porta/caminho.'; ?></small>
                <?php } else { ?>
                    <input id="<?php echo $f; ?>-fonte" type="text" value="<?php echo $h($c['fonte']); ?>" readonly>
                    <small>Este tipo de câmera só é alterado no modo avançado.</small>
                <?php } ?>
            </div>
        </div>
        <div class="linha-camera-acoes">
            <button type="submit" class="botao botao-principal" disabled>Salvar</button>
            <a class="botao botao-leve" href="/gestao/gravacoes#camera=<?php echo $c['id']; ?>">Gravações</a>
        </div>
    </form>
    <?php } ?>
</section>

<section class="bloco" id="armazenamento" aria-labelledby="t-arm">
    <h2 id="t-arm">Armazenamento</h2>
    <p class="intro">Quando o disco chega ao limite, o sistema apaga sozinho as gravações mais antigas para continuar gravando.</p>
    <?php foreach ($discos as $d) { $f = 'disco'.$d['prioridade']; ?>
    <form class="cartao" data-form-disco data-prioridade="<?php echo $d['prioridade']; ?>" autocomplete="off" novalidate>
        <?php if ($d['ok']) { ?>
            <p class="disco-uso"><strong><?php echo number_format($d['uso_pct'], 0, ',', '.'); ?>% usado</strong>
                — <?php echo G2WGestao::tamanho($d['livre']); ?> livres de <?php echo G2WGestao::tamanho($d['total']); ?></p>
            <span class="barra barra-grande" role="img" aria-label="<?php echo number_format($d['uso_pct'], 0); ?>% do disco em uso">
                <span class="barra-uso <?php echo $d['uso_pct'] >= 98 ? 'barra-erro' : ''; ?>" style="width: <?php echo min(100, $d['uso_pct']); ?>%"></span>
                <span class="barra-limite" style="left: <?php echo $d['limite_max']; ?>%"></span>
            </span>
        <?php } else { ?>
            <p class="texto-alerta">Não foi possível ler este disco.</p>
        <?php } ?>
        <div class="campos-linha">
            <div class="campo">
                <label for="<?php echo $f; ?>-max">Começar a apagar as mais antigas com</label>
                <div class="com-sufixo"><input id="<?php echo $f; ?>-max" name="limite_max" type="number" inputmode="numeric" min="50" max="99" step="1" required value="<?php echo intval($d['limite_max']); ?>"><span>% do disco</span></div>
            </div>
            <div class="campo">
                <label for="<?php echo $f; ?>-min">Parar de apagar ao voltar para</label>
                <div class="com-sufixo"><input id="<?php echo $f; ?>-min" name="limite_min" type="number" inputmode="numeric" min="40" max="98" step="1" required value="<?php echo intval($d['limite_min']); ?>"><span>% do disco</span></div>
            </div>
            <button type="submit" class="botao botao-principal" disabled>Salvar limites</button>
        </div>
        <small>Pasta de gravação: <?php echo $h($d['caminho']); ?>. Em discos grandes, limites mais baixos (por exemplo 80% e 75%) deixam o sistema mais rápido.</small>
    </form>
    <?php } ?>
</section>
