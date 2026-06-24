/* =============================================================================
   Comportamentos da interface (genéricos, sem dependências externas):
   - Abrir/fechar o menu no celular.
   - Abrir/fechar o modal de regras.
   - Habilitar o botão de finalizar só com o aceite marcado.
   ============================================================================= */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // --- Menu mobile -----------------------------------------------------
        var toggle = document.querySelector('[data-menu-toggle]');
        var menu = document.getElementById('menu');
        if (toggle && menu) {
            toggle.addEventListener('click', function () {
                menu.classList.toggle('aberto');
            });
        }

        // --- Modal (genérico, controlado por data-attributes) ----------------
        // Abrir: qualquer elemento com data-abrir-modal="ID_DO_MODAL".
        // Fechar: botão/elemento com data-fechar-modal dentro do modal,
        //         clique no fundo escuro, ou tecla ESC.
        function abrirModal(id) {
            var m = document.getElementById(id);
            if (m) { m.classList.add('aberto'); }
        }
        function fecharModal(m) {
            if (m) { m.classList.remove('aberto'); }
        }

        document.querySelectorAll('[data-abrir-modal]').forEach(function (el) {
            el.addEventListener('click', function (ev) {
                ev.preventDefault();
                abrirModal(el.getAttribute('data-abrir-modal'));
            });
        });

        document.querySelectorAll('.modal').forEach(function (modal) {
            modal.addEventListener('click', function (ev) {
                // Clique no fundo (fora do conteúdo) fecha.
                if (ev.target === modal) { fecharModal(modal); }
            });
            modal.querySelectorAll('[data-fechar-modal]').forEach(function (botao) {
                botao.addEventListener('click', function () { fecharModal(modal); });
            });
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                document.querySelectorAll('.modal.aberto').forEach(fecharModal);
            }
        });

        // --- Abas (ex.: Entrar / Criar conta) -------------------------------
        // Botões com data-aba="X" mostram o painel com data-painel="X".
        var botoesAba = document.querySelectorAll('[data-aba]');
        if (botoesAba.length) {
            botoesAba.forEach(function (botao) {
                botao.addEventListener('click', function () {
                    var alvo = botao.getAttribute('data-aba');
                    document.querySelectorAll('[data-aba]').forEach(function (b) {
                        b.classList.toggle('ativa', b.getAttribute('data-aba') === alvo);
                    });
                    document.querySelectorAll('[data-painel]').forEach(function (p) {
                        p.classList.toggle('ativo', p.getAttribute('data-painel') === alvo);
                    });
                });
            });
        }

        // --- Galeria do produto (troca a imagem principal) ------------------
        var galeriaPrincipal = document.getElementById('galeria-principal');
        var galeriaThumbs = document.querySelectorAll('[data-galeria-img]');
        if (galeriaPrincipal && galeriaThumbs.length) {
            galeriaThumbs.forEach(function (thumb) {
                thumb.addEventListener('click', function () {
                    var src = thumb.getAttribute('data-src');
                    if (src) { galeriaPrincipal.src = src; }
                    galeriaThumbs.forEach(function (x) { x.classList.remove('ativa'); });
                    thumb.classList.add('ativa');
                });
            });
        }

        // --- Aceite de termos habilita o botão de finalizar -----------------
        var aceite = document.getElementById('aceite');
        var btnFinalizar = document.getElementById('btn-finalizar');
        if (aceite && btnFinalizar) {
            var sincronizar = function () {
                btnFinalizar.disabled = !aceite.checked;
            };
            sincronizar(); // estado inicial
            aceite.addEventListener('change', sincronizar);
        }
    });
})();
