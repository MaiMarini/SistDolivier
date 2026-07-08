/* =============================================================================
   Notificações reaproveitáveis (genérico, sem dependências e sem nada
   específico do admin — pode ser incluído também nas páginas da loja).

   API global:
     notificar(tipo, mensagem[, opcoes])   -> toast ('sucesso' | 'erro')
     confirmar(mensagem, aoConfirmar[, opcoes]) -> modal estilizado
     Notificacoes.erroCampo(input, mensagem)    -> marca campo inválido
     Notificacoes.limparErro(input)             -> limpa o erro do campo

   Auto-wiring (aplicado no DOMContentLoaded, e reaplicável via
   Notificacoes.ligar(root)):
     - [data-confirmar="msg"] em <form>/<a>/<button> troca o confirm() nativo
       pelo modal. Extras: data-confirmar-ok, data-confirmar-titulo.
     - <form data-validar> valida campos [required] com o padrão de erro.
   ============================================================================= */
(function (global) {
    'use strict';

    var svg = function (corpo) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            + ' stroke-linejoin="round" aria-hidden="true">' + corpo + '</svg>';
    };
    var ICONES = {
        sucesso: svg('<path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M9 12l2 2l4 -4"/>'),
        erro: svg('<path d="M10.24 3.957l-8.422 14.06a1.989 1.989 0 0 0 1.7 2.983h16.845a1.989 1.989 0 0 0 1.7 -2.983l-8.423 -14.06a1.989 1.989 0 0 0 -3.4 0z"/><path d="M12 9v4"/><path d="M12 16h.01"/>'),
        fechar: svg('<path d="M18 6l-12 12"/><path d="M6 6l12 12"/>'),
        campo: svg('<path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"/><path d="M12 8v4"/><path d="M12 16h.01"/>')
    };

    function containerToasts() {
        var c = document.querySelector('.ntf-toasts');
        if (!c) {
            c = document.createElement('div');
            c.className = 'ntf-toasts';
            c.setAttribute('aria-live', 'polite');
            document.body.appendChild(c);
        }
        return c;
    }

    /** Toast no canto da tela. tipo: 'sucesso' | 'erro'. Some após opcoes.duracao (ms). */
    function notificar(tipo, mensagem, opcoes) {
        opcoes = opcoes || {};
        tipo = (tipo === 'erro') ? 'erro' : 'sucesso';
        var dur = typeof opcoes.duracao === 'number' ? opcoes.duracao : 4000;

        var t = document.createElement('div');
        t.className = 'ntf-toast ntf-' + tipo;
        t.setAttribute('role', tipo === 'erro' ? 'alert' : 'status');
        t.innerHTML =
            '<span class="ntf-toast-icone">' + (tipo === 'erro' ? ICONES.erro : ICONES.sucesso) + '</span>'
            + '<span class="ntf-toast-msg"></span>'
            + '<button type="button" class="ntf-toast-fechar" aria-label="Fechar">' + ICONES.fechar + '</button>';
        t.querySelector('.ntf-toast-msg').textContent = String(mensagem == null ? '' : mensagem);

        containerToasts().appendChild(t);
        requestAnimationFrame(function () { t.classList.add('ntf-entrando'); });

        var timer = null;
        function fechar() {
            if (t._fechado) { return; }
            t._fechado = true;
            clearTimeout(timer);
            t.classList.remove('ntf-entrando');
            t.classList.add('ntf-saindo');
            setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 260);
        }
        t.querySelector('.ntf-toast-fechar').addEventListener('click', fechar);
        if (dur > 0) { timer = setTimeout(fechar, dur); }
        return fechar;
    }

    /** Modal de confirmação. Só chama aoConfirmar() no botão OK. */
    function confirmar(mensagem, aoConfirmar, opcoes) {
        opcoes = opcoes || {};
        var overlay = document.createElement('div');
        overlay.className = 'ntf-modal-overlay';
        overlay.innerHTML =
            '<div class="ntf-modal" role="dialog" aria-modal="true">'
            + '<h2 class="ntf-modal-titulo"></h2>'
            + '<p class="ntf-modal-texto"></p>'
            + '<div class="ntf-modal-acoes">'
            + '<button type="button" class="ntf-btn ntf-btn-cancelar"></button>'
            + '<button type="button" class="ntf-btn ntf-btn-ok"></button>'
            + '</div></div>';
        overlay.querySelector('.ntf-modal-titulo').textContent = opcoes.titulo || 'Confirmar';
        overlay.querySelector('.ntf-modal-texto').textContent = String(mensagem == null ? '' : mensagem);
        var btnOk = overlay.querySelector('.ntf-btn-ok');
        var btnCancelar = overlay.querySelector('.ntf-btn-cancelar');
        btnOk.textContent = opcoes.ok || 'Confirmar';
        btnCancelar.textContent = opcoes.cancelar || 'Cancelar';

        document.body.appendChild(overlay);
        requestAnimationFrame(function () { overlay.classList.add('ntf-aberto'); });
        btnOk.focus();

        function fechar() {
            overlay.classList.remove('ntf-aberto');
            document.removeEventListener('keydown', onKey);
            setTimeout(function () { if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); } }, 220);
        }
        function onKey(e) { if (e.key === 'Escape') { fechar(); } }

        btnCancelar.addEventListener('click', function () {
            fechar();
            if (typeof opcoes.aoCancelar === 'function') { opcoes.aoCancelar(); }
        });
        btnOk.addEventListener('click', function () {
            fechar();
            if (typeof aoConfirmar === 'function') { aoConfirmar(); }
        });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { fechar(); } });
        document.addEventListener('keydown', onKey);
    }

    function campoDe(input) {
        return (input.closest && input.closest('.campo')) || input.parentNode;
    }
    /** Marca o campo como inválido e mostra a mensagem abaixo (some ao editar). */
    function erroCampo(input, mensagem) {
        if (!input) { return; }
        var campo = campoDe(input);
        campo.classList.add('tem-erro');
        var msg = campo.querySelector('.campo-erro-msg');
        if (!msg) {
            msg = document.createElement('span');
            msg.className = 'campo-erro-msg';
            campo.appendChild(msg);
        }
        msg.innerHTML = ICONES.campo + '<span></span>';
        msg.querySelector('span').textContent = String(mensagem == null ? '' : mensagem);
        input.addEventListener('input', function limpar() {
            limparErro(input);
            input.removeEventListener('input', limpar);
        });
    }
    function limparErro(input) {
        if (!input) { return; }
        var campo = campoDe(input);
        campo.classList.remove('tem-erro');
        var msg = campo.querySelector('.campo-erro-msg');
        if (msg && msg.parentNode) { msg.parentNode.removeChild(msg); }
    }

    /** Valida os campos [required] do formulário com o padrão de erro da marca.
        Marca os vazios, foca o 1º inválido e retorna true se estiver tudo ok. */
    function validar(form, opcoes) {
        opcoes = opcoes || {};
        var invalidos = [];
        form.querySelectorAll('[required]').forEach(function (campo) {
            if ((campo.value || '').trim() === '') {
                invalidos.push(campo);
                erroCampo(campo, opcoes.mensagem || 'Preencha este campo.');
            } else {
                limparErro(campo);
            }
        });
        if (invalidos.length) { invalidos[0].focus(); }
        return invalidos.length === 0;
    }

    /** Auto-wiring de [data-confirmar] e de <form data-validar>. */
    function ligar(root) {
        root = root || document;

        root.querySelectorAll('[data-confirmar]').forEach(function (el) {
            if (el._ntfConfirmar) { return; }
            el._ntfConfirmar = true;
            var msg = el.getAttribute('data-confirmar');
            var opc = {
                ok: el.getAttribute('data-confirmar-ok') || 'Confirmar',
                titulo: el.getAttribute('data-confirmar-titulo') || 'Confirmar'
            };
            if (el.tagName.toLowerCase() === 'form') {
                el.addEventListener('submit', function (e) {
                    if (el._ntfOk) { el._ntfOk = false; return; } // já confirmado: segue
                    e.preventDefault();
                    confirmar(msg, function () {
                        el._ntfOk = true;
                        if (el.requestSubmit) { el.requestSubmit(); } else { el.submit(); }
                    }, opc);
                });
            } else {
                el.addEventListener('click', function (e) {
                    e.preventDefault();
                    var href = el.getAttribute('href');
                    confirmar(msg, function () { if (href) { global.location.href = href; } }, opc);
                });
            }
        });

        root.querySelectorAll('form[data-validar]').forEach(function (form) {
            if (form._ntfValidar) { return; }
            form._ntfValidar = true;
            form.setAttribute('novalidate', 'novalidate'); // sem balão nativo
            form.addEventListener('submit', function (e) {
                if (!validar(form)) {
                    e.preventDefault();
                    notificar('erro', 'Confira os campos destacados.');
                }
            });
        });
    }

    function iniciar() { ligar(document); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    global.Notificacoes = {
        notificar: notificar,
        confirmar: confirmar,
        erroCampo: erroCampo,
        limparErro: limparErro,
        validar: validar,
        ligar: ligar
    };
    global.notificar = notificar;
    global.confirmar = confirmar;
})(window);
