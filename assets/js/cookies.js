/* =============================================================================
   Consentimento de cookies (LGPD) — genérico e reaproveitável, sem framework.

   - Guarda a escolha no cookie `cookie_consent` = "todos" | "essenciais".
   - Mostra o banner [data-cookie-banner] só quando NÃO há escolha salva.
   - Scripts NÃO-ESSENCIAIS ficam bloqueados como
       <script type="text/plain" data-cookie-src="URL"></script>
     e só são injetados (viram <script src>) quando a escolha for "todos".
   - [data-cookie-preferencias] (ex.: link no rodapé) reabre o banner.

   API: window.CookieConsent = { escolha(), definir(valor), abrir() }
   ============================================================================= */
(function (global) {
    'use strict';

    var CHAVE = 'cookie_consent';
    var UM_ANO = 60 * 60 * 24 * 365;

    function ler() {
        var m = document.cookie.match(/(?:^|;\s*)cookie_consent=([^;]*)/);
        return m ? decodeURIComponent(m[1]) : null;
    }
    function salvar(valor) {
        document.cookie = CHAVE + '=' + encodeURIComponent(valor)
            + '; path=/; max-age=' + UM_ANO + '; SameSite=Lax';
    }

    // Injeta os scripts não-essenciais bloqueados (só com consentimento "todos").
    function ativarNaoEssenciais() {
        document.querySelectorAll('script[type="text/plain"][data-cookie-src]').forEach(function (s) {
            if (s.getAttribute('data-cookie-ativado')) { return; }
            s.setAttribute('data-cookie-ativado', '1');
            var real = document.createElement('script');
            real.src = s.getAttribute('data-cookie-src');
            real.async = s.getAttribute('data-cookie-async') !== 'false';
            (document.body || document.head).appendChild(real);
        });
    }

    function aplicar(valor) {
        if (valor === 'todos') { ativarNaoEssenciais(); }
        // "essenciais" ou sem escolha: nada de não-essencial é carregado.
    }

    function iniciar() {
        var atual = ler();
        aplicar(atual);

        var banner = document.querySelector('[data-cookie-banner]');

        function esconder() { if (banner) { banner.classList.remove('cookie-aberto'); } }
        function mostrar() {
            if (!banner) { return; }
            banner.hidden = false;
            requestAnimationFrame(function () { banner.classList.add('cookie-aberto'); });
            var primeiro = banner.querySelector('button');
            if (primeiro) { primeiro.focus(); }
        }
        function escolher(valor) {
            salvar(valor);
            aplicar(valor);
            esconder();
        }

        if (banner) {
            var aceitar = banner.querySelector('[data-cookie-aceitar]');
            var essenciais = banner.querySelector('[data-cookie-essenciais]');
            if (aceitar) { aceitar.addEventListener('click', function () { escolher('todos'); }); }
            if (essenciais) { essenciais.addEventListener('click', function () { escolher('essenciais'); }); }
            if (!atual) { mostrar(); } // primeira visita (sem escolha): aparece
        }

        // Rever/alterar a escolha depois (LGPD): reabre o banner.
        document.querySelectorAll('[data-cookie-preferencias]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                mostrar();
            });
        });

        global.CookieConsent = { escolha: ler, definir: escolher, abrir: mostrar };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})(window);
