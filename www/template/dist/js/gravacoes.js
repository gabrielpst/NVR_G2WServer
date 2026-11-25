/* G2W Monitor — tela "Gravações" (/gravacoes). JavaScript puro, sem etapa de build.
 * A hora mostrada é a do servidor: tudo é contado a partir do início do dia que
 * o servidor informa, e não do relógio ou do fuso deste aparelho. */
(function () {
    'use strict';

    var D = JSON.parse(document.getElementById('dados-gravacoes').textContent);
    var TRECHO_MAX = D.trecho_max || 1200;
    var BLOB_MAX = 300 * 1048576;      // acima disso o download vai direto pelo navegador
    var PASSOS = { 86400: 300, 21600: 60, 3600: 10 };   // seta do teclado, por zoom

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
    function dois(n) { return (n < 10 ? '0' : '') + n; }
    function avisar(msg, erro) {
        var t = el('div', { class: 'toast' + (erro ? ' toast-erro' : ''), text: msg });
        $('#avisos-flutuantes').appendChild(t);
        setTimeout(function () { t.remove(); }, 6000);
    }
    function dur(s) {
        s = Math.max(0, Math.round(s));
        var h = Math.floor(s / 3600), mi = Math.floor((s % 3600) / 60), se = s % 60;
        if (h) return h + ' h ' + mi + ' min';
        if (mi) return mi + ' min' + (se ? ' ' + se + ' s' : '');
        return se + ' s';
    }
    function tam(b) {
        if (b >= 1073741824) return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(b / 1073741824) + ' GB';
        return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: b < 10485760 ? 1 : 0 }).format(b / 1048576) + ' MB';
    }

    var params = new URLSearchParams(window.location.search);
    var cam = null;
    D.cameras.forEach(function (c) { if (String(c.id) === params.get('camera')) cam = c; });

    // ------------------------------------------------------------ lista
    if (!cam) {
        var lista = $('#gv-lista'), grade = $('#gv-cameras');
        lista.hidden = false;
        if (!D.cameras.length) $('#gv-sem-cameras').hidden = false;
        D.cameras.forEach(function (c) {
            var foto = el('div', { class: 'gv-foto' });
            if (c.foto_ts) {
                var img = el('img', { src: '/media/request?mode=livesnapshot&id=' + c.id + '&t=' + c.foto_ts, alt: '', loading: 'lazy', width: 320, height: 180 });
                img.addEventListener('error', function () { img.remove(); foto.appendChild(el('span', { text: 'Sem imagem' })); });
                foto.appendChild(img);
            } else {
                foto.appendChild(el('span', { text: 'Sem imagem' }));
            }
            grade.appendChild(el('article', { class: 'gv-cartao' }, [
                foto,
                el('div', { class: 'gv-cartao-corpo' }, [
                    el('h2', { text: c.nome }),
                    el('a', { class: 'botao botao-principal', href: '/gravacoes?camera=' + c.id, text: 'Ver gravações', 'aria-label': 'Ver gravações de ' + c.nome })
                ])
            ]));
        });
        if (params.get('camera')) avisar('Essa câmera não está liberada para o seu usuário.', true);
        return;
    }

    // ----------------------------------------------------------- câmera
    $('#gv-camera').hidden = false;
    $('#gv-nome').textContent = cam.nome;
    document.title = cam.nome + ' · ' + document.title;

    var video = $('#gv-video'), palco = $('#gv-palco'), aviso = $('#gv-aviso');
    var linha = $('#gv-linha'), faixas = $('#gv-faixas'), elCursor = $('#gv-cursor'), elSelecao = $('#gv-selecao');
    var inpDia = $('#gv-dia'), btTocar = $('#gv-tocar');

    var dia = /^\d{4}-\d{2}-\d{2}$/.test(params.get('dia') || '') ? params.get('dia') : D.hoje;
    var hoje = D.hoje;
    var inicioDia = 0, fimDia = 0, agoraServidor = 0;
    var trechos = [];          // tocáveis (fechados), em ordem
    var abertos = [];          // em gravação: aparecem na linha, não tocam
    var atual = -1;            // índice em trechos do arquivo carregado no vídeo
    var cursor = 0;            // instante (epoch) mostrado
    var querTocar = false;
    var esperaPosicao = null;  // segundos dentro do arquivo a aplicar quando ele carregar
    var zoom = 86400, janelaA = 0;
    var selIni = null, selFim = null;
    var arrastando = false;
    var encerrado = false;
    var pedidoDia = 0;
    // "?hora=HH:MM:SS" no endereço: só vira instante depois que o servidor disser quando o dia começa
    var horaPendente = /^\d{2}:\d{2}(:\d{2})?$/.test(params.get('hora') || '') ? params.get('hora') : null;

    function hms(t) {
        var s = Math.max(0, Math.min(86400, Math.round(t - inicioDia)));
        if (s >= 86400) return '24:00:00';
        return dois(Math.floor(s / 3600)) + ':' + dois(Math.floor((s % 3600) / 60)) + ':' + dois(s % 60);
    }
    function hm(t) { return hms(t).slice(0, 5); }
    function deHora(txt) {   // "HH:MM" ou "HH:MM:SS" → instante do dia mostrado
        var m = /^(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(txt || '');
        if (!m) return null;
        return inicioDia + (+m[1]) * 3600 + (+m[2]) * 60 + (+(m[3] || 0));
    }
    function dataLonga(d) {
        var p = d.split('-');
        var txt = new Intl.DateTimeFormat('pt-BR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
            .format(new Date(+p[0], +p[1] - 1, +p[2], 12));
        return txt.charAt(0).toUpperCase() + txt.slice(1);
    }
    function somaDia(d, n) {
        var p = d.split('-'), x = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n));
        return x.getUTCFullYear() + '-' + dois(x.getUTCMonth() + 1) + '-' + dois(x.getUTCDate());
    }
    function mostrarAviso(msg) {
        aviso.textContent = msg || '';
        aviso.hidden = !msg;
    }
    function sessaoTerminou() {
        if (encerrado) return;
        encerrado = true;
        video.pause();
        mostrarAviso('Sua sessão terminou. Entre de novo para continuar.');
        avisar('Sua sessão terminou. Entre de novo.', true);
    }
    /* fetch que entende "sessão terminou": o servidor redireciona para a tela de entrada */
    function pedir(url, opcoes) {
        return fetch(url, Object.assign({ credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'application/json' } }, opcoes || {}))
            .then(function (r) {
                if (r.headers && r.headers.get('X-G2W-Sessao') === 'substituida') { window.location.href = '/login?motivo=outro-aparelho'; throw new Error('sessao'); }
                if (r.type === 'opaqueredirect' || r.status === 401) { sessaoTerminou(); throw new Error('sessao'); }
                return r;
            });
    }

    // -------------------------------------------------- linha do tempo
    function limitarJanela() {
        janelaA = Math.max(inicioDia, Math.min(fimDia - zoom, janelaA));
    }
    function centrarJanela() {
        janelaA = cursor - zoom / 2;
        limitarJanela();
    }
    function pct(t) { return ((t - janelaA) / zoom) * 100; }

    function desenharLinha() {
        faixas.textContent = '';
        var jb = janelaA + zoom;
        trechos.concat(abertos).forEach(function (t) {
            var a = Math.max(t.inicio, janelaA), b = Math.min(t.fim, jb);
            if (b <= a) return;
            var i = el('i', { class: t.aberto ? 'aberto' : '' });
            i.style.left = pct(a) + '%';
            i.style.width = Math.max(0.1, pct(b) - pct(a)) + '%';
            faixas.appendChild(i);
        });
        var esc = $('#gv-escala');
        esc.textContent = '';
        for (var k = 0; k <= 4; k++) {
            var t = janelaA + (zoom * k) / 4;
            esc.appendChild(el('span', { text: zoom >= 86400 ? Math.round((t - inicioDia) / 3600) + 'h' : hm(t) }));
        }
        var mover = zoom < 86400;
        $('#gv-janela-antes').hidden = !mover;
        $('#gv-janela-depois').hidden = !mover;
        $('#gv-janela-antes').disabled = janelaA <= inicioDia;
        $('#gv-janela-depois').disabled = janelaA + zoom >= fimDia;
        desenharCursor();
        desenharSelecao();
    }
    function desenharCursor() {
        var dentro = cursor >= janelaA && cursor <= janelaA + zoom && inicioDia > 0;
        elCursor.hidden = !dentro;
        if (dentro) elCursor.style.left = pct(cursor) + '%';
        $('#gv-hora').textContent = inicioDia ? hms(cursor) : '--:--:--';
        linha.setAttribute('aria-valuenow', Math.round(cursor - inicioDia));
        linha.setAttribute('aria-valuetext', inicioDia ? hms(cursor) : '');
    }
    function desenharSelecao() {
        var ok = selIni !== null && selFim !== null && selFim > selIni;
        var a = ok ? Math.max(selIni, janelaA) : 0, b = ok ? Math.min(selFim, janelaA + zoom) : 0;
        elSelecao.hidden = !ok || b <= a;
        if (!elSelecao.hidden) {
            elSelecao.style.left = pct(a) + '%';
            elSelecao.style.width = Math.max(0.3, pct(b) - pct(a)) + '%';
        }
    }
    function moverCursor(t) {
        cursor = Math.max(inicioDia, Math.min(fimDia, t));
        if (!arrastando && (cursor < janelaA || cursor > janelaA + zoom)) { centrarJanela(); desenharLinha(); }
        else desenharCursor();
    }

    // ---------------------------------------------------------- vídeo
    function acharTrecho(t) {
        for (var i = 0; i < trechos.length; i++) {
            if (t < trechos[i].fim) return { i: i, dentro: t >= trechos[i].inicio };
        }
        return null;
    }
    function botaoTocar() {
        btTocar.textContent = querTocar ? 'Pausar' : 'Tocar';
    }
    function carregar(i, posicao) {
        atual = i;
        esperaPosicao = Math.max(0, posicao);
        video.src = '/gravacoes/midia?id=' + trechos[i].id;
        video.load();
    }
    /* Vai para o instante t. Fora de gravação, anda até a próxima que existir. */
    function irPara(t, calado) {
        if (encerrado) return;
        t = Math.max(inicioDia, Math.min(fimDia - 1, t));
        var a = acharTrecho(t);
        if (!a) {
            querTocar = false; botaoTocar();
            video.pause();
            moverCursor(t);
            mostrarAviso(trechos.length ? 'Não há gravação depois das ' + hm(t) + ' neste dia.' : 'Não há gravação desta câmera neste dia.');
            return;
        }
        var seg = trechos[a.i];
        if (!a.dentro) {
            if (!calado) mostrarAviso('Sem gravação às ' + hm(t) + '. Fui para a próxima, às ' + hms(seg.inicio) + '.');
            t = seg.inicio;
        } else if (!calado) {
            mostrarAviso('');
        }
        moverCursor(t);
        var pos = t - seg.inicio;
        if (a.i === atual && video.readyState >= 1 && !video.error) {
            video.currentTime = Math.min(pos, Math.max(0, (video.duration || pos) - 0.2));
            if (querTocar) video.play().catch(function () {});
        } else {
            carregar(a.i, pos);
        }
    }

    video.addEventListener('loadedmetadata', function () {
        if (esperaPosicao !== null) {
            video.currentTime = Math.min(esperaPosicao, Math.max(0, (video.duration || esperaPosicao) - 0.2));
            esperaPosicao = null;
        }
        video.playbackRate = parseFloat($('#gv-velocidade').value) || 1;
        if (querTocar) video.play().catch(function () { querTocar = false; botaoTocar(); });
    });
    video.addEventListener('timeupdate', function () {
        if (atual < 0 || arrastando || esperaPosicao !== null || video.seeking) return;
        moverCursor(trechos[atual].inicio + video.currentTime);
    });
    video.addEventListener('ended', function () {
        var prox = atual + 1;
        if (prox < trechos.length) {
            var salto = trechos[prox].inicio - trechos[atual].fim;
            mostrarAviso(salto > 5 ? 'Pulei ' + dur(salto) + ' sem gravação.' : '');
            moverCursor(trechos[prox].inicio);
            carregar(prox, 0);
            return;
        }
        if (dia === hoje) {   // pode ter fechado mais um arquivo enquanto tocava
            var fimAntes = trechos[atual].fim;
            carregarDia(fimAntes, true);
            return;
        }
        querTocar = false; botaoTocar();
        mostrarAviso('Fim das gravações deste dia.');
    });
    video.addEventListener('error', function () {
        if (!video.getAttribute('src') || encerrado) return;
        querTocar = false; botaoTocar();
        // sessão caída também dá erro no vídeo: confere antes de culpar o formato
        pedir('/gravacoes/dia?camera=' + cam.id + '&dia=' + dia).then(function () {
            mostrarAviso('Este navegador não conseguiu tocar este trecho (algumas câmeras gravam em H.265, que nem todo navegador toca). '
                + 'Use "Baixar trecho" e abra o arquivo no computador ou no celular.');
        }).catch(function () {});
    });
    video.addEventListener('pause', function () {
        if (!video.ended && !video.seeking && esperaPosicao === null && querTocar && !arrastando) { querTocar = false; botaoTocar(); }
    });
    video.addEventListener('play', function () { querTocar = true; botaoTocar(); });

    btTocar.addEventListener('click', function () {
        if (encerrado) return;
        if (querTocar) { querTocar = false; botaoTocar(); video.pause(); return; }
        querTocar = true; botaoTocar();
        if (atual < 0 || video.ended || video.error) irPara(cursor);
        else video.play().catch(function () { querTocar = false; botaoTocar(); });
    });
    $('#gv-menos10').addEventListener('click', function () { irPara(cursor - 10); });
    $('#gv-mais10').addEventListener('click', function () { irPara(cursor + 10); });
    $('#gv-velocidade').addEventListener('change', function () { video.playbackRate = parseFloat(this.value) || 1; });
    $('#gv-som').addEventListener('click', function () {
        video.muted = !video.muted;
        this.setAttribute('aria-pressed', video.muted ? 'false' : 'true');
        this.textContent = video.muted ? 'Som desligado' : 'Som ligado';
    });
    $('#gv-tela-cheia').addEventListener('click', function () {
        if (document.fullscreenElement) { document.exitFullscreen(); return; }
        if (palco.requestFullscreen) palco.requestFullscreen().catch(function () {});
        else if (video.webkitEnterFullscreen) video.webkitEnterFullscreen();   // iPhone
    });

    // -------------------------------------------- interação com a linha
    function instanteDoPonteiro(ev) {
        var r = linha.getBoundingClientRect();
        var f = Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width));
        return Math.round(janelaA + f * zoom);
    }
    linha.addEventListener('pointerdown', function (ev) {
        if (ev.button !== undefined && ev.button !== 0) return;
        if (!inicioDia) return;
        arrastando = true;
        try { linha.setPointerCapture(ev.pointerId); } catch (e) { /* sem captura: segue pelo movimento na própria linha */ }
        moverCursor(instanteDoPonteiro(ev));
    });
    linha.addEventListener('pointermove', function (ev) { if (arrastando) moverCursor(instanteDoPonteiro(ev)); });
    function soltar(ev) {
        if (!arrastando) return;
        arrastando = false;
        irPara(ev.type === 'pointercancel' ? cursor : instanteDoPonteiro(ev));
    }
    linha.addEventListener('pointerup', soltar);
    linha.addEventListener('pointercancel', soltar);
    linha.addEventListener('keydown', function (ev) {
        var p = PASSOS[zoom], d = 0;
        if (ev.key === 'ArrowRight' || ev.key === 'ArrowUp') d = p;
        else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowDown') d = -p;
        else if (ev.key === 'PageUp') d = p * 6;
        else if (ev.key === 'PageDown') d = -p * 6;
        else if (ev.key === 'Home') { ev.preventDefault(); if (trechos.length) irPara(trechos[0].inicio); return; }
        else if (ev.key === 'End') { ev.preventDefault(); if (trechos.length) irPara(trechos[trechos.length - 1].fim - 30); return; }
        else return;
        ev.preventDefault();
        irPara(cursor + d);
    });
    $$('.gv-zoom [data-zoom]').forEach(function (b) {
        b.addEventListener('click', function () {
            zoom = parseInt(b.getAttribute('data-zoom'), 10);
            $$('.gv-zoom [data-zoom]').forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
            centrarJanela();
            desenharLinha();
        });
    });
    $('#gv-janela-antes').addEventListener('click', function () { janelaA -= zoom / 2; limitarJanela(); desenharLinha(); });
    $('#gv-janela-depois').addEventListener('click', function () { janelaA += zoom / 2; limitarJanela(); desenharLinha(); });

    // ------------------------------------------------------------ dia
    function resumo() {
        var total = 0;
        trechos.concat(abertos).forEach(function (t) { total += Math.min(t.fim, fimDia) - Math.max(t.inicio, inicioDia); });
        var txt;
        if (!trechos.length && !abertos.length) txt = 'Não há gravação desta câmera neste dia.';
        else if (!trechos.length) txt = 'A gravação de hoje começou há pouco: o primeiro trecho fica disponível em até 5 minutos.';
        else txt = dur(total) + ' gravados neste dia, das ' + hm(trechos[0].inicio) + ' às ' + hm((abertos[0] || trechos[trechos.length - 1]).fim) + '.';
        $('#gv-resumo').textContent = txt;
    }
    /* irA: instante para onde ir depois de carregar (null = escolhe sozinho) */
    function carregarDia(irA, continuar) {
        var n = ++pedidoDia;
        if (!continuar) {
            video.pause(); video.removeAttribute('src'); video.load();
            atual = -1; querTocar = false; botaoTocar();
            mostrarAviso('Carregando as gravações do dia…');
        }
        pedir('/gravacoes/dia?camera=' + cam.id + '&dia=' + encodeURIComponent(dia))
            .then(function (r) { return r.json().then(function (j) { return { r: r, j: j }; }); })
            .then(function (x) {
                if (n !== pedidoDia) return;
                if (!x.r.ok || !x.j.ok) { mostrarAviso((x.j && x.j.erro) || 'Não foi possível carregar as gravações.'); return; }
                var j = x.j;
                inicioDia = j.inicio_dia; fimDia = j.fim_dia; agoraServidor = j.agora; hoje = j.hoje;
                trechos = j.trechos.filter(function (t) { return !t.aberto; });
                abertos = j.trechos.filter(function (t) { return t.aberto; });
                inpDia.value = dia;
                inpDia.max = hoje;
                if (j.primeiro_dia) inpDia.min = j.primeiro_dia;
                $('#gv-dia-depois').disabled = dia >= hoje;
                $('#gv-dia-antes').disabled = !!j.primeiro_dia && dia <= j.primeiro_dia;
                $('#gv-data').textContent = dataLonga(dia);
                resumo();
                if (!continuar) { selIni = selFim = null; $('#gv-t-inicio').value = ''; $('#gv-t-fim').value = ''; infoTrecho(); }

                if (continuar) {
                    var a = acharTrecho(irA + 1);
                    if (a) { desenharLinha(); atual = -1; irPara(Math.max(irA, trechos[a.i].inicio), true); }
                    else { querTocar = false; botaoTocar(); desenharLinha(); mostrarAviso('Você chegou ao fim do que já foi gravado.'); }
                    return;
                }
                if (horaPendente) { irA = deHora(horaPendente); horaPendente = null; }
                var alvo = irA;
                if (alvo === null || alvo === undefined) {
                    if (!trechos.length) alvo = inicioDia + (dia === hoje ? Math.max(0, agoraServidor - inicioDia - 60) : 43200);
                    else if (dia === hoje) alvo = Math.max(trechos[0].inicio, trechos[trechos.length - 1].fim - 300);
                    else alvo = trechos[0].inicio;
                }
                cursor = Math.max(inicioDia, Math.min(fimDia - 1, alvo));
                centrarJanela();
                desenharLinha();
                if (trechos.length) irPara(cursor, irA === null || irA === undefined);
                else mostrarAviso(abertos.length ? 'O primeiro trecho de hoje ainda está sendo gravado. Volte em alguns minutos.' : 'Não há gravação desta câmera neste dia.');
                if (trechos.length && (irA === null || irA === undefined)) mostrarAviso('');

                var u = new URL(window.location.href);
                u.searchParams.set('dia', dia);
                history.replaceState(null, '', u.pathname + u.search);
            })
            .catch(function (e) {
                if (n !== pedidoDia || (e && e.message === 'sessao')) return;
                mostrarAviso('Não foi possível falar com o servidor. Confira a conexão e tente de novo.');
            });
    }
    function trocarDia(novo, irA) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(novo) || novo > hoje) return;
        dia = novo;
        carregarDia(irA === undefined ? null : irA, false);
    }
    inpDia.addEventListener('change', function () { if (inpDia.value) trocarDia(inpDia.value); });
    $('#gv-dia-antes').addEventListener('click', function () { trocarDia(somaDia(dia, -1)); });
    $('#gv-dia-depois').addEventListener('click', function () { trocarDia(somaDia(dia, 1)); });
    $('#gv-form-hora').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var t = deHora($('#gv-ir-hora').value);
        if (t === null) return;
        irPara(t);
        linha.focus();
    });

    // -------------------------------------------------- baixar trecho
    var inpIni = $('#gv-t-inicio'), inpFim = $('#gv-t-fim'), erroTrecho = $('#gv-trecho-erro');
    var btBaixar = $('#gv-t-baixar'), btCancelar = $('#gv-t-cancelar');
    var baixando = null;   // AbortController do download em curso

    function erroT(msg) { erroTrecho.textContent = msg || ''; erroTrecho.hidden = !msg; }
    /* devolve o texto do problema, ou '' se o trecho marcado é válido */
    function infoTrecho() {
        selIni = deHora(inpIni.value);
        selFim = deHora(inpFim.value);
        var info = $('#gv-trecho-info'), problema = '';
        if (selIni === null || selFim === null) {
            info.textContent = '';
            problema = 'Marque o início e o fim do trecho.';
        } else if (selFim <= selIni) {
            info.textContent = '';
            problema = 'O fim precisa ser depois do início.';
        } else if (selFim - selIni > TRECHO_MAX) {
            info.textContent = 'Trecho marcado: ' + dur(selFim - selIni) + '.';
            problema = 'O trecho pode ter no máximo ' + Math.round(TRECHO_MAX / 60) + ' minutos. Diminua o intervalo.';
        } else {
            info.textContent = 'Trecho marcado: das ' + hms(selIni) + ' às ' + hms(selFim) + ' (' + dur(selFim - selIni) + ').';
        }
        desenharSelecao();
        return problema;
    }
    function marcar(inp) {
        if (!inicioDia) return;
        inp.value = hms(cursor);
        var p = infoTrecho();
        erroT(inpIni.value && inpFim.value ? p : '');
    }
    $('#gv-t-inicio-aqui').addEventListener('click', function () { marcar(inpIni); });
    $('#gv-t-fim-aqui').addEventListener('click', function () { marcar(inpFim); });
    [inpIni, inpFim].forEach(function (i) {
        i.addEventListener('change', function () { var p = infoTrecho(); erroT(inpIni.value && inpFim.value ? p : ''); });
    });

    function progresso(feito, total) {
        $('#gv-progresso').hidden = false;
        $('#gv-progresso-barra').value = total ? Math.round((feito / total) * 100) : 0;
        $('#gv-progresso-texto').textContent = total ? tam(feito) + ' de ' + tam(total) : 'Montando o trecho no servidor…';
    }
    function fimDownload() {
        baixando = null;
        btBaixar.disabled = false;
        btCancelar.hidden = true;
        $('#gv-progresso').hidden = true;
    }
    function nomeDoArquivo(r) {
        var m = /filename="([^"]+)"/.exec(r.headers.get('Content-Disposition') || '');
        return m ? m[1] : 'gravacao.mp4';
    }
    btCancelar.addEventListener('click', function () { if (baixando) baixando.abort(); });

    $('#gv-form-trecho').addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (baixando || encerrado) return;
        var problema = infoTrecho();
        if (problema) { erroT(problema); (selIni === null ? inpIni : inpFim).focus(); return; }
        erroT('');
        var ini = selIni, fim = selFim;
        var url = '/media/trecho?id=' + cam.id + '&inicio=' + ini + '&fim=' + fim;
        var recente = fim > agoraServidor - 330 && dia === hoje;
        baixando = new AbortController();
        btBaixar.disabled = true;
        btCancelar.hidden = false;
        progresso(0, 0);

        pedir(url, { signal: baixando.signal, headers: { Accept: 'video/mp4, application/json' } }).then(function (r) {
            if (!r.ok) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    var msg = j.erro || 'Não foi possível baixar o trecho.';
                    var espera = parseInt(r.headers.get('Retry-After') || '', 10);
                    if (r.status === 404 && recente) msg += ' Os últimos 5 minutos ainda estão sendo gravados; tente de novo daqui a pouco.';
                    if ((r.status === 429 || r.status === 503) && espera) msg += ' Tente de novo em ' + espera + ' segundos.';
                    erroT(msg);
                    fimDownload();
                });
            }
            var total = parseInt(r.headers.get('Content-Length') || '0', 10);
            var completo = r.headers.get('X-G2W-Trecho-Completo') !== '0';
            var veio = parseFloat(r.headers.get('X-G2W-Trecho-Duracao') || '0');
            var nome = nomeDoArquivo(r);
            function concluir() {
                var msg = 'Trecho baixado: ' + nome + '.';
                if (!completo) {
                    msg = 'Baixei o que existe gravado nesse intervalo: ' + dur(veio) + ' de ' + dur(fim - ini) + '.'
                        + (recente ? ' Os últimos 5 minutos ainda estão sendo gravados e não entram.' : ' Em parte do intervalo não há gravação.');
                }
                $('#gv-trecho-info').textContent = msg;
                avisar(completo ? 'Trecho baixado.' : 'Trecho baixado, mas incompleto.');
            }
            if (total > BLOB_MAX || !r.body || !window.ReadableStream) {
                // grande demais para montar na memória do navegador: entrega ao próprio navegador
                baixando.abort();
                fimDownload();
                $('#gv-trecho-info').textContent = 'O arquivo tem ' + tam(total) + '. O download segue pelo navegador.';
                setTimeout(function () { window.location.href = url; }, 1500);
                return;
            }
            var leitor = r.body.getReader(), partes = [], feito = 0;
            function ler() {
                return leitor.read().then(function (p) {
                    if (p.done) return;
                    partes.push(p.value);
                    feito += p.value.length;
                    progresso(feito, total);
                    return ler();
                });
            }
            return ler().then(function () {
                if (total && feito < total) throw new Error('incompleto');
                var blob = new Blob(partes, { type: 'video/mp4' });
                var a = el('a', { href: URL.createObjectURL(blob), download: nome });
                document.body.appendChild(a);
                a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 60000);
                fimDownload();
                concluir();
            });
        }).catch(function (e) {
            if (e && e.message === 'sessao') { fimDownload(); return; }
            var cancelado = e && e.name === 'AbortError';
            fimDownload();
            erroT(cancelado ? '' : 'O download foi interrompido. Confira a conexão e tente de novo.');
            if (cancelado) $('#gv-trecho-info').textContent = 'Download cancelado.';
        });
    });

    // ----------------------------------------------------------- início
    carregarDia(null, false);
})();
