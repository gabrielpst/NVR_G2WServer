/* G2W Monitor — telas de entrada (/login e /changepwd). Envia o formulário sem recarregar
 * e mostra a resposta do servidor em português. Sem biblioteca. */
(function () {
    'use strict';

    var form = document.querySelector('form[data-entrar]');
    if (!form) return;

    var erro = form.querySelector('.erro-form');
    var botao = form.querySelector('button[type="submit"]');
    var rotulo = botao.textContent;

    // o servidor responde em inglês (as mesmas frases servem ao app); aqui só a tela traduz
    var TRADUCAO = {
        'Wrong login/password combination, please try again.': 'Usuário ou senha incorretos.',
        'You do not have permission to use web interface': 'Este usuário não tem permissão para entrar pelo navegador.',
        'You do not have permission to use remote client': 'Este usuário não tem permissão para entrar pelo aplicativo.',
        'Login field can not be empty.': 'Informe o usuário.',
        'Password field can not be empty.': 'Informe a senha.',
        'PASSWORDS DO NOT MATCH': 'As duas senhas não são iguais.',
        'FAIL': 'Não foi possível salvar a senha. Tente de novo.'
    };

    function mostrarErro(texto) {
        erro.textContent = texto;
        erro.hidden = false;
    }

    function ocupado(sim) {
        botao.disabled = sim;
        botao.textContent = sim ? botao.getAttribute('data-ocupado') || rotulo : rotulo;
    }

    Array.prototype.forEach.call(form.querySelectorAll('.ver-senha'), function (b) {
        b.addEventListener('click', function () {
            var campo = document.getElementById(b.getAttribute('aria-controls'));
            var mostrar = campo.type === 'password';
            campo.type = mostrar ? 'text' : 'password';
            b.textContent = mostrar ? 'Ocultar' : 'Mostrar';
            b.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
        });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        erro.hidden = true;

        var vazio = form.querySelector('input:required:placeholder-shown');
        if (vazio) {
            mostrarErro(vazio.getAttribute('data-vazio') || 'Preencha todos os campos.');
            vazio.focus();
            return;
        }
        var confirma = form.querySelector('[name="confirm_password"]');
        if (confirma && confirma.value !== form.querySelector('[name="password"]').value) {
            mostrarErro(TRADUCAO['PASSWORDS DO NOT MATCH']);
            confirma.focus();
            return;
        }

        ocupado(true);
        fetch(form.getAttribute('action'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new URLSearchParams(new FormData(form))
        }).then(function (r) {
            return r.json();
        }).then(function (resp) {
            // o servidor manda a mensagem dentro de uma lista
            var msg = String(Array.isArray(resp.msg) ? resp.msg[0] : resp.msg);
            if (parseInt(resp.status, 10) === 1) {
                window.location.href = msg;
                return;
            }
            // na troca de senha, sessão vencida volta como endereço da tela de entrada
            if (msg.charAt(0) === '/') {
                window.location.href = msg;
                return;
            }
            ocupado(false);
            mostrarErro(TRADUCAO[msg] || 'Não foi possível entrar. Tente de novo.');
        }).catch(function () {
            ocupado(false);
            mostrarErro('Sem resposta do servidor. Confira a conexão e tente de novo.');
        });
    });
})();
