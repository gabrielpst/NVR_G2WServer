/* G2W Monitor — painel de gestão (/gestao). JavaScript puro, sem etapa de build. */
(function () {
    'use strict';

    var csrf = (document.querySelector('meta[name="g2w-csrf"]') || {}).content || '';
    var aba = document.body.getAttribute('data-aba');

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

    function aviso(texto, tipo) {
        var caixa = $('#avisos-flutuantes');
        var t = el('div', { class: 'toast toast-' + (tipo || 'ok'), text: texto });
        caixa.appendChild(t);
        setTimeout(function () { t.remove(); }, tipo === 'erro' ? 9000 : 5000);
    }

    function enviar(acao, dados) {
        return fetch('/gestao/' + acao, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-G2W-CSRF': csrf, 'Accept': 'application/json' },
            body: JSON.stringify(dados)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, erro: 'Resposta inesperada do servidor (' + r.status + ').' }; })
                .then(function (j) {
                    if (r.status === 401) { window.location.href = '/login'; }
                    return j;
                });
        }).catch(function () {
            return { ok: false, erro: 'Sem conexão com o servidor. Confira a rede e tente de novo.' };
        });
    }

    function ocupado(botao, sim, textoOcupado) {
        if (!botao) return;
        if (sim) {
            botao.dataset.texto = botao.textContent;
            botao.textContent = textoOcupado || 'Salvando…';
            botao.disabled = true;
        } else {
            botao.textContent = botao.dataset.texto || botao.textContent;
            botao.disabled = false;
        }
    }

    function erroForm(form, msg) {
        var p = $('.erro-form', form);
        if (!p) { if (msg) aviso(msg, 'erro'); return; }
        p.textContent = msg || '';
        p.hidden = !msg;
    }

    // Diálogos (<dialog> nativo: foco preso e Esc já vêm do navegador)
    function abrir(dlg) { erroForm(dlg, ''); dlg.showModal(); }
    // foco automático no primeiro campo só com mouse/teclado: no celular abriria o teclado por cima do diálogo
    function focoInicial(campo) { if (campo && window.matchMedia('(pointer: fine)').matches) campo.focus(); }
    document.addEventListener('click', function (ev) {
        var f = ev.target.closest('[data-fechar]');
        if (f) f.closest('dialog').close();
    });

    function confirmar(texto, rotuloOk) {
        return new Promise(function (resolve) {
            var dlg = $('#dlg-confirma');
            if (!dlg) { resolve(window.confirm(texto)); return; }
            $('[data-texto]', dlg).textContent = texto;
            $('[data-ok]', dlg).textContent = rotuloOk || 'Confirmar';
            var ok = false;
            var form = $('form', dlg);
            function aoEnviar() { ok = true; }
            function aoFechar() {
                form.removeEventListener('submit', aoEnviar);
                dlg.removeEventListener('close', aoFechar);
                resolve(ok);
            }
            form.addEventListener('submit', aoEnviar);
            dlg.addEventListener('close', aoFechar);
            dlg.showModal();
        });
    }

    // Aviso ao sair com alteração não salva
    var pendente = false;
    window.addEventListener('beforeunload', function (ev) {
        if (pendente) { ev.preventDefault(); ev.returnValue = ''; }
    });

    // ------------------------------------------------------- visão geral
    function iniciarVisao() {
        var raiz = $('#visao-geral');
        var seg = parseInt(raiz.getAttribute('data-atualizar'), 10) || 60;
        setInterval(function () {
            if (document.hidden) return;
            fetch('/gestao', { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                .then(function (r) { return r.ok && !r.redirected ? r.text() : null; })
                .then(function (html) {
                    if (!html) return;
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var novo = doc.getElementById('visao-geral');
                    var hora = doc.getElementById('hora-pagina');
                    if (!novo) return;
                    $('#visao-geral').replaceWith(novo);
                    if (hora) $('#hora-pagina').replaceWith(hora);
                })
                .catch(function () { /* fica com a última leitura */ });
        }, seg * 1000);
    }

    // ------------------------------------------------------------ câmeras
    function iniciarCameras() {
        function haPendente() {
            return $$('[data-form-camera] button[type="submit"], [data-form-disco] button[type="submit"]')
                .some(function (b) { return !b.disabled; });
        }
        function vigiar(form) {
            var botao = $('button[type="submit"]', form);
            function estado() { return $$('input, select', form).map(function (i) { return i.value; }).join('\u0001'); }
            var inicial = estado();
            form.addEventListener('input', function () {
                botao.disabled = (estado() === inicial);
                pendente = haPendente();
            });
            return function salvo() {
                inicial = estado();
                botao.disabled = true;
                pendente = haPendente();
            };
        }

        $$('[data-form-camera]').forEach(function (form) {
            var salvo = vigiar(form);
            var gravAntes = form.elements.gravacao.value;
            var fonteAntes = form.elements.fonte && !form.elements.fonte.readOnly ? form.elements.fonte.value.trim() : null;
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var botao = $('button[type="submit"]', form);
                var nome = form.elements.nome.value.trim();
                if (!nome) { aviso('Dê um nome para a câmera.', 'erro'); form.elements.nome.focus(); return; }
                var ret = form.elements.retencao_dias.value.trim();
                var dados = {
                    id: parseInt(form.getAttribute('data-id'), 10),
                    nome: nome,
                    retencao_dias: ret === '' ? null : Number(ret),
                    gravacao: form.elements.gravacao.value
                };
                if (fonteAntes !== null) dados.fonte = form.elements.fonte.value.trim();
                // Na versão atual do gravador, parar a gravação de uma câmera ou
                // trocar o endereço dela faz o gravador inteiro reiniciar.
                var reinicia = (dados.gravacao === 'nunca' && gravAntes !== 'nunca') || (fonteAntes !== null && dados.fonte !== fonteAntes);
                var segue = reinicia
                    ? confirmar('Esta mudança faz o gravador reiniciar: todas as câmeras ficam fora do ar por cerca de 1 minuto. Salvar agora?', 'Salvar e reiniciar')
                    : Promise.resolve(true);
                segue.then(function (sim) {
                    if (!sim) return;
                    ocupado(botao, true);
                    enviar('camera', dados).then(function (r) {
                        ocupado(botao, false);
                        if (r.ok) {
                            aviso(nome + ': ' + r.msg);
                            gravAntes = dados.gravacao;
                            if (fonteAntes !== null) fonteAntes = dados.fonte;
                            salvo();
                        } else { aviso(r.erro, 'erro'); }
                    });
                });
            });
        });

        $$('[data-form-disco]').forEach(function (form) {
            var salvo = vigiar(form);
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                var botao = $('button[type="submit"]', form);
                ocupado(botao, true);
                enviar('armazenamento', {
                    prioridade: parseInt(form.getAttribute('data-prioridade'), 10),
                    limite_max: parseInt(form.elements.limite_max.value, 10),
                    limite_min: parseInt(form.elements.limite_min.value, 10)
                }).then(function (r) {
                    ocupado(botao, false);
                    if (r.ok) { aviso(r.msg); salvo(); }
                    else { aviso(r.erro, 'erro'); }
                });
            });
        });
    }

    // ----------------------------------------------------------- usuários
    function iniciarUsuarios() {
        var D = JSON.parse($('#dados-usuarios').textContent);
        var usuarios = D.usuarios;
        // casa2 antes de casa10
        var ordem = new Intl.Collator('pt-BR', { numeric: true, sensitivity: 'base' });
        usuarios.sort(function (a, b) { return ordem.compare(a.login, b.login); });
        var porId = {};
        usuarios.forEach(function (u) { porId[u.id] = u; });
        var selecionados = {};
        var filtro = 'todos';
        var busca = '';
        var corpo = $('#tabela-usuarios tbody');
        var totalCams = D.cameras.length;

        var filtros = {
            todos: function () { return true; },
            trocar: function (u) { return u.trocar_senha; },
            nunca: function (u) { return u.ultimo_acesso === null; },
            desativados: function (u) { return !u.ativo; },
            admins: function (u) { return u.admin; }
        };

        var m = /filtro=(\w+)/.exec(window.location.hash);
        if (m && filtros[m[1]]) filtro = m[1];

        if (D.acessos_desde === null) {
            $('#nota-acessos').textContent = 'O registro de último acesso não está disponível neste servidor.';
        } else {
            $('#nota-acessos').textContent = 'O último acesso é registrado desde ' +
                new Date(D.acessos_desde * 1000).toLocaleDateString('pt-BR') + '.';
        }

        function situacao(u) {
            if (!u.ativo) return ['neutro', 'Desativado'];
            if (u.trocar_senha) return ['alerta', 'Sem senha própria'];
            if (u.admin) return ['info', 'Administrador'];
            return ['ok', 'Ativo'];
        }

        function visiveis() {
            var b = busca.toLowerCase();
            return usuarios.filter(function (u) {
                return filtros[filtro](u) && (!b || u.login.toLowerCase().indexOf(b) >= 0 || u.nome.toLowerCase().indexOf(b) >= 0);
            });
        }

        function desenhar() {
            var lista = visiveis();
            var frag = document.createDocumentFragment();
            lista.forEach(function (u) {
                var s = situacao(u);
                var cams = u.admin ? 'Todas' : (u.cameras.length === totalCams ? 'Todas' : (u.cameras.length === 0 ? 'Nenhuma' : u.cameras.length + ' de ' + totalCams));
                var marca = el('input', { type: 'checkbox', 'aria-label': 'Selecionar ' + u.login, 'data-id': u.id });
                marca.checked = !!selecionados[u.id];
                var tr = el('tr', {}, [
                    el('td', { class: 'col-marca' }, [marca]),
                    el('td', { 'data-rotulo': 'Login' }, [el('strong', { text: u.login }), u.online ? el('span', { class: 'selo-online', text: 'conectado' }) : null]),
                    el('td', { 'data-rotulo': 'Nome', text: u.nome || '—' }),
                    el('td', { 'data-rotulo': 'Situação' }, [el('span', { class: 'estado estado-' + s[0] }, [el('span', { class: 'ponto', 'aria-hidden': 'true' }), s[1]])]),
                    el('td', { 'data-rotulo': 'Último acesso', text: u.ultimo_acesso_txt || 'sem registro' }),
                    el('td', { 'data-rotulo': 'Câmeras', text: cams }),
                    el('td', { class: 'col-acao' }, [el('button', { type: 'button', class: 'botao botao-leve', 'data-editar': u.id, 'aria-label': 'Editar ' + u.login, text: 'Editar' })])
                ]);
                if (selecionados[u.id]) tr.className = 'marcada';
                frag.appendChild(tr);
            });
            corpo.textContent = '';
            corpo.appendChild(frag);
            $('#lista-vazia').hidden = lista.length > 0;
            $('#lista-total').textContent = lista.length === usuarios.length
                ? usuarios.length + ' usuários.'
                : 'Mostrando ' + lista.length + ' de ' + usuarios.length + ' usuários.';
            Object.keys(filtros).forEach(function (k) {
                $('[data-conta="' + k + '"]').textContent = '(' + usuarios.filter(filtros[k]).length + ')';
                $('[data-filtro="' + k + '"]').setAttribute('aria-pressed', k === filtro ? 'true' : 'false');
            });
            atualizarSelecao();
        }

        function idsSelecionados() { return Object.keys(selecionados).map(Number); }

        function atualizarSelecao() {
            var n = idsSelecionados().length;
            $('#barra-selecao').hidden = n === 0;
            $('#selecao-conta').textContent = n === 1 ? '1 usuário selecionado' : n + ' usuários selecionados';
            var vis = visiveis();
            var todos = $('#marcar-todos');
            var marcadosVis = vis.filter(function (u) { return selecionados[u.id]; }).length;
            todos.checked = vis.length > 0 && marcadosVis === vis.length;
            todos.indeterminate = marcadosVis > 0 && marcadosVis < vis.length;
        }

        corpo.addEventListener('change', function (ev) {
            var id = ev.target.getAttribute('data-id');
            if (!id) return;
            if (ev.target.checked) selecionados[id] = true; else delete selecionados[id];
            ev.target.closest('tr').className = ev.target.checked ? 'marcada' : '';
            atualizarSelecao();
        });
        $('#marcar-todos').addEventListener('change', function (ev) {
            visiveis().forEach(function (u) { if (ev.target.checked) selecionados[u.id] = true; else delete selecionados[u.id]; });
            desenhar();
        });
        $('#limpar-selecao').addEventListener('click', function () { selecionados = {}; desenhar(); });
        $('#busca').addEventListener('input', function (ev) { busca = ev.target.value.trim(); desenhar(); });
        $$('[data-filtro]').forEach(function (b) {
            b.addEventListener('click', function () {
                filtro = b.getAttribute('data-filtro');
                history.replaceState(null, '', filtro === 'todos' ? window.location.pathname : '#filtro=' + filtro);
                desenhar();
            });
        });

        function recarregar(msg) {
            if (msg) sessionStorage.setItem('g2w-aviso', msg);
            window.location.reload();
        }
        var guardado = sessionStorage.getItem('g2w-aviso');
        if (guardado) { sessionStorage.removeItem('g2w-aviso'); aviso(guardado); }

        function caixasCameras(form, marcadas) {
            var caixa = $('[data-cameras] .lista-cameras', form);
            caixa.textContent = '';
            if (!D.cameras.length) { caixa.appendChild(el('p', { class: 'vazio', text: 'Nenhuma câmera cadastrada.' })); return; }
            D.cameras.forEach(function (c) {
                var i = el('input', { type: 'checkbox', name: 'camera', value: c.id });
                i.checked = !marcadas || marcadas.indexOf(c.id) >= 0;
                caixa.appendChild(el('label', { class: 'opcao' }, [i, ' ' + c.nome]));
            });
        }
        function camerasMarcadas(form) {
            return $$('input[name="camera"]:checked', form).map(function (i) { return parseInt(i.value, 10); });
        }

        // --- editar / novo
        var dlgU = $('#dlg-usuario');
        var formU = $('form', dlgU);
        function abrirUsuario(u) {
            formU.reset();
            formU.elements.id.value = u ? u.id : '';
            $('#dlg-usuario-t').textContent = u ? 'Editar ' + u.login : 'Novo usuário';
            $('#u-senha-ajuda').textContent = u
                ? 'Deixe em branco para manter a senha atual.'
                : 'Mínimo de ' + D.senha_min + ' caracteres.';
            formU.elements.senha.required = !u;
            if (u) {
                ['login', 'nome', 'telefone', 'email', 'obs'].forEach(function (k) { formU.elements[k].value = u[k]; });
                ['app', 'site', 'gravacoes', 'trocar_senha', 'admin'].forEach(function (k) { formU.elements[k].checked = !!u[k]; });
            } else {
                formU.elements.app.checked = true;
                formU.elements.site.checked = true;
                formU.elements.trocar_senha.checked = true;
            }
            formU.elements.admin.disabled = !!(u && u.id === D.eu);
            caixasCameras(formU, u ? u.cameras : null);
            abrir(dlgU);
            focoInicial(formU.elements.login);
        }
        corpo.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-editar]');
            if (b) abrirUsuario(porId[b.getAttribute('data-editar')]);
        });
        formU.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var f = formU.elements;
            var dados = {
                id: f.id.value ? parseInt(f.id.value, 10) : null,
                login: f.login.value.trim(), nome: f.nome.value.trim(), senha: f.senha.value,
                telefone: f.telefone.value.trim(), email: f.email.value.trim(), obs: f.obs.value.trim(),
                app: f.app.checked, site: f.site.checked, gravacoes: f.gravacoes.checked,
                trocar_senha: f.trocar_senha.checked, admin: f.admin.checked,
                cameras: camerasMarcadas(formU)
            };
            if (!dados.login) { erroForm(formU, 'Informe o login.'); f.login.focus(); return; }
            if (!dados.id && dados.senha.length < D.senha_min) { erroForm(formU, 'A senha precisa ter pelo menos ' + D.senha_min + ' caracteres.'); f.senha.focus(); return; }
            var botao = $('button[type="submit"]', formU);
            ocupado(botao, true);
            enviar('usuario', dados).then(function (r) {
                ocupado(botao, false);
                if (r.ok) recarregar(r.msg); else erroForm(formU, r.erro);
            });
        });

        // --- criar vários
        var dlgL = $('#dlg-lote');
        var formL = $('form', dlgL);
        function previaLote() {
            var f = formL.elements;
            var p = f.prefixo.value.trim(), de = parseInt(f.de.value, 10), ate = parseInt(f.ate.value, 10);
            var alvo = $('#l-previa');
            if (!p || isNaN(de) || isNaN(ate) || ate < de) { alvo.textContent = ''; return; }
            var n = ate - de + 1;
            alvo.textContent = n > D.lote_max
                ? 'São ' + n + ' usuários — o máximo por vez é ' + D.lote_max + '.'
                : 'Serão criados ' + n + ' usuários: ' + p + de + (n > 1 ? ' até ' + p + ate : '') + '.';
        }
        formL.addEventListener('input', previaLote);
        formL.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var f = formL.elements;
            var dados = {
                prefixo: f.prefixo.value.trim(), nome_base: f.nome_base.value.trim(),
                de: parseInt(f.de.value, 10), ate: parseInt(f.ate.value, 10), senha: f.senha.value,
                trocar_senha: f.trocar_senha.checked, app: f.app.checked, site: f.site.checked, gravacoes: f.gravacoes.checked,
                cameras: camerasMarcadas(formL)
            };
            if (!dados.prefixo) { erroForm(formL, 'Informe o prefixo do login.'); f.prefixo.focus(); return; }
            if (isNaN(dados.de) || isNaN(dados.ate)) { erroForm(formL, 'Informe o número inicial e o final.'); (isNaN(dados.de) ? f.de : f.ate).focus(); return; }
            if (dados.senha.length < D.senha_min) { erroForm(formL, 'A senha inicial precisa ter pelo menos ' + D.senha_min + ' caracteres.'); f.senha.focus(); return; }
            var botao = $('button[type="submit"]', formL);
            ocupado(botao, true, 'Criando…');
            enviar('usuarios-lote', dados).then(function (r) {
                ocupado(botao, false);
                if (r.ok) recarregar(r.msg); else erroForm(formL, r.erro);
            });
        });

        // --- ações em lote
        function descreverAlvo() {
            var ids = idsSelecionados();
            if (ids.length === 1) return 'Usuário: ' + porId[ids[0]].login + '.';
            return ids.length + ' usuários selecionados.';
        }
        function acaoLote(acao, extra, form, botao) {
            var dados = Object.assign({ acao: acao, ids: idsSelecionados() }, extra || {});
            ocupado(botao, true, 'Aplicando…');
            return enviar('usuarios-acao', dados).then(function (r) {
                ocupado(botao, false);
                if (r.ok) recarregar(r.msg);
                else if (form) erroForm(form, r.erro);
                else aviso(r.erro, 'erro');
            });
        }
        var textosConfirma = {
            exigir_troca: ['vão ter que criar uma senha nova no próximo acesso. Enquanto não criarem, não entram no aplicativo antigo.', 'Exigir nova senha'],
            dispensar_troca: ['ficam liberados de criar senha nova e continuam com a senha atual.', 'Dispensar troca'],
            ativar: ['voltam a entrar pelo aplicativo e pelo site.', 'Ativar'],
            desativar: ['não vão mais conseguir entrar, e quem estiver conectado será desconectado. Os cadastros não são apagados.', 'Desativar']
        };
        $$('#barra-selecao [data-acao]').forEach(function (b) {
            b.addEventListener('click', function () {
                var acao = b.getAttribute('data-acao');
                var n = idsSelecionados().length;
                var t = textosConfirma[acao];
                confirmar((n === 1 ? porId[idsSelecionados()[0]].login + ': ' : n + ' usuários ') + t[0], t[1]).then(function (sim) {
                    if (sim) acaoLote(acao, null, null, b);
                });
            });
        });

        var formS = $('#dlg-senha form');
        formS.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var senha = formS.elements.senha.value;
            if (senha.length < D.senha_min) { erroForm(formS, 'A senha precisa ter pelo menos ' + D.senha_min + ' caracteres.'); formS.elements.senha.focus(); return; }
            acaoLote('redefinir_senha', { senha: senha, trocar_senha: formS.elements.trocar_senha.checked }, formS, $('button[type="submit"]', formS));
        });
        var formC = $('#dlg-cameras form');
        formC.addEventListener('submit', function (ev) {
            ev.preventDefault();
            acaoLote('definir_cameras', { cameras: camerasMarcadas(formC) }, formC, $('button[type="submit"]', formC));
        });

        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-abrir]');
            if (!b) return;
            var id = b.getAttribute('data-abrir');
            if (id === 'dlg-usuario') { abrirUsuario(null); return; }
            var dlg = document.getElementById(id);
            var form = $('form', dlg);
            form.reset();
            if (id === 'dlg-lote') { caixasCameras(form, null); previaLote(); }
            if (id === 'dlg-cameras') {
                var ids = idsSelecionados();
                caixasCameras(form, ids.length === 1 ? porId[ids[0]].cameras : null);
            }
            var alvo = $('[data-alvo]', dlg);
            if (alvo) alvo.textContent = descreverAlvo();
            abrir(dlg);
            focoInicial($('input:not([type="hidden"])', form));
        });

        desenhar();
    }

    // ---------------------------------------------------------- gravações
    function iniciarGravacoes() {
        var form = $('#form-gravacoes');
        if (!form || !form.elements.camera.options.length) return;
        var selCam = form.elements.camera, inpDia = form.elements.dia, inpHora = form.elements.hora;
        var trechos = [], inicioDia = 0, fimDia = 0, pedido = 0;

        var m = /camera=(\d+)/.exec(window.location.hash);
        if (m && $('option[value="' + m[1] + '"]', selCam)) selCam.value = m[1];
        var md = /dia=(\d{4}-\d{2}-\d{2})/.exec(window.location.hash);
        if (md) inpDia.value = md[1];

        function hm(t) {   // hora do servidor, a partir do início do dia
            var s = Math.max(0, Math.min(86400, t - inicioDia));
            if (s >= 86400) return '24:00';
            var h = Math.floor(s / 3600), mi = Math.floor((s % 3600) / 60);
            return (h < 10 ? '0' : '') + h + ':' + (mi < 10 ? '0' : '') + mi;
        }
        function dur(s) {
            s = Math.max(0, Math.round(s));
            var h = Math.floor(s / 3600), mi = Math.floor((s % 3600) / 60), se = s % 60;
            if (h) return h + ' h ' + mi + ' min';
            if (mi) return mi + ' min' + (se ? ' ' + se + ' s' : '');
            return se + ' s';
        }
        function tam(b) {
            if (b === null) return 'gravando…';
            if (b >= 1073741824) return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(b / 1073741824) + '\u00a0GB';
            return new Intl.NumberFormat('pt-BR').format(Math.max(1, Math.round(b / 1048576))) + '\u00a0MB';
        }
        function mostrar(id, sim) { $(id).hidden = !sim; }

        function tocar(t, focar) {
            var v = $('#g-video');
            v.src = '/gestao/midia?id=' + t.id;
            $('#g-baixar').href = '/gestao/midia?id=' + t.id + '&baixar=1';
            $('#g-titulo-trecho').textContent = 'Trecho das ' + hm(t.inicio) + ' às ' + hm(t.fim);
            mostrar('#g-reprodutor', true);
            $$('#g-linha .trecho').forEach(function (b) { b.classList.toggle('atual', b.getAttribute('data-id') === String(t.id)); });
            $$('#g-lista tr').forEach(function (r) { r.classList.toggle('marcada', r.getAttribute('data-id') === String(t.id)); });
            if (focar) { $('#g-titulo-trecho').focus(); $('#g-reprodutor').scrollIntoView({ block: 'nearest' }); }
            v.play().catch(function () { /* autoplay bloqueado ou formato não suportado */ });
        }

        function desenhar() {
            var linha = $('#g-linha'), lista = $('#g-lista');
            linha.textContent = '';
            lista.textContent = '';
            var total = 0;
            trechos.forEach(function (t) {
                var a = Math.max(t.inicio, inicioDia), b = Math.min(t.fim, fimDia);
                if (b <= a) return;
                total += b - a;
                var bt = el('button', { type: 'button', class: 'trecho', 'data-id': t.id,
                    'aria-label': 'Assistir trecho das ' + hm(t.inicio) + ' às ' + hm(t.fim), title: hm(t.inicio) + ' – ' + hm(t.fim) });
                bt.style.left = ((a - inicioDia) / 864) + '%';
                bt.style.width = Math.max(0.15, (b - a) / 864) + '%';
                bt.addEventListener('click', function () { tocar(t, true); });
                linha.appendChild(bt);

                var ver = el('button', { type: 'button', class: 'botao botao-leve', text: 'Assistir', 'aria-label': 'Assistir trecho das ' + hm(t.inicio) });
                ver.addEventListener('click', function () { tocar(t, true); });
                lista.appendChild(el('tr', { 'data-id': t.id }, [
                    el('td', { 'data-rotulo': 'Início', text: hm(t.inicio) }),
                    el('td', { 'data-rotulo': 'Fim', text: t.aberto ? 'gravando agora' : hm(t.fim) }),
                    el('td', { 'data-rotulo': 'Duração', text: dur(t.fim - t.inicio) }),
                    el('td', { 'data-rotulo': 'Tamanho', text: tam(t.bytes) }),
                    el('td', { class: 'col-acao' }, [ver, el('a', { class: 'botao botao-leve', href: '/gestao/midia?id=' + t.id + '&baixar=1', download: true, text: 'Baixar', 'aria-label': 'Baixar trecho das ' + hm(t.inicio) })])
                ]));
            });
            var tem = trechos.length > 0;
            mostrar('#g-resultado', tem);
            mostrar('#g-lista-caixa', tem);
            mostrar('#g-vazio', !tem);
            mostrar('#g-reprodutor', false);
            $('#g-video').removeAttribute('src');
            if (tem) {
                var limite = Math.min(fimDia, Math.floor(Date.now() / 1000));
                var falta = Math.max(0, (limite - inicioDia) - total);
                // no celular a linha rola de lado: já mostra onde está a gravação mais recente
                var caixa = $('.linha-tempo-caixa'), ultimo = linha.lastElementChild;
                if (ultimo && caixa.scrollWidth > caixa.clientWidth) caixa.scrollLeft = Math.max(0, ultimo.offsetLeft - caixa.clientWidth * 0.7);
                $('#g-resumo').textContent = trechos.length + ' trechos, ' + dur(total) + ' gravados' +
                    (falta > 300 ? ' — ' + dur(falta) + ' sem gravação neste dia.' : ' — dia completo.');
            }
        }

        function carregar() {
            if (!inpDia.value) return;
            var meu = ++pedido;
            history.replaceState(null, '', '#camera=' + selCam.value + '&dia=' + inpDia.value);
            mostrar('#g-erro', false);
            $('#g-proximo').disabled = inpDia.value >= inpDia.max;
            fetch('/gestao/trechos?camera=' + encodeURIComponent(selCam.value) + '&dia=' + encodeURIComponent(inpDia.value),
                { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (r.status === 401) window.location.href = '/login'; return r.json(); })
                .then(function (j) {
                    if (meu !== pedido) return;
                    if (!j.ok) throw new Error(j.erro);
                    trechos = j.trechos; inicioDia = j.inicio_dia; fimDia = j.fim_dia;
                    desenhar();
                })
                .catch(function (e) {
                    if (meu !== pedido) return;
                    trechos = [];
                    desenhar();
                    mostrar('#g-vazio', false);
                    $('#g-erro').textContent = (e && e.message && e.message !== 'Failed to fetch') ? e.message : 'Não foi possível buscar as gravações. Tente de novo.';
                    mostrar('#g-erro', true);
                });
        }

        function mudarDia(delta) {
            var p = inpDia.value.split('-').map(Number);
            var d = new Date(Date.UTC(p[0], p[1] - 1, p[2] + delta));
            var novo = d.toISOString().slice(0, 10);
            if (novo > inpDia.max) return;
            inpDia.value = novo;
            carregar();
        }
        $('#g-anterior').addEventListener('click', function () { mudarDia(-1); });
        $('#g-proximo').addEventListener('click', function () { mudarDia(1); });
        selCam.addEventListener('change', carregar);
        inpDia.addEventListener('change', carregar);
        inpHora.addEventListener('change', function () {
            if (!inpHora.value || !trechos.length) return;
            var p = inpHora.value.split(':').map(Number);
            var alvo = inicioDia + p[0] * 3600 + p[1] * 60;
            var achado = null;
            trechos.forEach(function (t) { if (!achado && t.fim > alvo) achado = t; });
            if (achado && achado.inicio <= alvo + 1800) tocar(achado, true);
            else aviso('Não há gravação perto das ' + inpHora.value + ' neste dia.', 'erro');
        });
        form.addEventListener('submit', function (ev) { ev.preventDefault(); carregar(); });
        carregar();
    }

    if (aba === 'visao') iniciarVisao();
    if (aba === 'cameras') iniciarCameras();
    if (aba === 'usuarios') iniciarUsuarios();
    if (aba === 'gravacoes') iniciarGravacoes();
})();
