/* G2W Monitor — tela "Ao vivo" (/aovivo). JavaScript puro; o tocador é o hls.js
 * guardado em vendor/ (nada vem de fora). Não usar MooTools nesta página: ele
 * troca Function.prototype.bind e o hls.js para de funcionar. */
(function () {
    'use strict';

    var D = JSON.parse(document.getElementById('dados-aovivo').textContent);
    var cameras = D.cameras;
    var porId = {};
    cameras.forEach(function (c) { porId[c.id] = c; });

    var OPCOES = ['todas', '1', '2', '4', '6', '9'];
    var CHAVE = 'g2w-aovivo-' + D.usuario_id;
    var RENOVA_TOKEN_MS = 110000;      // o servidor descarta chave parada há 5 min
    var CONFERE_ESTADO_MS = 30000;
    var SEM_AVANCO_MS = 20000;         // vídeo "ao vivo" que não anda = caiu
    var ESPERA_INICIO_MS = 25000;
    var CODEC_NAO_TOCA = ['manifestIncompatibleCodecsError', 'bufferAddCodecError', 'bufferIncompatibleCodecsError'];

    var grade = document.getElementById('av-grade');
    var dlg = document.getElementById('av-escolher');
    var quadros = [];                  // objetos Quadro, um por posição
    var encerrado = false;             // sessão terminou: nada mais é pedido ao servidor

    function $(sel, raiz) { return (raiz || document).querySelector(sel); }
    function $$(sel, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(sel)); }
    function el(tag, attrs, filhos) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') e.textContent = attrs[k];
            else if (k === 'class') e.className = attrs[k];
            else if (attrs[k] !== false && attrs[k] !== null) e.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
        });
        (filhos || []).forEach(function (f) { if (f) e.appendChild(typeof f === 'string' ? document.createTextNode(f) : f); });
        return e;
    }
    function hora(d) {
        return new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(d || new Date());
    }

    // ------------------------------------------------ preferência da pessoa
    // Fica só neste navegador (localStorage): quantas câmeras por tela e qual
    // câmera em cada quadro. Sem preferência salva, abre com todas.
    var pref = lerPref();

    function lerPref() {
        var p = null;
        try { p = JSON.parse(window.localStorage.getItem(CHAVE)); } catch (e) { p = null; }
        if (!p || OPCOES.indexOf(String(p.quantas)) < 0 || !Array.isArray(p.quadros)) {
            return arrumar('todas', []);
        }
        // ids sempre como número (é assim que a lista de câmeras vem do servidor)
        var ids = p.quadros.map(function (v) { var n = parseInt(v, 10); return (v === null || isNaN(n)) ? null : n; });
        return arrumar(String(p.quantas), ids, true);
    }

    function salvarPref() {
        try { window.localStorage.setItem(CHAVE, JSON.stringify(pref)); } catch (e) { /* navegador sem armazenamento: vale só nesta visita */ }
    }

    /* Monta a lista de quadros para a quantidade pedida, aproveitando a ordem
     * que já existia. `manterVazios`: ao reabrir a tela, quadro que a pessoa
     * deixou vazio continua vazio; ao trocar a quantidade, os vazios são
     * preenchidos com as câmeras que sobraram. */
    function arrumar(quantas, anteriores, manterVazios) {
        var usados = {};
        var lista = [];
        anteriores.forEach(function (id) {
            if (id !== null && porId[id] && !usados[id]) { usados[id] = true; lista.push(id); }
            else if (manterVazios && quantas !== 'todas') lista.push(null);
        });
        var sobra = cameras.map(function (c) { return c.id; }).filter(function (id) { return !usados[id]; });
        if (quantas === 'todas') {
            return { quantas: 'todas', quadros: lista.filter(function (id) { return id !== null; }).concat(sobra) };
        }
        var n = parseInt(quantas, 10);
        lista = lista.slice(0, n);
        // câmera que ficou de fora do corte volta a estar disponível
        sobra = cameras.map(function (c) { return c.id; }).filter(function (id) { return lista.indexOf(id) < 0; });
        if (!manterVazios) {
            lista = lista.filter(function (id) { return id !== null; });
            while (lista.length < n && sobra.length) lista.push(sobra.shift());
        }
        while (lista.length < n) lista.push(null);
        return { quantas: quantas, quadros: lista };
    }

    // ----------------------------------------------------- chave do vídeo
    var token = null, tokenEm = 0, pedindoToken = null;

    function sessaoAcabou() {
        if (encerrado) return;
        encerrado = true;
        quadros.forEach(function (q) { q.parar('Sessão encerrada'); });
        var a = $('#av-aviso');
        a.textContent = 'Sua sessão terminou. ';
        a.appendChild(el('a', { href: '/login', text: 'Entrar de novo' }));
        a.hidden = false;
    }

    /* Confere a sessão e traz a hora da última foto de cada câmera. Vem antes
     * de qualquer pedido às rotas /media: essas respondem "401 Basic" quando a
     * sessão acaba e o navegador abriria a janela de usuário e senha. */
    function conferirEstado() {
        if (encerrado) return Promise.reject(new Error('sessao'));
        return fetch('/aovivo/estado', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (r.redirected || r.status === 401 || r.status === 403) { sessaoAcabou(); throw new Error('sessao'); }
                if (!r.ok) throw new Error('estado ' + r.status);
                return r.json();
            })
            .then(function (j) {
                var liberadas = {};
                (j.cameras || []).forEach(function (c) {
                    liberadas[c.id] = true;
                    if (porId[c.id]) { porId[c.id].foto_ts = c.foto_ts; porId[c.id].foto_hora = c.foto_hora; }
                });
                quadros.forEach(function (q) {
                    // câmera desligada ou bloqueada para este usuário depois que a tela abriu:
                    // o quadro para de tentar e diz o motivo, em vez de ficar em "Sem sinal"
                    if (q.cam && !liberadas[q.cam.id]) q.negar();
                    else q.atualizarFoto();
                });
                return j;
            });
    }

    function obterToken(camId, forcar) {
        if (!forcar && token && Date.now() - tokenEm < RENOVA_TOKEN_MS) return Promise.resolve(token);
        if (pedindoToken) return pedindoToken;
        pedindoToken = conferirEstado().then(function () {
            return fetch('/media/hls?id=' + encodeURIComponent(camId) + '&tokenOnly=1', { credentials: 'same-origin' });
        }).then(function (r) {
            if (!r.ok) throw new Error('token ' + r.status);
            return r.json();
        }).then(function (j) {
            if (!j || !j.success || !j.token) throw new Error('token recusado');
            // Se a chave mudar, os vídeos que já tocam seguem com a antiga; a nova vale
            // para os próximos inícios. Se o servidor recusar a antiga (401), o
            // quadro pede outra e recomeça sozinho.
            token = String(j.token);
            tokenEm = Date.now();
            pedindoToken = null;
            return token;
        }).catch(function (e) {
            pedindoToken = null;
            throw e;
        });
        return pedindoToken;
    }

    // ------------------------------------------------------------- quadro
    function Quadro(pos, camId) {
        var cam = camId !== null ? porId[camId] : null;
        var hls = null, video = null, foto = null, rotulo = null, detalhe = null, botaoSom = null;
        var estado = 'vazio', iniciadoEm = 0, ultimoAvanco = 0, fragmentos = 0, errosMidia = 0;
        var tentativa = 0, timerVolta = null, semSinalDesde = null, vivo = true;
        var rodada = 0;     // cada início ganha um número; resposta de um início antigo é descartada

        var raiz = el('div', { class: 'av-quadro', 'data-pos': pos, 'data-estado': 'vazio' });

        if (!cam) {
            var escolher = el('button', { type: 'button', class: 'av-vazio', 'aria-label': 'Escolher câmera para o quadro ' + (pos + 1) },
                [el('span', { class: 'av-mais', 'aria-hidden': 'true', text: '+' }), 'Escolher câmera']);
            escolher.addEventListener('click', function () { abrirEscolha(pos); });
            raiz.appendChild(escolher);
        } else {
            foto = el('img', { class: 'av-foto', alt: '', width: 640, height: 360, decoding: 'async' });
            video = el('video', { playsinline: true, autoplay: true, 'aria-label': 'Vídeo ao vivo de ' + cam.nome });
            video.muted = true;
            rotulo = el('span', { class: 'av-estado' });
            detalhe = el('p', { class: 'av-detalhe', hidden: true });
            botaoSom = el('button', { type: 'button', class: 'av-botao', hidden: true, 'aria-pressed': 'false', text: 'Som' });
            var trocar = el('button', { type: 'button', class: 'av-botao av-b-trocar', 'aria-label': 'Trocar a câmera do quadro ' + (pos + 1) + ' (' + cam.nome + ')', text: 'Trocar' });
            var cheia = el('button', { type: 'button', class: 'av-botao', 'aria-label': 'Tela cheia: ' + cam.nome, text: 'Tela cheia' });

            raiz.appendChild(foto);
            raiz.appendChild(video);
            raiz.appendChild(el('div', { class: 'av-legenda' }, [el('strong', { class: 'av-nome', text: cam.nome, translate: 'no' }), rotulo]));
            raiz.appendChild(detalhe);
            raiz.appendChild(el('div', { class: 'av-botoes' }, [botaoSom, trocar, cheia]));

            trocar.addEventListener('click', function () { abrirEscolha(pos); });
            // clicar em qualquer ponto do quadro também abre a troca (o botão fica para o teclado)
            raiz.addEventListener('click', function (ev) {
                // (com o próprio quadro em tela cheia o clique não faz nada: o diálogo ficaria por baixo)
                if (!ev.target.closest('.av-botoes') && document.fullscreenElement !== raiz) abrirEscolha(pos);
            });
            cheia.addEventListener('click', function () { telaCheia(raiz, video); });
            botaoSom.addEventListener('click', function () {
                var ligar = video.muted;
                quadros.forEach(function (q) { q.calar(); });
                if (ligar) { video.muted = false; botaoSom.setAttribute('aria-pressed', 'true'); botaoSom.textContent = 'Som ligado'; }
            });
            video.addEventListener('timeupdate', function () {
                if (video.currentTime > 0 && !video.paused) {
                    ultimoAvanco = Date.now();
                    if (estado !== 'aovivo' && estado !== 'foto') aoVivo();
                }
            });
            // só vale no HLS nativo (iPhone/iPad); com o hls.js quem avisa é o evento ERROR dele
            video.addEventListener('error', function () { if (!hls && estado !== 'foto' && estado !== 'parado' && video.getAttribute('src')) semSinal(); });
            foto.addEventListener('error', function () { foto.hidden = true; });
            foto.addEventListener('load', function () { foto.hidden = false; });
        }

        function marcar(novo, texto, explicacao) {
            estado = novo;
            raiz.setAttribute('data-estado', novo);
            if (rotulo) rotulo.textContent = texto;
            if (detalhe) { detalhe.textContent = explicacao || ''; detalhe.hidden = !explicacao; }
        }

        function aoVivo() {
            tentativa = 0;
            errosMidia = 0;
            semSinalDesde = null;
            marcar('aovivo', 'Ao vivo');
        }

        function desligarTocador() {
            rodada++;
            if (timerVolta) { clearTimeout(timerVolta); timerVolta = null; }
            if (hls) { try { hls.destroy(); } catch (e) { /* já destruído */ } hls = null; }
            if (video) { video.removeAttribute('src'); try { video.load(); } catch (e) { /* sem fonte */ } }
        }

        function semSinal() {
            if (!vivo || encerrado || estado === 'foto' || estado === 'negado') return;
            desligarTocador();
            if (!semSinalDesde) semSinalDesde = new Date();
            porFoto();
            marcar('semsinal', 'Sem sinal desde ' + hora(semSinalDesde), 'Tentando de novo…');
            var espera = Math.min(30000, 3000 * Math.pow(2, tentativa));
            tentativa++;
            timerVolta = setTimeout(iniciar, espera);
        }

        function soFoto(motivo) {
            desligarTocador();
            porFoto();
            marcar('foto', 'Só foto' + (cam.foto_hora ? ' · ' + cam.foto_hora : ''), motivo);
        }

        /* Foto mais recente por baixo do vídeo. Com o vídeo tocando ela fica
         * escondida, então só é buscada quando vai aparecer. */
        function porFoto() {
            if (!cam || !foto || !cam.foto_ts) return;
            var src = '/media/request?mode=livesnapshot&id=' + cam.id + '&t=' + cam.foto_ts;
            if (foto.getAttribute('src') !== src) foto.setAttribute('src', src);
        }

        function iniciar() {
            if (!vivo || !cam || encerrado) return;
            desligarTocador();
            iniciadoEm = Date.now();
            ultimoAvanco = 0;
            fragmentos = 0;
            if (estado !== 'semsinal') marcar('conectando', 'Conectando…');
            var minha = rodada;
            obterToken(cam.id).then(function (tk) {
                if (!vivo || encerrado || minha !== rodada) return;
                var url = '/hls/' + cam.id + '/0/playlist.m3u8?authtoken=' + encodeURIComponent(tk);
                if (window.Hls && window.Hls.isSupported()) {
                    tocarComHls(url);
                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                    // iPhone/iPad: o próprio navegador toca
                    video.src = url;
                    var p = video.play();
                    if (p && p.catch) p.catch(function () { /* espera o toque do usuário */ });
                } else {
                    soFoto('Este navegador não toca vídeo ao vivo. A foto é atualizada a cada 2 minutos.');
                }
            }).catch(function (e) {
                if ((e && e.message === 'sessao') || minha !== rodada) return;
                semSinal();
            });
        }

        function tocarComHls(url) {
            var H = window.Hls;
            hls = new H({
                enableWorker: true,
                lowLatencyMode: false,
                backBufferLength: 10,           // tela aberta o dia todo: não acumular vídeo antigo na memória
                liveSyncDurationCount: 2,
                liveMaxLatencyDurationCount: 6,
                maxLiveSyncPlaybackRate: 1.2,
                manifestLoadingMaxRetry: 1,
                levelLoadingMaxRetry: 2,
                fragLoadingMaxRetry: 2
            });
            hls.on(H.Events.MANIFEST_PARSED, function () {
                var p = video.play();
                if (p && p.catch) p.catch(function () { /* autoplay recusado: o controle aparece no toque */ });
            });
            hls.on(H.Events.FRAG_LOADED, function () { fragmentos++; });
            hls.on(H.Events.BUFFER_CODECS, function (ev, dados) {
                if (dados && dados.audio) botaoSom.hidden = false;
            });
            hls.on(H.Events.ERROR, function (ev, dados) {
                if (!dados) return;
                if (CODEC_NAO_TOCA.indexOf(dados.details) >= 0) {
                    soFoto('Esta câmera envia um formato de vídeo (H.265) que este navegador não toca. A foto é atualizada a cada 2 minutos.');
                    return;
                }
                if (!dados.fatal) return;
                var codigo = dados.response && dados.response.code;
                if (codigo === 401 || codigo === 403) {
                    // chave vencida ou trocada: pede outra e recomeça. Se a recusa for
                    // por permissão, a conferência de estado marca o quadro como "Sem acesso".
                    token = null;
                    semSinal();
                    conferirEstado().catch(function () { /* fica em "Sem sinal" e tenta de novo */ });
                    return;
                }
                if (dados.type === H.ErrorTypes.MEDIA_ERROR && errosMidia < 2) {
                    errosMidia++;
                    hls.recoverMediaError();
                    return;
                }
                semSinal();
            });
            hls.loadSource(url);
            hls.attachMedia(video);
        }

        return {
            el: raiz,
            cam: cam,
            iniciar: iniciar,
            parar: function (texto) {
                desligarTocador();
                if (cam) marcar('parado', texto || 'Pausado');
            },
            destruir: function () { vivo = false; desligarTocador(); },
            negar: function () {
                if (estado === 'negado') return;
                desligarTocador();
                if (foto) foto.hidden = true;
                marcar('negado', 'Sem acesso', 'Esta câmera não está mais liberada para o seu usuário. Escolha outra para este quadro.');
            },
            calar: function () {
                if (!video) return;
                video.muted = true;
                botaoSom.setAttribute('aria-pressed', 'false');
                botaoSom.textContent = 'Som';
            },
            atualizarFoto: function () {
                if (!cam || !foto || estado === 'negado') return;
                if (estado !== 'aovivo') porFoto();
                if (estado === 'foto') rotulo.textContent = 'Só foto' + (cam.foto_hora ? ' · ' + cam.foto_hora : '');
            },
            vigiar: function () {
                if (!cam || !vivo || encerrado || estado === 'negado') return;
                var agora = Date.now();
                if (estado === 'aovivo' && video.paused) {
                    // o navegador pausa vídeo mudo fora da área visível (celular): não é queda
                    ultimoAvanco = agora;
                    var p = video.play();
                    if (p && p.catch) p.catch(function () { /* volta quando estiver visível */ });
                } else if (estado === 'aovivo' && agora - ultimoAvanco > SEM_AVANCO_MS) {
                    semSinal();
                } else if (estado === 'conectando' && agora - iniciadoEm > ESPERA_INICIO_MS) {
                    // pedaços chegando e nada na tela = o navegador não decodifica este formato
                    if (fragmentos > 0) soFoto('Este navegador não conseguiu mostrar o vídeo desta câmera. A foto é atualizada a cada 2 minutos.');
                    else semSinal();
                }
            },
            retomar: function () { if (cam && (estado === 'parado')) { tentativa = 0; iniciar(); } }
        };
    }

    // --------------------------------------------------------------- grade
    function montarQuadro(pos) {
        var antigo = quadros[pos];
        var novo = Quadro(pos, pref.quadros[pos]);
        if (antigo) { antigo.destruir(); grade.replaceChild(novo.el, antigo.el); }
        else grade.appendChild(novo.el);
        quadros[pos] = novo;
        novo.atualizarFoto();
        novo.iniciar();
    }

    function montarGrade() {
        quadros.forEach(function (q) { q.destruir(); });
        quadros = [];
        grade.textContent = '';
        grade.setAttribute('data-quantas', pref.quantas);
        grade.setAttribute('data-total', pref.quadros.length);
        $$('[data-quantas]', $('.av-quantas')).forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-quantas') === pref.quantas ? 'true' : 'false');
        });
        pref.quadros.forEach(function (id, pos) { montarQuadro(pos); });
    }

    $$('[data-quantas]', $('.av-quantas')).forEach(function (b) {
        b.addEventListener('click', function () {
            var q = b.getAttribute('data-quantas');
            if (q === pref.quantas) return;
            pref = arrumar(q, pref.quadros, false);
            salvarPref();
            montarGrade();
        });
    });

    // ------------------------------------------------- trocar a câmera
    function abrirEscolha(pos) {
        var lista = $('#av-lista');
        lista.textContent = '';
        // o diálogo mora dentro de <main>; se a tela cheia for de um quadro só, sai dela para ele aparecer
        if (document.fullscreenElement && !document.fullscreenElement.contains(dlg)) document.exitFullscreen();
        $('#av-escolher-t').textContent = 'Qual câmera no quadro ' + (pos + 1) + '?';
        var atual = pref.quadros[pos];
        cameras.forEach(function (c) {
            var onde = pref.quadros.indexOf(c.id);
            var nota = c.id === atual ? 'está neste quadro' : (onde >= 0 ? 'hoje no quadro ' + (onde + 1) + ' — troca de lugar' : '');
            var b = el('button', { type: 'button', class: 'av-opcao', 'aria-current': c.id === atual ? 'true' : false },
                [el('strong', { text: c.nome, translate: 'no' }), nota ? el('span', { text: nota }) : null]);
            b.addEventListener('click', function () { escolher(pos, c.id); });
            lista.appendChild(b);
        });
        if (pref.quantas !== 'todas' && atual !== null) {
            var vazio = el('button', { type: 'button', class: 'av-opcao av-opcao-vazio' }, [el('strong', { text: 'Deixar este quadro vazio' })]);
            vazio.addEventListener('click', function () { escolher(pos, null); });
            lista.appendChild(vazio);
        }
        dlg.showModal();
    }

    function escolher(pos, camId) {
        dlg.close();
        var atual = pref.quadros[pos];
        if (camId === atual) return;
        var outro = camId === null ? -1 : pref.quadros.indexOf(camId);
        pref.quadros[pos] = camId;
        if (outro >= 0) pref.quadros[outro] = atual;      // a câmera já estava em outro quadro: troca de lugar
        salvarPref();
        montarQuadro(pos);
        if (outro >= 0) montarQuadro(outro);
    }

    document.addEventListener('click', function (ev) {
        var f = ev.target.closest('[data-fechar]');
        if (f) f.closest('dialog').close();
    });

    // ---------------------------------------------------------- tela cheia
    function telaCheia(alvo, video) {
        if (document.fullscreenElement) { document.exitFullscreen(); return; }
        if (alvo.requestFullscreen) alvo.requestFullscreen().catch(function () { /* recusado pelo navegador */ });
        else if (video && video.webkitEnterFullscreen) video.webkitEnterFullscreen();   // iPhone: só o vídeo entra em tela cheia
    }
    var botaoCheia = $('#av-tela-cheia');
    if (!document.fullscreenEnabled) botaoCheia.hidden = true;
    botaoCheia.addEventListener('click', function () { telaCheia($('#conteudo')); });
    document.addEventListener('fullscreenchange', function () {
        botaoCheia.textContent = document.fullscreenElement ? 'Sair da tela cheia' : 'Tela cheia';
    });

    // ------------------------------------------- economia e manutenção
    // Aba escondida por mais de 20 s: para os vídeos (não gasta rede nem
    // processador); ao voltar, recomeça do ponto ao vivo.
    var timerEscondida = null;
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            timerEscondida = setTimeout(function () { quadros.forEach(function (q) { q.parar('Pausado'); }); }, 20000);
        } else {
            clearTimeout(timerEscondida);
            quadros.forEach(function (q) { q.retomar(); });
        }
    });

    setInterval(function () { quadros.forEach(function (q) { q.vigiar(); }); }, 5000);
    setInterval(function () { if (!document.hidden && !encerrado) conferirEstado().catch(function () { /* tenta na próxima */ }); }, CONFERE_ESTADO_MS);
    setInterval(function () {
        if (document.hidden || encerrado || !cameras.length) return;
        obterToken(cameras[0].id, true).catch(function () { /* os quadros tratam a queda */ });
    }, RENOVA_TOKEN_MS);

    if (!cameras.length) {
        $('#av-sem-cameras').hidden = false;
        $('.av-controles').hidden = true;
        $('#av-nota').hidden = true;
    } else {
        montarGrade();
    }
})();
