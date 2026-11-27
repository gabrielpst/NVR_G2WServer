<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$problemas = array(); $infos = array();
foreach ($avisos as $a) { if ($a[0] === 'info') $infos[] = $a; else $problemas[] = $a; }
$lista = function ($itens) use ($h) { ?>
        <ul class="avisos">
            <?php foreach ($itens as $a) { ?>
                <li class="aviso aviso-<?php echo $h($a[0]); ?>">
                    <span class="aviso-marca" aria-hidden="true"><?php echo $a[0] === 'info' ? 'i' : '!'; ?></span>
                    <div class="aviso-texto">
                        <strong><?php echo $h($a[1]); ?></strong>
                        <span><?php echo $h($a[2]); ?></span>
                    </div>
                    <?php if ($a[3] !== '') { ?><a class="botao botao-leve" href="<?php echo $h($a[3]); ?>">Ver<span class="sr"> <?php echo $h($a[1]); ?></span></a><?php } ?>
                </li>
            <?php } ?>
        </ul>
<?php };
?>
<div id="visao-geral" data-atualizar="60">

<h1 class="titulo-pagina">Avisos</h1>

<section aria-labelledby="t-atencao" class="bloco">
    <h2 id="t-atencao"><?php echo !$problemas ? 'Nada precisa de atenção' : (count($problemas) == 1 ? '1 ponto precisa de atenção' : count($problemas).' pontos precisam de atenção'); ?></h2>
    <?php if ($problemas) { $lista($problemas); } else { ?>
        <p class="intro">Câmeras, disco, certificado e motor de gravação sem alertas.</p>
    <?php } ?>
</section>

<?php if ($infos) { ?>
<section aria-labelledby="t-info" class="bloco">
    <h2 id="t-info">Para saber</h2>
    <p class="intro">Não é problema; fica aqui para consulta.</p>
    <?php $lista($infos); ?>
</section>
<?php } ?>

</div>
