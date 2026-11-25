<?php defined('INDVR') or exit();
$h = array('G2WGestao', 'h');
$json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
require_once __DIR__.'/../lib/Estatico.php';
$v = function ($arq) { return g2w_v($arq); };
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="icon" href="/favicon.ico">
    <title>Gravações · <?php echo G2WMarca::h(G2WMarca::nome()); ?></title>
    <link href="/template/dist/css/gestao.css?v=<?php echo $v('template/dist/css/gestao.css'); ?>" rel="stylesheet">
    <link href="/template/dist/css/gravacoes.css?v=<?php echo $v('template/dist/css/gravacoes.css'); ?>" rel="stylesheet">
</head>
<body data-aba="gravacoes">
    <a class="pular" href="#conteudo">Pular para o conteúdo</a>
    <header class="topo">
        <div class="topo-linha">
            <a class="marca" href="/aovivo" aria-label="<?php echo G2WMarca::h(G2WMarca::nome()); ?> — ao vivo" translate="no">
                <?php if (G2WMarca::logo()) { echo G2WMarca::logoImg(200, 40, 'logo-marca'); } elseif (G2WMarca::propria()) { ?><img src="<?php echo G2WMarca::h(G2WMarca::icone()); ?>" alt="" width="40" height="40"><span class="marca-nome"><?php echo G2WMarca::h(G2WMarca::nome()); ?></span><?php } else { ?><img src="/img/logo.png?v=<?php echo $v('img/logo.png'); ?>" alt="G2W Monitor" width="109" height="46"><?php } ?>
            </a>
            <div class="topo-acoes">
                <?php if ($admin) { ?><a class="link-topo" href="/gestao">Painel</a><?php } ?>
                <a class="link-topo" href="/aovivo">Ao vivo</a>
                <span class="quem" title="Usuário conectado"><?php echo $h($usuario); ?></span>
                <a class="link-topo" href="/logout">Sair</a>
            </div>
        </div>
    </header>

    <main id="conteudo" tabindex="-1" class="gravacoes">
        <script type="application/json" id="dados-gravacoes"><?php echo $json; ?></script>

<?php if (!$pode) { ?>
        <h1 class="titulo-pagina">Gravações</h1>
        <div class="cartao gv-sem-acesso">
            <p><strong>Seu usuário não tem acesso às gravações.</strong></p>
            <p>Você pode ver as câmeras ao vivo. Para ver ou baixar gravações, peça a liberação ao administrador.</p>
            <p><a class="botao botao-principal" href="/aovivo">Ir para o ao vivo</a></p>
        </div>
<?php } else { ?>
        <!-- lista de câmeras -->
        <section id="gv-lista" hidden aria-labelledby="gv-lista-t">
            <h1 class="titulo-pagina" id="gv-lista-t">Gravações</h1>
            <p class="intro">Escolha a câmera para ver o que foi gravado.</p>
            <div class="gv-cameras" id="gv-cameras"></div>
            <p class="vazio" id="gv-sem-cameras" hidden>Nenhuma câmera está liberada para o seu usuário. Fale com o administrador.</p>
        </section>

        <!-- uma câmera -->
        <section id="gv-camera" hidden aria-labelledby="gv-nome">
            <div class="gv-cabeca">
                <a class="botao" href="/gravacoes"><span aria-hidden="true">‹</span> Todas as câmeras</a>
                <h1 class="titulo-pagina" id="gv-nome" tabindex="-1"></h1>
            </div>

            <div class="gv-palco" id="gv-palco">
                <video id="gv-video" playsinline muted preload="metadata" aria-label="Gravação da câmera"></video>
                <p class="gv-aviso" id="gv-aviso" role="status" hidden></p>
            </div>

            <div class="gv-barra">
                <p class="gv-relogio"><span id="gv-data"></span> <strong id="gv-hora" aria-live="off">--:--:--</strong></p>
                <div class="gv-controles" role="group" aria-label="Controles do vídeo">
                    <button type="button" class="botao" id="gv-menos10" aria-label="Voltar 10 segundos">−10 s</button>
                    <button type="button" class="botao botao-principal" id="gv-tocar">Tocar</button>
                    <button type="button" class="botao" id="gv-mais10" aria-label="Avançar 10 segundos">+10 s</button>
                    <label class="sr" for="gv-velocidade">Velocidade</label>
                    <select id="gv-velocidade" class="gv-select">
                        <option value="0.5">0,5×</option>
                        <option value="1" selected>1×</option>
                        <option value="2">2×</option>
                        <option value="4">4×</option>
                        <option value="8">8×</option>
                    </select>
                    <button type="button" class="botao" id="gv-som" aria-pressed="false">Som desligado</button>
                    <button type="button" class="botao" id="gv-tela-cheia">Tela cheia</button>
                </div>
            </div>

            <div class="cartao gv-tempo">
                <div class="gv-ir">
                    <div class="campo">
                        <label for="gv-dia">Dia</label>
                        <div class="dia-nav">
                            <button type="button" class="botao" id="gv-dia-antes" aria-label="Dia anterior">‹</button>
                            <input id="gv-dia" type="date" required>
                            <button type="button" class="botao" id="gv-dia-depois" aria-label="Próximo dia">›</button>
                        </div>
                    </div>
                    <form class="campo" id="gv-form-hora" autocomplete="off">
                        <label for="gv-ir-hora">Ir para a hora</label>
                        <div class="dia-nav">
                            <input id="gv-ir-hora" type="time" step="1" required>
                            <button type="submit" class="botao">Ir</button>
                        </div>
                    </form>
                    <div class="campo">
                        <span class="gv-rotulo" id="gv-zoom-t">Mostrar na linha</span>
                        <div class="gv-zoom" role="group" aria-labelledby="gv-zoom-t">
                            <button type="button" class="filtro" data-zoom="86400" aria-pressed="true">Dia inteiro</button>
                            <button type="button" class="filtro" data-zoom="21600" aria-pressed="false">6 h</button>
                            <button type="button" class="filtro" data-zoom="3600" aria-pressed="false">1 h</button>
                        </div>
                    </div>
                </div>

                <p class="gv-resumo" id="gv-resumo" aria-live="polite"></p>

                <div class="gv-linha-caixa">
                    <button type="button" class="botao gv-mover" id="gv-janela-antes" aria-label="Mostrar o período anterior" hidden>‹</button>
                    <div class="gv-linha" id="gv-linha" role="slider" tabindex="0" aria-label="Linha do tempo: posição do vídeo"
                         aria-valuemin="0" aria-valuemax="86400" aria-valuenow="0" aria-valuetext="">
                        <div class="gv-faixas" id="gv-faixas"></div>
                        <div class="gv-selecao" id="gv-selecao" hidden></div>
                        <div class="gv-cursor" id="gv-cursor" hidden></div>
                    </div>
                    <button type="button" class="botao gv-mover" id="gv-janela-depois" aria-label="Mostrar o período seguinte" hidden>›</button>
                </div>
                <div class="gv-escala" id="gv-escala" aria-hidden="true"></div>
                <p class="legenda gv-legenda"><span><span class="amostra amostra-gravado"></span> gravado</span> <span><span class="amostra amostra-vazio"></span> sem gravação</span> <span><span class="amostra gv-amostra-selecao"></span> trecho marcado para baixar</span></p>
                <p class="nota">Clique ou arraste na linha para ir a um horário. Com o teclado: setas andam aos poucos, Page Up e Page Down andam mais.</p>
            </div>

            <div class="cartao gv-baixar">
                <h2>Baixar trecho</h2>
                <p class="nota gv-baixar-intro">Marque o início e o fim (até 20 minutos) e baixe um arquivo só.</p>
                <form id="gv-form-trecho" autocomplete="off" novalidate>
                    <div class="gv-baixar-campos">
                        <div class="campo">
                            <label for="gv-t-inicio">Início</label>
                            <input id="gv-t-inicio" type="time" step="1" required>
                            <button type="button" class="botao botao-leve" id="gv-t-inicio-aqui">Usar o ponto atual</button>
                        </div>
                        <div class="campo">
                            <label for="gv-t-fim">Fim</label>
                            <input id="gv-t-fim" type="time" step="1" required>
                            <button type="button" class="botao botao-leve" id="gv-t-fim-aqui">Usar o ponto atual</button>
                        </div>
                    </div>
                    <p class="gv-trecho-info" id="gv-trecho-info" aria-live="polite"></p>
                    <p class="erro-form" id="gv-trecho-erro" role="alert" hidden></p>
                    <div class="gv-progresso" id="gv-progresso" hidden>
                        <progress id="gv-progresso-barra" max="100" value="0" aria-label="Andamento do download"></progress>
                        <span id="gv-progresso-texto" aria-live="polite"></span>
                    </div>
                    <div class="dialogo-acoes gv-baixar-acoes">
                        <button type="button" class="botao" id="gv-t-cancelar" hidden>Cancelar</button>
                        <button type="submit" class="botao botao-principal" id="gv-t-baixar">Baixar trecho</button>
                    </div>
                </form>
            </div>
        </section>
<?php } ?>
    </main>

    <div id="avisos-flutuantes" class="toasts" role="status" aria-live="polite"></div>
<?php if ($pode) { ?>
    <script src="/template/dist/js/gravacoes.js?v=<?php echo $v('template/dist/js/gravacoes.js'); ?>" defer></script>
<?php } ?>
</body>
</html>
