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
        // Se a atualização falhar (rede, sessão expirada), a tela não pode
        // continuar parecendo atual: avisa de quando é a leitura mostrada.
        function desatualizada(sim) {
            var faixa = $('#visao-velha');
            if (!sim) { if (faixa) faixa.remove(); return; }
            if (faixa) return;
            faixa = el('p', { id: 'visao-velha', class: 'faixa-velha', role: 'alert' }, [
                'Não foi possível atualizar. O que aparece abaixo é a leitura das ' + $('#hora-pagina').textContent + '. ',
                el('a', { href: '/gestao', text: 'Recarregar' })
            ]);
            $('#conteudo').insertBefore(faixa, $('#conteudo').firstChild);
        }
        setInterval(function () {
            if (document.hidden) return;
            fetch('/gestao', { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                .then(function (r) { return r.ok && !r.redirected ? r.text() : null; })
                .then(function (html) {
                    var doc = html ? new DOMParser().parseFromString(html, 'text/html') : null;
                    var novo = doc ? doc.getElementById('visao-geral') : null;
                    if (!novo) { desatualizada(true); return; }
                    var hora = doc.getElementById('hora-pagina');
                    $('#visao-geral').replaceWith(novo);
                    if (hora) $('#hora-pagina').replaceWith(hora);
                    desatualizada(false);
                })
                .catch(function () { desatualizada(true); });
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
                Promise.resolve(true).then(function () {
                    ocupado(botao, true);
                    enviar('camera', dados).then(function (r) {
                        ocupado(botao, false);
                        if (r.ok) {
                            aviso(nome + ': ' + r.msg);
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
        // "Um aparelho por vez": chave da instalação
        var formSU = $('[data-form-sessao-unica]');
        if (formSU) {
            var caixaSU = formSU.elements.ligado, estadoSU = $('[data-estado]', formSU);
            caixaSU.addEventListener('change', function () {
                var quer = caixaSU.checked;
                caixaSU.disabled = true;
                enviar('sessao-unica', { ligado: quer }).then(function (r) {
                    caixaSU.disabled = false;
                    if (!r.ok) { caixaSU.checked = !quer; aviso(r.erro, 'erro'); return; }
                    estadoSU.textContent = r.ligado ? 'Ligado' : 'Desligado';
                    aviso(r.msg);
                });
            });
        }
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
                    el('td', { class: 'col-acao' }, [ver, el('a', { class: 'botao botao-leve', href: '/gestao/midia?id=' + t.id + '&baixar=1', text: 'Baixar', 'aria-label': 'Baixar trecho das ' + hm(t.inicio) })])
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

    // ---------------------------------------------------------------- mapa
    // Leaflet guardado em vendor/ (nada de CDN). As imagens do mapa vêm do
    // servidor público do OpenStreetMap; o navegador manda a ele só a origem
    // desta página (cabeçalho Referer), como a política de uso de lá exige.
    function criarMapa(alvo) {
        var m = window.L.map(alvo, { zoomControl: true });
        m.attributionControl.setPrefix(false);
        window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            referrerPolicy: 'strict-origin-when-cross-origin',
            attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>'
        }).addTo(m);
        return m;
    }
    var BRASIL = [[-33.8, -73.9], [5.3, -34.8]];
    function coordTexto(lat, lon) {
        var f = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 6, maximumFractionDigits: 6 });
        return f.format(lat) + ' ; ' + f.format(lon);
    }

    var escolherLocal = null;   // definida por iniciarLocalizacao()

    function iniciarLocalizacao() {
        var bruto = $('#dados-locais');
        var dlg = $('#dlg-local');
        if (!bruto || !dlg) return;
        var cams = JSON.parse(bruto.textContent);
        var porId = {};
        cams.forEach(function (c) { porId[c.id] = c; });
        var form = $('form', dlg);
        var campo = form.elements.endereco;
        var lista = $('#loc-resultados'), msg = $('#loc-busca-msg'), coord = $('#loc-coordenada');
        var botaoBuscar = $('#loc-buscar'), botaoRemover = $('#loc-remover');
        var mapa = null, marcador = null, atual = null;

        function semMapa() {
            erroForm(form, 'O mapa não carregou neste navegador. Recarregue a página.');
        }
        function marcar(lat, lon, aproximar) {
            if (!mapa) return;
            if (!marcador) {
                marcador = window.L.marker([lat, lon], { draggable: true, keyboard: true, title: 'Posição da câmera (arraste para ajustar)' }).addTo(mapa);
                marcador.on('dragend', function () { var p = marcador.getLatLng(); mostrarCoord(p.lat, p.lng); });
            } else {
                marcador.setLatLng([lat, lon]);
            }
            if (aproximar) mapa.setView([lat, lon], Math.max(mapa.getZoom(), 17));
            mostrarCoord(lat, lon);
        }
        function mostrarCoord(lat, lon) { coord.textContent = 'Posição marcada: ' + coordTexto(lat, lon) + '.'; }
        function desmarcar() {
            if (marcador) { marcador.remove(); marcador = null; }
            coord.textContent = 'Nenhum ponto marcado ainda.';
        }

        // Cadastro de câmera nova: o mesmo diálogo, mas a posição volta para o
        // formulário (ainda não há câmera para gravar).
        escolherLocal = function (nome, local, aoEscolher) {
            abrirLocal(null, { id: null, nome: nome || 'câmera nova', local: local, aoEscolher: aoEscolher });
        };

        function abrirLocal(id, avulso) {
            atual = avulso || porId[id];
            if (!atual) return;
            form.reset();
            lista.hidden = true;
            lista.textContent = '';
            msg.textContent = '';
            var mc = $('#loc-coord-msg'); if (mc) { mc.textContent = ''; mc.className = 'coord-msg'; }
            $('#dlg-local-t').textContent = 'Localização: ' + atual.nome;
            campo.value = atual.local ? atual.local.endereco : '';
            botaoRemover.hidden = !atual.local;
            abrir(dlg);
            if (!window.L) { semMapa(); return; }
            if (!mapa) {
                mapa = criarMapa($('#loc-mapa'));
                mapa.on('click', function (ev) { marcar(ev.latlng.lat, ev.latlng.lng, false); });
            }
            mapa.invalidateSize();
            desmarcar();
            if (atual.local) {
                mapa.setView([atual.local.latitude, atual.local.longitude], 17);
                marcar(atual.local.latitude, atual.local.longitude, false);
            } else {
                // sem posição ainda: começa perto das outras câmeras já marcadas, ou no país
                var outras = cams.filter(function (c) { return c.local; }).map(function (c) { return [c.local.latitude, c.local.longitude]; });
                if (outras.length) mapa.fitBounds(window.L.latLngBounds(outras).pad(0.3), { maxZoom: 16 });
                else mapa.fitBounds(BRASIL);
            }
            focoInicial(campo);
        }

        function buscar() {
            var q = campo.value.trim();
            erroForm(form, '');
            if (q.length < 3) { msg.textContent = 'Escreva pelo menos 3 letras do endereço para buscar.'; campo.focus(); return; }
            ocupado(botaoBuscar, true, 'Buscando…');
            msg.textContent = '';
            lista.hidden = true;
            lista.textContent = '';
            fetch('/gestao/buscar-endereco?q=' + encodeURIComponent(q), {
                credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-G2W-CSRF': csrf }
            }).then(function (r) {
                if (r.status === 401) window.location.href = '/login';
                return r.json();
            }).then(function (j) {
                ocupado(botaoBuscar, false);
                if (!j.ok) { msg.textContent = j.erro; return; }
                if (!j.resultados.length) { msg.textContent = 'Nenhum lugar encontrado com esse texto. Tente com cidade e bairro, ou marque direto no mapa.'; return; }
                j.resultados.forEach(function (r) {
                    var b = el('button', { type: 'button', text: r.nome });
                    b.addEventListener('click', function () {
                        marcar(r.latitude, r.longitude, true);
                        if (mapa) mapa.setView([r.latitude, r.longitude], 17);
                        lista.hidden = true;
                        msg.textContent = 'Ponto marcado. Confira no mapa e arraste o marcador se precisar.';
                    });
                    lista.appendChild(el('li', {}, [b]));
                });
                lista.hidden = false;
                msg.textContent = j.resultados.length + (j.resultados.length === 1 ? ' lugar encontrado' : ' lugares encontrados') + ' (busca: ' + j.servico + ').';
                $('button', lista).focus();
            }).catch(function () {
                ocupado(botaoBuscar, false);
                msg.textContent = 'Não foi possível buscar agora. Você pode marcar o ponto direto no mapa.';
            });
        }
        botaoBuscar.addEventListener('click', buscar);
        campo.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); buscar(); }
        });

        // Coordenadas coladas: lidas só aqui no navegador (coordenadas.js);
        // o servidor recebe os dois números ao salvar e confere a faixa.
        var campoCoord = $('#loc-coord'), msgCoord = $('#loc-coord-msg');
        function irParaCoord(lat, lon, texto) {
            marcar(lat, lon, true);
            if (mapa) mapa.setView([lat, lon], 17);
            msgCoord.textContent = texto || 'Ponto marcado em ' + coordTexto(lat, lon) + '. Confira no mapa e salve.';
            msgCoord.className = 'coord-msg';
        }
        function lerCoord() {
            msgCoord.textContent = '';
            msgCoord.className = 'coord-msg';
            if (!window.G2WCoordenadas) { msgCoord.textContent = 'Recarregue a página para usar as coordenadas.'; return; }
            if (!mapa) { semMapa(); return; }
            var r = window.G2WCoordenadas.ler(campoCoord.value);
            if (r.ok) {
                irParaCoord(r.lat, r.lon, r.aviso ? r.aviso : null);
                if (r.aviso) msgCoord.className = 'coord-msg coord-alerta';
                return;
            }
            msgCoord.className = 'coord-msg coord-alerta';
            msgCoord.appendChild(document.createTextNode(r.erro + ' '));
            if (r.sugestao) {
                var s = r.sugestao;
                var b = el('button', { type: 'button', class: 'botao botao-leve', text: 'Usar ' + coordTexto(s.lat, s.lon) });
                b.addEventListener('click', function () { campoCoord.value = coordTexto(s.lat, s.lon); irParaCoord(s.lat, s.lon); });
                msgCoord.appendChild(b);
            }
        }
        if (campoCoord) {
            $('#loc-ir').addEventListener('click', lerCoord);
            campoCoord.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); lerCoord(); }
            });
            // colar já marca (depois de o texto entrar no campo)
            campoCoord.addEventListener('paste', function () { setTimeout(lerCoord, 0); });
        }

        function refletir(local) {
            atual.local = local;
            var linha = $('[data-local-de="' + atual.id + '"]');
            if (!linha) return;
            $('[data-local-texto]', linha).textContent = local ? (local.endereco || 'Ponto marcado, sem endereço escrito') : 'Não definida';
            var b = $('[data-abrir-local]', linha);
            b.textContent = local ? 'Ajustar' : 'Definir';
            b.setAttribute('aria-label', (local ? 'Ajustar' : 'Definir') + ' a localização de ' + atual.nome);
        }

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (!atual) return;
            if (!marcador) { erroForm(form, 'Marque no mapa onde a câmera fica (clique no mapa ou use a busca).'); return; }
            var p = marcador.getLatLng();
            var botao = $('button[type="submit"]', form);
            ocupado(botao, true);
            if (atual.id === null) {
                atual.aoEscolher({ latitude: p.lat, longitude: p.lng, endereco: campo.value.trim() });
                dlg.close();
                return;
            }
            enviar('camera-local', { id: atual.id, latitude: p.lat, longitude: p.lng, endereco: campo.value.trim() }).then(function (r) {
                ocupado(botao, false);
                if (!r.ok) { erroForm(form, r.erro); return; }
                refletir(r.local);
                dlg.close();
                aviso(atual.nome + ': ' + r.msg);
            });
        });
        botaoRemover.addEventListener('click', function () {
            if (!atual) return;
            if (atual.id === null) { atual.aoEscolher(null); dlg.close(); return; }
            confirmar('Remover a localização de ' + atual.nome + '? A câmera some do mapa do painel e do aplicativo.', 'Remover').then(function (sim) {
                if (!sim) return;
                enviar('camera-local', { id: atual.id, remover: true }).then(function (r) {
                    if (!r.ok) { erroForm(form, r.erro); return; }
                    refletir(null);
                    dlg.close();
                    aviso(atual.nome + ': ' + r.msg);
                });
            });
        });

        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-abrir-local]');
            if (b) abrirLocal(parseInt(b.getAttribute('data-abrir-local'), 10));
        });
        var m = /local=(\d+)/.exec(window.location.hash);
        if (m && porId[m[1]]) abrirLocal(parseInt(m[1], 10));
    }

    // ------------------------------------ cadastro e remoção de câmera
    function mostrarTeste(caixa, t, erro) {
        caixa.textContent = '';
        caixa.className = 'resultado-teste';
        if (erro) {
            caixa.classList.add('teste-falha');
            caixa.appendChild(el('p', { text: erro }));
        } else if (t === null) {
            caixa.appendChild(el('p', { text: 'Testando… pode levar até 15 segundos.' }));
        } else {
            caixa.classList.add(!t.conectou ? 'teste-falha' : (t.avisos.length ? 'teste-atencao' : 'teste-ok'));
            caixa.appendChild(el('p', {}, [el('strong', { text: t.texto })]));
            (t.avisos || []).forEach(function (a) { caixa.appendChild(el('p', { class: 'teste-aviso', text: a })); });
        }
        caixa.hidden = false;
    }
    function testar(botao, caixa, dados) {
        ocupado(botao, true, 'Testando…');
        mostrarTeste(caixa, null);
        return enviar('camera-testar', dados).then(function (r) {
            ocupado(botao, false);
            if (r.ok) mostrarTeste(caixa, r.teste); else mostrarTeste(caixa, null, r.erro);
            return r;
        });
    }

    function iniciarCadastro() {
        var dlg = $('#dlg-nova');
        if (!dlg) return;
        var form = $('form', dlg), f = form.elements;
        var caixa = $('#n-teste'), textoLocal = $('#n-local-texto'), botaoLocal = $('#n-local');
        var local = null, caminhoPosto = '', sujo = false;

        function limpar() {
            form.reset();
            local = null;
            caminhoPosto = '';
            sujo = false;
            semModelo();
            caixa.hidden = true;
            refletirLocal();
        }
        function semModelo() {
            f.modelo.textContent = '';
            f.modelo.appendChild(el('option', { value: '', text: 'Não precisa escolher' }));
            f.modelo.disabled = true;
        }
        function refletirLocal() {
            textoLocal.textContent = local ? (local.endereco || 'Ponto marcado, sem endereço escrito') : 'Não definida';
            botaoLocal.textContent = local ? 'Ajustar' : 'Definir';
        }
        // o atalho só troca o caminho se o campo estiver vazio ou ainda com o que um atalho pôs
        function porCaminho(c) {
            if (f.caminho.value.trim() === '' || f.caminho.value === caminhoPosto) { f.caminho.value = c; caminhoPosto = c; }
        }
        function endereco() {
            return {
                host: f.host.value.trim(),
                porta: f.porta.value.trim() === '' ? 554 : Number(f.porta.value),
                usuario: f.usuario.value.trim(),
                senha: f.senha.value,
                caminho: f.caminho.value.trim()
            };
        }
        function conferir(comNome) {
            if (comNome && !f.nome.value.trim()) { erroForm(form, 'Dê um nome para a câmera.'); f.nome.focus(); return false; }
            if (!f.host.value.trim()) { erroForm(form, 'Informe o endereço IP ou o nome da câmera na rede.'); f.host.focus(); return false; }
            if (!f.caminho.value.trim()) { erroForm(form, 'Informe o caminho do vídeo, ou escolha o fabricante e o modelo para preencher.'); f.caminho.focus(); return false; }
            erroForm(form, '');
            return true;
        }

        $('#abrir-nova').addEventListener('click', function () {
            limpar();
            abrir(dlg);
            focoInicial(f.nome);
        });
        form.addEventListener('input', function () { sujo = true; });
        dlg.addEventListener('cancel', function (ev) {
            // Esc com formulário preenchido: não joga fora o que foi digitado sem perguntar
            if (sujo && !window.confirm('Sair sem adicionar a câmera? O que você preencheu será perdido.')) ev.preventDefault();
        });

        f.fabricante.addEventListener('change', function () {
            var op = f.fabricante.options[f.fabricante.selectedIndex];
            semModelo();
            if (op.value === '') return;
            if (op.value === 'atalho') { porCaminho(op.getAttribute('data-caminho')); return; }
            f.modelo.textContent = '';
            f.modelo.appendChild(el('option', { value: '', text: 'Carregando…' }));
            f.modelo.disabled = false;
            fetch('/gestao/modelos?fabricante=' + encodeURIComponent(op.value), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (r.status === 401) window.location.href = '/login'; return r.json(); })
                .then(function (j) {
                    f.modelo.textContent = '';
                    if (!j.ok || !j.modelos.length) {
                        f.modelo.appendChild(el('option', { value: '', text: 'Sem modelos conhecidos: digite o caminho' }));
                        return;
                    }
                    f.modelo.appendChild(el('option', { value: '', text: 'Escolha o modelo' }));
                    j.modelos.forEach(function (m) {
                        f.modelo.appendChild(el('option', { value: m.id, text: m.modelo, 'data-caminho': m.caminho }));
                    });
                })
                .catch(function () {
                    f.modelo.textContent = '';
                    f.modelo.appendChild(el('option', { value: '', text: 'Não foi possível carregar: digite o caminho' }));
                });
        });
        f.modelo.addEventListener('change', function () {
            var op = f.modelo.options[f.modelo.selectedIndex];
            if (op && op.value !== '') porCaminho(op.getAttribute('data-caminho'));
        });

        $('#n-testar').addEventListener('click', function () {
            if (!conferir(false)) return;
            testar(this, caixa, endereco());
        });

        botaoLocal.addEventListener('click', function () {
            if (!escolherLocal) return;
            escolherLocal(f.nome.value.trim(), local, function (novo) { local = novo; sujo = true; refletirLocal(); });
        });

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (!conferir(true)) return;
            var dados = endereco();
            dados.nome = f.nome.value.trim();
            dados.retencao_dias = f.retencao_dias.value.trim() === '' ? null : Number(f.retencao_dias.value);
            dados.gravacao = f.gravacao.value;
            dados.gravar_som = f.gravar_som.checked;
            if (!f.modelo.disabled && f.modelo.value) dados.modelo = f.modelo.value;
            if (local) dados.local = local;
            var botao = $('button[type="submit"]', form);
            ocupado(botao, true, 'Adicionando…');
            enviar('camera-nova', dados).then(function (r) {
                ocupado(botao, false);
                if (!r.ok) { erroForm(form, r.erro); return; }
                f.senha.value = '';
                sujo = false;
                pendente = false;
                try { sessionStorage.setItem('g2w-aviso', dados.nome + ': ' + r.msg); } catch (e) { /* sem armazenamento: só não mostra o aviso */ }
                window.location.hash = 'camera-' + r.id;
                window.location.reload();
            });
        });

        // aviso deixado antes de recarregar a página
        try {
            var deixado = sessionStorage.getItem('g2w-aviso');
            if (deixado) { sessionStorage.removeItem('g2w-aviso'); aviso(deixado); }
        } catch (e) { /* idem */ }
        var m = /^#camera-(\d+)$/.exec(window.location.hash);
        var alvo = m && $('[data-form-camera][data-id="' + m[1] + '"]');
        if (alvo) alvo.scrollIntoView({ block: 'center' });
    }

    function iniciarRemocao() {
        var dlg = $('#dlg-remover');
        if (!dlg) return;
        var form = $('form', dlg), botao = $('button[type="submit"]', form), andamento = $('#r-andamento');
        var atual = null, trabalhando = false;

        function rotulo() {
            botao.textContent = (atual && atual.guardado && form.elements.gravacoes.value === 'apagar') ? 'Remover e apagar gravações' : 'Remover câmera';
        }
        form.addEventListener('change', rotulo);
        dlg.addEventListener('cancel', function (ev) { if (trabalhando) ev.preventDefault(); });

        // apaga em voltas curtas até o servidor dizer que não resta nada
        function apagarAteOFim(id, restam, aoMudar) {
            if (!restam) return Promise.resolve({ ok: true });
            aoMudar(restam);
            return enviar('removida-apagar', { id: id, continuar: true }).then(function (r) {
                if (!r.ok) return r;
                if (!r.restam) return r;
                return new Promise(function (resolve) { setTimeout(resolve, 3000); })
                    .then(function () { return apagarAteOFim(id, r.restam, aoMudar); });
            });
        }
        function recarregar(msg) {
            try { sessionStorage.setItem('g2w-aviso', msg); } catch (e) { /* só não mostra o aviso */ }
            pendente = false;
            window.location.hash = '';
            window.location.reload();
        }

        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-remover]');
            if (b) {
                atual = { id: parseInt(b.getAttribute('data-remover'), 10), nome: b.getAttribute('data-nome'), guardado: b.getAttribute('data-guardado') };
                $('#r-prazo').textContent = b.getAttribute('data-prazo');
                form.reset();
                andamento.textContent = '';
                $('#dlg-remover-t').textContent = 'Remover câmera: ' + atual.nome;
                $('#r-guardado').textContent = atual.guardado;
                $('#r-opcoes').hidden = !atual.guardado;
                $('#r-sem-gravacao').hidden = !!atual.guardado;
                rotulo();
                abrir(dlg);
                return;
            }
            var a = ev.target.closest('[data-apagar-removida]');
            if (a) {
                var id = parseInt(a.getAttribute('data-apagar-removida'), 10), nome = a.getAttribute('data-nome');
                confirmar('Apagar do disco as gravações de ' + nome + ' (' + a.getAttribute('data-guardado') + ')? Não tem como desfazer.', 'Apagar gravações').then(function (sim) {
                    if (!sim) return;
                    ocupado(a, true, 'Apagando…');
                    enviar('removida-apagar', { id: id }).then(function (r) {
                        if (!r.ok) return r;
                        return apagarAteOFim(id, r.restam, function (n) { a.textContent = 'Apagando… restam ' + n; });
                    }).then(function (r) {
                        if (!r.ok) { ocupado(a, false); aviso(r.erro, 'erro'); return; }
                        recarregar(nome + ': gravações apagadas e espaço liberado.');
                    });
                });
            }
        });

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            if (!atual || trabalhando) return;
            var apagar = !!atual.guardado && form.elements.gravacoes.value === 'apagar';
            var nome = atual.nome, id = atual.id;
            trabalhando = true;
            ocupado(botao, true, 'Removendo…');
            $('[data-fechar]', form).disabled = true;
            enviar('camera-remover', { id: id, apagar_gravacoes: apagar }).then(function (r) {
                if (!r.ok || !apagar) return r;
                andamento.textContent = 'Câmera removida. Apagando as gravações…';
                // o gravador leva alguns segundos para fechar o arquivo que estava aberto
                return enviar('removida-apagar', { id: id, continuar: true }).then(function (r1) {
                    if (!r1.ok) return r1;
                    return apagarAteOFim(id, r1.restam, function (n) { andamento.textContent = 'Câmera removida. Apagando as gravações… restam ' + n + '.'; });
                }).then(function (r2) { return r2.ok ? r : { ok: true, parcial: true }; });
            }).then(function (r) {
                trabalhando = false;
                $('[data-fechar]', form).disabled = false;
                if (!r.ok) { ocupado(botao, false); rotulo(); erroForm(form, r.erro); return; }
                recarregar(r.parcial
                    ? nome + ': câmera removida, mas parte das gravações não foi apagada. Use “Continuar apagando” no fim da página.'
                    : nome + ': ' + (apagar ? 'câmera removida e gravações apagadas.' : r.msg));
            });
        });

        // teste de conexão de câmera já cadastrada (usa a senha guardada no servidor)
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-testar]');
            if (!b) return;
            var id = b.getAttribute('data-testar');
            testar(b, $('[data-teste-de="' + id + '"]'), { id: parseInt(id, 10) });
        });
    }

    function iniciarMapa() {
        var alvo = $('#mapa-cameras');
        if (!alvo) return;
        if (!window.L) { alvo.textContent = 'O mapa não carregou neste navegador. A lista abaixo traz as mesmas câmeras.'; return; }
        var cams = JSON.parse($('#dados-mapa').textContent);
        var mapa = criarMapa(alvo);
        var marcadores = {};
        cams.forEach(function (c) {
            // conteúdo montado com textContent: nome e endereço nunca viram HTML
            var caixa = el('div', {}, [
                el('strong', { text: c.nome }),
                el('span', { text: c.estado }),
                c.endereco ? el('div', { text: c.endereco }) : null,
                el('a', { href: '/gestao/cameras#local=' + c.id, text: 'Ajustar posição' }),
                el('a', { href: '/gestao/gravacoes#camera=' + c.id, text: 'Gravações' })
            ]);
            marcadores[c.id] = window.L.marker([c.latitude, c.longitude], { title: c.nome, alt: 'Câmera ' + c.nome }).addTo(mapa).bindPopup(caixa);
        });
        // O enquadramento depende do tamanho do mapa na tela. Se o Leaflet medir
        // antes de a página terminar de se arrumar, mede zero e abre no mundo
        // inteiro; por isso mede de novo quando o tamanho de fato aparece.
        var limites = window.L.latLngBounds(cams.map(function (c) { return [c.latitude, c.longitude]; })).pad(0.25);
        var enquadrado = false;
        function enquadrar() {
            mapa.invalidateSize();
            var t = mapa.getSize();
            if (t.x < 50 || t.y < 50) return;
            mapa.fitBounds(limites, { maxZoom: 17, animate: false });
            enquadrado = true;
        }
        mapa.setView(limites.getCenter(), 15, { animate: false });
        enquadrar();
        if (!enquadrado && window.ResizeObserver) {
            var olho = new ResizeObserver(function () { if (!enquadrado) enquadrar(); if (enquadrado) olho.disconnect(); });
            olho.observe(alvo);
        }
        window.addEventListener('load', function () { if (!enquadrado) enquadrar(); });
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('[data-ver-no-mapa]');
            if (!b) return;
            var mk = marcadores[b.getAttribute('data-ver-no-mapa')];
            if (!mk) return;
            mapa.setView(mk.getLatLng(), Math.max(mapa.getZoom(), 17));
            mk.openPopup();
            alvo.scrollIntoView({ block: 'nearest' });
        });
    }

    if (aba === 'visao') iniciarVisao();
    if (aba === 'cameras') { iniciarCameras(); iniciarLocalizacao(); iniciarCadastro(); iniciarRemocao(); }
    if (aba === 'mapa') iniciarMapa();
    if (aba === 'usuarios') iniciarUsuarios();
    if (aba === 'gravacoes') iniciarGravacoes();
})();
