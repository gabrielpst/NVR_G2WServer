<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
?>
<h1 class="titulo-pagina">Gravações</h1>
<p class="intro">Escolha a câmera e o dia. Toque em um trecho da linha do tempo para assistir ou baixar.</p>

<section class="bloco" aria-label="Busca de gravações">
    <form class="cartao barra-busca-gravacoes" id="form-gravacoes" autocomplete="off">
        <div class="campo">
            <label for="g-camera">Câmera</label>
            <select id="g-camera" name="camera">
                <?php foreach ($cams as $c) { ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo $h($c['nome']); ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="campo">
            <label for="g-dia">Dia</label>
            <div class="dia-nav">
                <button type="button" class="botao" id="g-anterior" aria-label="Dia anterior">‹</button>
                <input id="g-dia" name="dia" type="date" required value="<?php echo $h($hoje); ?>" max="<?php echo $h($hoje); ?>">
                <button type="button" class="botao" id="g-proximo" aria-label="Próximo dia">›</button>
            </div>
        </div>
        <div class="campo">
            <label for="g-hora">Ir para a hora</label>
            <input id="g-hora" name="hora" type="time" step="60">
        </div>
    </form>

    <?php if (!$cams) { ?><p class="vazio">Nenhuma câmera cadastrada.</p><?php } ?>

    <div class="cartao" id="g-resultado" hidden>
        <p class="resumo-dia" id="g-resumo" aria-live="polite"></p>
        <div class="linha-tempo-caixa">
            <div class="linha-tempo" id="g-linha" role="group" aria-label="Linha do tempo do dia: trechos gravados"></div>
            <div class="linha-tempo-horas" aria-hidden="true">
                <span>0h</span><span>3h</span><span>6h</span><span>9h</span><span>12h</span><span>15h</span><span>18h</span><span>21h</span><span>24h</span>
            </div>
        </div>
        <p class="legenda"><span class="amostra amostra-gravado"></span> gravado <span class="amostra amostra-vazio"></span> sem gravação</p>
    </div>

    <div class="cartao reprodutor" id="g-reprodutor" hidden>
        <div class="cabecalho-bloco">
            <h2 id="g-titulo-trecho" tabindex="-1">Trecho</h2>
            <a class="botao botao-principal" id="g-baixar" href="#">Baixar este trecho</a>
        </div>
        <video id="g-video" controls preload="metadata" playsinline></video>
        <p class="nota">Se o vídeo não abrir no navegador (alguns formatos de câmera não tocam direto), baixe o trecho e abra no computador.</p>
    </div>

    <div class="cartao" id="g-lista-caixa" hidden>
        <h2>Trechos do dia</h2>
        <div class="tabela-rolagem">
            <table class="tabela tabela-trechos">
                <caption class="sr">Trechos gravados no dia escolhido</caption>
                <thead><tr><th scope="col">Início</th><th scope="col">Fim</th><th scope="col">Duração</th><th scope="col">Tamanho</th><th scope="col"><span class="sr">Ações</span></th></tr></thead>
                <tbody id="g-lista"></tbody>
            </table>
        </div>
    </div>
    <p class="vazio" id="g-vazio" hidden>Não há gravação desta câmera neste dia.</p>
    <p class="erro-form" id="g-erro" role="alert" hidden></p>
</section>
