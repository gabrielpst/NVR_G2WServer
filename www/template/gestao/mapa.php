<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$no_mapa = array(); $sem = array();
foreach ($cams as $c) {
    if ($c['local']) {
        $no_mapa[] = array('id' => $c['id'], 'nome' => $c['nome'], 'latitude' => $c['local']['latitude'], 'longitude' => $c['local']['longitude'],
            'endereco' => $c['local']['endereco'], 'estado' => $c['estado']['rotulo'], 'nivel' => $c['estado']['nivel']);
    } else {
        $sem[] = $c;
    }
}
?>
<h1 class="titulo-pagina">Mapa</h1>
<p class="intro">Onde fica cada câmera. A posição é definida na aba <a href="/gestao/cameras">Câmeras</a> e aparece, no aplicativo, só para quem tem a câmera liberada.</p>

<script type="application/json" id="dados-mapa"><?php echo json_encode($no_mapa, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

<section class="bloco" aria-label="Mapa das câmeras">
    <?php if (!$no_mapa) { ?>
        <p class="vazio">Nenhuma câmera tem localização ainda. Abra a aba <a href="/gestao/cameras">Câmeras</a> e use “Definir” em “Localização no mapa”.</p>
    <?php } else { ?>
        <div class="mapa mapa-pagina" id="mapa-cameras" role="application" aria-label="Mapa com <?php echo count($no_mapa); ?> câmeras; a mesma lista está logo abaixo"></div>
        <div class="tabela-rolagem">
            <table class="tabela">
                <caption class="sr">Câmeras com localização</caption>
                <thead><tr><th scope="col">Câmera</th><th scope="col">Situação</th><th scope="col">Endereço</th><th scope="col"><span class="sr">Ações</span></th></tr></thead>
                <tbody>
                <?php foreach ($no_mapa as $c) { ?>
                    <tr>
                        <td data-rotulo="Câmera"><strong><?php echo $h($c['nome']); ?></strong></td>
                        <td data-rotulo="Situação"><span class="estado estado-<?php echo $h($c['nivel']); ?>"><span class="ponto" aria-hidden="true"></span><?php echo $h($c['estado']); ?></span></td>
                        <td data-rotulo="Endereço"><?php echo $c['endereco'] !== '' ? $h($c['endereco']) : '—'; ?></td>
                        <td class="col-acao">
                            <button type="button" class="botao botao-leve" data-ver-no-mapa="<?php echo $c['id']; ?>" aria-label="Mostrar <?php echo $h($c['nome']); ?> no mapa">Ver no mapa</button>
                            <a class="botao botao-leve" href="/gestao/cameras#local=<?php echo $c['id']; ?>" aria-label="Ajustar a localização de <?php echo $h($c['nome']); ?>">Ajustar</a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>

<?php if ($sem) { ?>
<section class="bloco" aria-labelledby="t-sem-local">
    <h2 id="t-sem-local">Sem localização (<?php echo count($sem); ?>)</h2>
    <ul class="lista-simples cartao">
        <?php foreach ($sem as $c) { ?>
            <li><strong><?php echo $h($c['nome']); ?></strong> <a href="/gestao/cameras#local=<?php echo $c['id']; ?>">Definir a localização<span class="sr"> de <?php echo $h($c['nome']); ?></span></a></li>
        <?php } ?>
    </ul>
</section>
<?php } ?>
