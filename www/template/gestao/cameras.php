<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$rotulo_padrao = array('sempre' => 'grava sempre', 'nunca' => 'não grava', 'personalizada' => 'por agenda');
?>
<div class="titulo-com-acao">
    <h1 class="titulo-pagina">Câmeras</h1>
    <button type="button" class="botao botao-principal" id="abrir-nova">Adicionar câmera</button>
</div>
<p class="intro">Adicione e remova câmeras, mude o nome, por quantos dias guardar e se a câmera grava. Ajustes finos de imagem ficam no <a href="/monitor">modo avançado</a>.</p>

<section class="bloco" aria-label="Lista de câmeras">
    <?php if (!$cams) { ?><p class="vazio">Nenhuma câmera cadastrada ainda. Use “Adicionar câmera” para cadastrar a primeira.</p><?php } ?>
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
            <?php if ($c['fonte_editavel']) { ?>
            <button type="button" class="botao botao-leve" data-testar="<?php echo $c['id']; ?>" aria-label="Testar a conexão de <?php echo $h($c['nome']); ?>">Testar conexão</button>
            <?php } ?>
            <button type="button" class="botao botao-leve botao-perigo" data-remover="<?php echo $c['id']; ?>"
                data-nome="<?php echo $h($c['nome']); ?>"
                data-prazo="<?php echo $c['retencao_dias'] === null ? 'sem limite de dias' : intval($c['retencao_dias']).' dia'.($c['retencao_dias'] > 1 ? 's' : ''); ?>"
                data-guardado="<?php echo $c['mais_antiga'] ? $h(($c['dias_guardados'] < 1 ? 'menos de 1 dia' : $c['dias_guardados'].' dia'.($c['dias_guardados'] > 1 ? 's' : '')).($c['bytes'] > 0 ? ', '.G2WGestao::tamanho($c['bytes']) : '')) : ''; ?>"
                aria-label="Remover a câmera <?php echo $h($c['nome']); ?>">Remover</button>
        </div>
        <div class="resultado-teste" data-teste-de="<?php echo $c['id']; ?>" role="status" aria-live="polite" hidden></div>
        <div class="linha-camera-local" data-local-de="<?php echo $c['id']; ?>">
            <span class="rotulo">Localização no mapa</span>
            <span class="local-texto" data-local-texto><?php
                echo $c['local'] ? $h($c['local']['endereco'] !== '' ? $c['local']['endereco'] : 'Ponto marcado, sem endereço escrito') : 'Não definida'; ?></span>
            <button type="button" class="botao" data-abrir-local="<?php echo $c['id']; ?>"
                aria-label="<?php echo $c['local'] ? 'Ajustar' : 'Definir'; ?> a localização de <?php echo $h($c['nome']); ?>"><?php echo $c['local'] ? 'Ajustar' : 'Definir'; ?></button>
        </div>
    </form>
    <?php } ?>
</section>

<?php
$locais_json = array();
foreach ($cams as $c) $locais_json[] = array('id' => $c['id'], 'nome' => $c['nome'], 'local' => $c['local']);
?>
<script type="application/json" id="dados-locais"><?php echo json_encode($locais_json, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

<dialog id="dlg-local" class="dialogo dialogo-mapa" aria-labelledby="dlg-local-t">
    <form method="dialog" data-form-local autocomplete="off" novalidate>
        <h2 id="dlg-local-t">Localização da câmera</h2>
        <div class="busca-endereco">
            <div class="campo">
                <label for="loc-endereco">Endereço</label>
                <input id="loc-endereco" name="endereco" type="text" maxlength="<?php echo G2WGestao::ENDERECO_MAX; ?>" autocomplete="off" placeholder="Ex.: Rua das Flores, 120, Campo Grande…" aria-describedby="loc-endereco-ajuda">
            </div>
            <button type="button" class="botao" id="loc-buscar">Buscar no mapa</button>
        </div>
        <small id="loc-endereco-ajuda">O endereço aparece para quem pode ver a câmera. Ao buscar, só o texto digitado é enviado ao serviço público de busca do OpenStreetMap (Nominatim).</small>
        <ul class="busca-resultados" id="loc-resultados" aria-label="Lugares encontrados" hidden></ul>
        <p class="nota" id="loc-busca-msg" role="status" aria-live="polite"></p>
        <div class="mapa mapa-dialogo" id="loc-mapa" role="application" aria-label="Mapa: clique para marcar onde a câmera fica; arraste o marcador para ajustar"></div>
        <p class="nota">Clique no mapa para marcar o ponto, ou arraste o marcador. <span id="loc-coordenada" aria-live="polite"></span></p>
        <p class="erro-form" role="alert" hidden></p>
        <div class="dialogo-acoes">
            <button type="button" class="botao botao-perigo" id="loc-remover" hidden>Remover localização</button>
            <button type="button" class="botao" data-fechar>Cancelar</button>
            <button type="submit" class="botao botao-principal">Salvar localização</button>
        </div>
    </form>
</dialog>

<?php if ($removidas) { ?>
<section class="bloco" id="removidas" aria-labelledby="t-rem">
    <h2 id="t-rem">Câmeras removidas com gravações guardadas</h2>
    <p class="intro">Só administradores veem estas gravações. Elas ocupam espaço até vencerem, serem apagadas aqui ou a limpeza automática do disco chegar nelas.</p>
    <ul class="lista-removidas">
        <?php foreach ($removidas as $r) { ?>
        <li class="cartao">
            <?php if ($r['apagando']) { ?>
            <div>
                <strong><?php echo $h($r['nome']); ?></strong>
                <span class="nota">Removida em <?php echo date('d/m/Y', $r['removida_em']); ?>. <?php echo $r['bytes'] > 0
                    ? 'A exclusão das gravações foi interrompida: ainda há '.G2WGestao::tamanho($r['bytes']).' no disco.'
                    : 'As gravações já foram apagadas e o espaço liberado. O servidor termina a limpeza sozinho em alguns minutos e este item some.'; ?></span>
            </div>
            <?php if ($r['bytes'] > 0) { ?>
            <div class="linha-camera-acoes">
                <button type="button" class="botao botao-leve botao-perigo" data-apagar-removida="<?php echo $r['id']; ?>" data-nome="<?php echo $h($r['nome']); ?>"
                    data-guardado="<?php echo $h(G2WGestao::tamanho($r['bytes'])); ?>" aria-label="Continuar apagando as gravações de <?php echo $h($r['nome']); ?>">Continuar apagando</button>
            </div>
            <?php } ?>
            <?php } else { ?>
            <div>
                <strong><?php echo $h($r['nome']); ?></strong>
                <span class="nota">Removida em <?php echo date('d/m/Y', $r['removida_em']); ?>. Gravações de <?php echo date('d/m/Y', $r['antiga']); ?> a <?php echo date('d/m/Y', $r['recente']); ?>, <?php echo G2WGestao::tamanho($r['bytes']); ?>. <?php echo $r['retencao_dias'] ? 'Cada gravação é apagada ao completar '.$r['retencao_dias'].' dia'.($r['retencao_dias'] > 1 ? 's' : '').'.' : 'Sem prazo em dias: ficam até o disco precisar do espaço.'; ?></span>
            </div>
            <div class="linha-camera-acoes">
                <a class="botao botao-leve" href="/gestao/gravacoes#camera=<?php echo $r['id']; ?>">Ver gravações</a>
                <button type="button" class="botao botao-leve botao-perigo" data-apagar-removida="<?php echo $r['id']; ?>" data-nome="<?php echo $h($r['nome']); ?>"
                    data-guardado="<?php echo $h(G2WGestao::tamanho($r['bytes'])); ?>" aria-label="Apagar as gravações de <?php echo $h($r['nome']); ?>">Apagar gravações</button>
            </div>
            <?php } ?>
        </li>
        <?php } ?>
    </ul>
</section>
<?php } ?>

<dialog id="dlg-nova" class="dialogo dialogo-nova" aria-labelledby="dlg-nova-t">
    <form method="dialog" data-form-nova autocomplete="off" novalidate>
        <h2 id="dlg-nova-t">Adicionar câmera</h2>
        <div class="campos-grade">
            <div class="campo campo-largo">
                <label for="n-nome">Nome</label>
                <input id="n-nome" name="nome" type="text" maxlength="100" autocomplete="off" placeholder="Ex.: Portaria…">
            </div>
            <div class="campo">
                <label for="n-fab">Fabricante</label>
                <select id="n-fab" name="fabricante" aria-describedby="n-fab-ajuda">
                    <option value="">Outro (RTSP): digitar o caminho</option>
                    <optgroup label="Mais usados">
                        <option value="atalho" data-caminho="/cam/realmonitor?channel=1&amp;subtype=0">Intelbras / Dahua: imagem principal</option>
                        <option value="atalho" data-caminho="/cam/realmonitor?channel=1&amp;subtype=1">Intelbras / Dahua: imagem leve</option>
                        <option value="atalho" data-caminho="/Streaming/Channels/101">Hikvision: imagem principal</option>
                        <option value="atalho" data-caminho="/Streaming/Channels/102">Hikvision: imagem leve</option>
                    </optgroup>
                    <?php if ($fabricantes) { ?>
                    <optgroup label="Todos os fabricantes">
                        <?php foreach ($fabricantes as $fb) { ?>
                        <option value="<?php echo $fb['id']; ?>"><?php echo $h($fb['nome']); ?></option>
                        <?php } ?>
                    </optgroup>
                    <?php } ?>
                </select>
                <small id="n-fab-ajuda">Escolher o fabricante só preenche o caminho do vídeo. Você pode corrigir o caminho depois.</small>
            </div>
            <div class="campo">
                <label for="n-modelo">Modelo</label>
                <select id="n-modelo" name="modelo" disabled><option value="">Não precisa escolher</option></select>
            </div>
            <div class="campo">
                <label for="n-host">Endereço IP ou nome da câmera</label>
                <input id="n-host" name="host" type="text" inputmode="url" maxlength="253" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="Ex.: 192.168.1.50…">
            </div>
            <div class="campo">
                <label for="n-porta">Porta RTSP</label>
                <input id="n-porta" name="porta" type="number" inputmode="numeric" min="1" max="65535" step="1" value="554" autocomplete="off">
            </div>
            <div class="campo">
                <label for="n-usuario">Usuário da câmera</label>
                <input id="n-usuario" name="usuario" type="text" maxlength="64" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="Ex.: admin…">
            </div>
            <div class="campo">
                <label for="n-senha">Senha da câmera</label>
                <input id="n-senha" name="senha" type="password" maxlength="128" autocomplete="new-password" aria-describedby="n-senha-ajuda">
                <small id="n-senha-ajuda">Fica guardada no servidor e não aparece mais em nenhuma tela.</small>
            </div>
            <div class="campo campo-largo">
                <label for="n-caminho">Caminho do vídeo</label>
                <input id="n-caminho" name="caminho" type="text" maxlength="200" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="Ex.: /cam/realmonitor?channel=1&amp;subtype=0…" aria-describedby="n-caminho-ajuda">
                <small id="n-caminho-ajuda">É a parte do endereço depois da porta. Está no manual da câmera, em “RTSP”.</small>
            </div>
        </div>
        <div class="teste-linha">
            <button type="button" class="botao" id="n-testar">Testar conexão</button>
            <div class="resultado-teste" id="n-teste" role="status" aria-live="polite" hidden></div>
        </div>
        <div class="campos-grade">
            <div class="campo">
                <label for="n-ret">Guardar gravações por</label>
                <div class="com-sufixo">
                    <input id="n-ret" name="retencao_dias" type="number" inputmode="numeric" min="1" max="3650" step="1" placeholder="sem limite" autocomplete="off" aria-describedby="n-ret-ajuda">
                    <span>dias</span>
                </div>
                <small id="n-ret-ajuda">Em branco: guarda até o disco encher.</small>
            </div>
            <div class="campo">
                <label for="n-grav">Gravação</label>
                <select id="n-grav" name="gravacao">
                    <option value="padrao" selected>Padrão (<?php echo $rotulo_padrao[$padrao_servidor]; ?>)</option>
                    <option value="sempre">Gravar o tempo todo</option>
                    <option value="nunca">Não gravar (só ao vivo)</option>
                </select>
            </div>
        </div>
        <label class="opcao"><input type="checkbox" name="gravar_som"> Gravar também o som da câmera (só funciona se o áudio dela for AAC)</label>
        <div class="linha-camera-local">
            <span class="rotulo">Localização no mapa (opcional)</span>
            <span class="local-texto" id="n-local-texto">Não definida</span>
            <button type="button" class="botao" id="n-local">Definir</button>
        </div>
        <p class="nota">A câmera nova fica liberada para todos os usuários. Para restringir, abra a aba <a href="/gestao/usuarios">Usuários</a> depois de adicionar.</p>
        <p class="erro-form" role="alert" hidden></p>
        <div class="dialogo-acoes">
            <button type="button" class="botao" data-fechar>Cancelar</button>
            <button type="submit" class="botao botao-principal">Adicionar câmera</button>
        </div>
    </form>
</dialog>

<dialog id="dlg-remover" class="dialogo" aria-labelledby="dlg-remover-t">
    <form method="dialog" data-form-remover autocomplete="off" novalidate>
        <h2 id="dlg-remover-t">Remover câmera</h2>
        <p>A câmera sai do painel e do aplicativo e para de gravar.</p>
        <fieldset class="opcoes" id="r-opcoes">
            <legend>O que fazer com as gravações dela (<span id="r-guardado"></span>)</legend>
            <label class="opcao"><input type="radio" name="gravacoes" value="guardar" checked> Guardar as gravações</label>
            <small>Só administradores continuam vendo, na aba Gravações. Elas seguem o prazo que a câmera tinha (<span id="r-prazo"></span>) e a limpeza automática do disco; você também pode apagá-las depois, no fim desta página.</small>
            <label class="opcao"><input type="radio" name="gravacoes" value="apagar"> Apagar também as gravações</label>
            <small>Apaga os vídeos agora e libera o espaço. Não tem como desfazer.</small>
        </fieldset>
        <p class="nota" id="r-sem-gravacao" hidden>Esta câmera não tem gravações guardadas.</p>
        <p class="nota" id="r-andamento" role="status" aria-live="polite"></p>
        <p class="erro-form" role="alert" hidden></p>
        <div class="dialogo-acoes">
            <button type="button" class="botao" data-fechar>Cancelar</button>
            <button type="submit" class="botao botao-perigo">Remover câmera</button>
        </div>
    </form>
</dialog>

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
