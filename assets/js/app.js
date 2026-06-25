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

        // --- Carrossel de banners -------------------------------------------
        document.querySelectorAll('[data-carrossel]').forEach(function (raiz) {
            var trilho = raiz.querySelector('.carrossel-trilho');
            var slides = raiz.querySelectorAll('.carrossel-slide');
            var dots = raiz.querySelectorAll('[data-carrossel-dot]');
            var total = slides.length;
            if (!trilho || total <= 1) {
                return; // 1 slide: sem dots/autoplay/navegação
            }

            var reduz = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var atual = 0;
            var timer = null;

            function ir(i) {
                atual = (i + total) % total;
                trilho.style.transform = 'translateX(' + (-atual * 100) + '%)';
                dots.forEach(function (d, idx) {
                    d.classList.toggle('ativo', idx === atual);
                    d.setAttribute('aria-current', idx === atual ? 'true' : 'false');
                });
            }
            var prox = function () { ir(atual + 1); };
            var ant = function () { ir(atual - 1); };

            function iniciar() { if (!reduz) { parar(); timer = setInterval(prox, 5000); } }
            function parar() { if (timer) { clearInterval(timer); timer = null; } }
            function reiniciar() { parar(); iniciar(); }

            dots.forEach(function (d) {
                d.addEventListener('click', function () {
                    ir(parseInt(d.getAttribute('data-carrossel-dot'), 10) || 0);
                    reiniciar();
                });
            });
            var btnPrev = raiz.querySelector('[data-carrossel-prev]');
            var btnNext = raiz.querySelector('[data-carrossel-next]');
            if (btnPrev) { btnPrev.addEventListener('click', function () { ant(); reiniciar(); }); }
            if (btnNext) { btnNext.addEventListener('click', function () { prox(); reiniciar(); }); }

            raiz.addEventListener('mouseenter', parar);
            raiz.addEventListener('mouseleave', iniciar);

            // Arrastar/deslizar (swipe) com Pointer Events.
            var x0 = null, dx = 0, arrastando = false;
            trilho.addEventListener('pointerdown', function (e) {
                x0 = e.clientX; dx = 0; arrastando = true; parar();
            });
            trilho.addEventListener('pointermove', function (e) {
                if (arrastando && x0 !== null) { dx = e.clientX - x0; }
            });
            function fimArraste() {
                if (!arrastando) { return; }
                arrastando = false;
                if (Math.abs(dx) > 50) { (dx < 0 ? prox : ant)(); }
                x0 = null; dx = 0;
                iniciar();
            }
            trilho.addEventListener('pointerup', fimArraste);
            trilho.addEventListener('pointercancel', fimArraste);
            trilho.addEventListener('pointerleave', fimArraste);

            ir(0);
            iniciar();
        });

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
