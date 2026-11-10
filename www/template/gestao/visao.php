<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$gravando = 0; $ativas = 0;
foreach ($cams as $c) {
    if (!$c['desativada']) $ativas++;
    if ($c['estado']['nivel'] === 'ok') $gravando++;
}
$problemas = 0;
foreach ($avisos as $a) { if ($a[0] !== 'info') $problemas++; }
$disco = $discos ? $discos[0] : null;
?>
<div id="visao-geral" data-atualizar="60">

<h1 class="titulo-pagina">Visão geral</h1>

<section aria-labelledby="t-avisos" class="bloco">
    <h2 id="t-avisos" class="sr">Situação</h2>
    <?php if (!$problemas) { ?>
        <div class="situacao situacao-ok">
            <span class="situacao-icone" aria-hidden="true">✓</span>
            <div>
                <strong>Tudo funcionando</strong>
                <span><?php echo $gravando; ?> de <?php echo $ativas; ?> câmeras gravando, disco e sistema sem alertas.</span>
            </div>
        </div>
    <?php } else { ?>
        <div class="situacao situacao-erro">
            <span class="situacao-icone" aria-hidden="true">!</span>
            <div>
                <strong><?php echo $problemas == 1 ? '1 ponto precisa de atenção' : $problemas.' pontos precisam de atenção'; ?></strong>
                <span>Veja abaixo o que está acontecendo.</span>
            </div>
        </div>
    <?php } ?>
    <?php if ($avisos) { ?>
        <ul class="avisos">
            <?php foreach ($avisos as $a) { ?>
                <li class="aviso aviso-<?php echo $h($a[0]); ?>">
                    <span class="aviso-marca" aria-hidden="true"><?php echo $a[0] === 'info' ? 'i' : '!'; ?></span>
                    <div class="aviso-texto">
                        <strong><?php echo $h($a[1]); ?></strong>
                        <span><?php echo $h($a[2]); ?></span>
                    </div>
                    <?php if ($a[3] !== '') { ?><a class="botao botao-leve" href="<?php echo $h($a[3]); ?>">Ver</a><?php } ?>
                </li>
            <?php } ?>
        </ul>
    <?php } ?>
</section>

<section class="bloco resumo" aria-label="Resumo">
    <a class="cartao cartao-resumo" href="/gestao/cameras">
        <span class="rotulo">Câmeras</span>
        <span class="numero"><?php echo $gravando; ?><small> de <?php echo $ativas; ?></small></span>
        <span class="apoio">gravando agora</span>
    </a>
    <a class="cartao cartao-resumo" href="/gestao/cameras#armazenamento">
        <span class="rotulo">Disco de gravação</span>
        <?php if ($disco && $disco['ok']) { ?>
            <span class="numero"><?php echo number_format($disco['uso_pct'], 0, ',', '.'); ?><small>% usado</small></span>
            <span class="barra" role="img" aria-label="<?php echo number_format($disco['uso_pct'], 0); ?>% do disco em uso">
                <span class="barra-uso <?php echo $disco['uso_pct'] >= 98 ? 'barra-erro' : ''; ?>" style="width: <?php echo min(100, $disco['uso_pct']); ?>%"></span>
                <span class="barra-limite" style="left: <?php echo $disco['limite_max']; ?>%" title="Limite configurado"></span>
            </span>
            <span class="apoio">
                <?php echo G2WGestao::tamanho($disco['livre']); ?> livres de <?php echo G2WGestao::tamanho($disco['total']); ?>.
                <?php if ($disco['no_limite']) { ?>
                    No limite de <?php echo number_format($disco['limite_max'], 0); ?>%: as gravações mais antigas vão sendo apagadas.
                <?php } elseif ($disco['dias_ate_limite'] !== null) { ?>
                    Chega ao limite em cerca de <?php echo $disco['dias_ate_limite'] < 1 ? 'menos de 1 dia' : number_format($disco['dias_ate_limite'], 0, ',', '.').' dias'; ?>.
                <?php } ?>
            </span>
        <?php } else { ?>
            <span class="numero">—</span><span class="apoio">Não foi possível ler o disco.</span>
        <?php } ?>
    </a>
    <a class="cartao cartao-resumo" href="/gestao/usuarios">
        <span class="rotulo">Usuários</span>
        <span class="numero"><?php echo $resumo['total']; ?></span>
        <span class="apoio">
            <?php echo $resumo['online']; ?> conectado(s) agora<?php
            if ($resumo['trocar_senha']) echo ' · '.$resumo['trocar_senha'].' sem senha própria';
            if ($resumo['desativados']) echo ' · '.$resumo['desativados'].' desativado(s)'; ?>
        </span>
    </a>
    <div class="cartao cartao-resumo">
        <span class="rotulo">Sistema</span>
        <?php if ($motor['rodando']) { ?>
            <span class="numero numero-texto">No ar</span>
            <span class="apoio">Gravador ligado <?php echo $motor['iniciado_em'] ? 'há '.G2WGestao::duracao(time() - $motor['iniciado_em']) : ''; ?>.
                <?php if ($bytes_dia >= 1048576) { ?>Grava cerca de <?php echo G2WGestao::tamanho($bytes_dia); ?> por dia.<?php } ?></span>
        <?php } else { ?>
            <span class="numero numero-texto numero-erro">Parado</span>
            <span class="apoio">O gravador não está rodando.</span>
        <?php } ?>
    </div>
</section>

<section class="bloco" aria-labelledby="t-cams">
    <div class="cabecalho-bloco">
        <h2 id="t-cams">Câmeras</h2>
        <a href="/gestao/cameras">Ajustar câmeras</a>
    </div>
    <?php if (!$cams) { ?>
        <p class="vazio">Nenhuma câmera cadastrada. Use o <a href="/monitor">modo avançado</a> para adicionar a primeira.</p>
    <?php } ?>
    <ul class="grade-cameras">
        <?php foreach ($cams as $c) { ?>
        <li class="cartao camera camera-<?php echo $h($c['estado']['nivel']); ?>">
            <div class="camera-imagem">
                <?php if ($c['imagem_ts']) { ?>
                    <img src="/gestao/miniatura?id=<?php echo $c['id']; ?>&amp;t=<?php echo intval($c['imagem_ts']); ?>" alt="Imagem mais recente de <?php echo $h($c['nome']); ?>" loading="lazy" width="640" height="360">
                    <?php if (!$c['imagem_ok']) { ?><span class="selo-imagem">imagem de <?php echo $h(G2WGestao::quando($c['imagem_ts'])); ?></span><?php } ?>
                <?php } else { ?>
                    <span class="sem-imagem">Sem imagem</span>
                <?php } ?>
            </div>
            <div class="camera-corpo">
                <h3><?php echo $h($c['nome']); ?></h3>
                <p class="estado estado-<?php echo $h($c['estado']['nivel']); ?>">
                    <span class="ponto" aria-hidden="true"></span><?php echo $h($c['estado']['rotulo']); ?>
                </p>
                <p class="camera-detalhe"><?php echo $h($c['estado']['detalhe']); ?></p>
                <dl class="fatos">
                    <div><dt>Última gravação</dt><dd><?php echo $h(G2WGestao::quando($c['ultima_gravacao'])); ?></dd></div>
                    <div><dt>Histórico guardado</dt><dd><?php
                        echo $c['mais_antiga'] ? ($c['dias_guardados'] < 1 ? 'menos de 1 dia' : $c['dias_guardados'].' dia'.($c['dias_guardados'] > 1 ? 's' : '')) : 'nada';
                        echo $c['retencao_dias'] ? ' (limite: '.$c['retencao_dias'].')' : ' (até encher o disco)'; ?></dd></div>
                    <div><dt>Espaço usado</dt><dd><?php echo G2WGestao::tamanho($c['bytes']); ?></dd></div>
                </dl>
                <a class="camera-link" href="/gestao/gravacoes#camera=<?php echo $c['id']; ?>">Ver gravações<span class="sr"> de <?php echo $h($c['nome']); ?></span></a>
            </div>
        </li>
        <?php } ?>
    </ul>
</section>

<section class="bloco duas-colunas">
    <div class="cartao" aria-labelledby="t-cert">
        <h2 id="t-cert">Certificado de segurança (HTTPS)</h2>
        <?php if (!$certs) { ?><p class="vazio">Nenhuma porta HTTPS respondeu.</p><?php } ?>
        <ul class="lista-simples">
            <?php foreach ($certs as $c) { ?>
            <li>
                <strong><?php echo $h($c['rotulo']); ?></strong>
                <?php if ($c['autoassinado']) { ?>
                    <span>Certificado interno (não vence para o uso local).</span>
                <?php } else { ?>
                    <span class="<?php echo $c['dias'] <= G2WGestao::CERT_DIAS_ALERTA ? 'texto-alerta' : ''; ?>">
                        <?php echo $h($c['nome']); ?> —
                        <?php echo $c['dias'] < 0 ? 'vencido há '.abs($c['dias']).' dias' : 'vence em '.$c['dias'].' dias'; ?>
                        (<?php echo date('d/m/Y', $c['vence_em']); ?>)
                    </span>
                <?php } ?>
            </li>
            <?php } ?>
        </ul>
    </div>
    <div class="cartao" aria-labelledby="t-motor">
        <h2 id="t-motor">Últimos reinícios do gravador</h2>
        <?php if (!$motor['historico']) { ?>
            <p class="vazio">Ainda sem registro.</p>
        <?php } else { ?>
            <ul class="lista-simples">
                <?php foreach ($motor['historico'] as $i => $t) { ?>
                    <li><strong><?php echo date('d/m/Y H:i', $t); ?></strong>
                        <span><?php echo ($i === 0 && $motor['rodando'] && $motor['iniciado_em'] == $t) ? 'início atual — no ar desde então' : 'reinício anterior'; ?></span></li>
                <?php } ?>
            </ul>
            <p class="nota">Registrado quando o painel é aberto; reinícios seguidos sem ninguém olhar aparecem como um só.</p>
        <?php } ?>
    </div>
</section>

</div>
