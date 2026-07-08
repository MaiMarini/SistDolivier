<?php
/**
 * Banner de consentimento de cookies (LGPD). Começa oculto (hidden); o
 * cookies.js decide mostrar (só na primeira visita) e salva a escolha.
 */
?>
<div class="cookie-banner" data-cookie-banner role="dialog"
     aria-label="Aviso sobre cookies" aria-live="polite" hidden>
    <div class="cookie-card">
        <span class="cookie-icone" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M8 13v.01" />
                <path d="M12 17v.01" />
                <path d="M14 11v.01" />
                <path d="M16 15v.01" />
                <path d="M9 9v.01" />
                <path d="M12 3a3 3 0 0 0 3 3a3 3 0 0 0 3 3a9 9 0 1 1 -6 -6" />
            </svg>
        </span>
        <div class="cookie-corpo">
            <h2 class="cookie-titulo">Sobre os cookies</h2>
            <p class="cookie-texto">
                Usamos cookies para o site funcionar e melhorar a sua experiência.
                Saiba mais na <a href="<?= e(url('politica-de-privacidade')) ?>">Política de Privacidade</a>.
            </p>
            <div class="cookie-acoes">
                <button type="button" class="btn" data-cookie-aceitar>Aceitar todos</button>
                <button type="button" class="btn sec" data-cookie-essenciais>Só essenciais</button>
            </div>
        </div>
    </div>
</div>
