<?php
/**
 * Rodapé do site, em TRÊS colunas:
 *   1) Marca (logo + nome + slogan) — fixos
 *   2) Navegação (links fixos)
 *   3) Redes sociais (de settings; link montado aqui; só as preenchidas)
 * Textos da marca/links são fixos; valores de settings são escapados com e().
 */

// Ícones (SVG inline, monocromáticos via currentColor).
$icones = [
    'WhatsApp'  => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.82 11.82 0 018.413 3.488 11.82 11.82 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.149-.173.198-.297.298-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.876 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>',
    'Instagram' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
    'TikTok'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>',
    'Facebook'  => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
    'Pinterest' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.688 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738a.36.36 0 01.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12.017 24c6.624 0 11.99-5.367 11.99-11.987C24.007 5.367 18.641.001 12.017.001z"/></svg>',
];

// Redes sociais: monta o link a partir das chaves de settings (só as preenchidas).
$redes = [];

$wpp = preg_replace('/\D+/', '', (string) cfg('whatsapp_numero', ''));
if ($wpp !== '') {
    $redes['WhatsApp'] = 'https://wa.me/' . $wpp;
}
$ig = trim((string) cfg('instagram_usuario', ''));
if ($ig !== '') {
    $redes['Instagram'] = 'https://instagram.com/' . ltrim($ig, '@');
}
$tt = trim((string) cfg('tiktok_usuario', ''));
if ($tt !== '') {
    $redes['TikTok'] = 'https://tiktok.com/@' . ltrim($tt, '@');
}
$fb = trim((string) cfg('facebook_url', ''));
if ($fb !== '') {
    $redes['Facebook'] = $fb;
}
$pin = trim((string) cfg('pinterest_url', ''));
if ($pin !== '') {
    $redes['Pinterest'] = $pin;
}
?>
<footer class="rodape">
    <!-- Borda superior em arcos largos com fio dourado (estica em qualquer largura) -->
    <svg class="rodape-curva" viewBox="0 0 1200 80" preserveAspectRatio="none" aria-hidden="true">
        <path class="rodape-curva-fio"
              d="M0,38 Q150,8 300,38 T600,38 T900,38 T1200,38"/>
        <path class="rodape-curva-corpo"
              d="M0,52 Q150,22 300,52 T600,52 T900,52 T1200,52 L1200,80 L0,80 Z"/>
    </svg>

    <div class="container">
        <div class="rodape-grid">
            <!-- Coluna 1: marca -->
            <div class="rodape-marca-bloco">
                <img class="rodape-logo-img" src="<?= e(asset('Logo/trigo.png')) ?>"
                     height="46" alt="D'Olivier Confeitaria Artesanal">
                <span class="rodape-marca-texto">
                    <span class="rodape-marca">D'Olivier</span>
                    <span class="rodape-slogan">Confeitaria Artesanal</span>
                </span>
            </div>

            <!-- Coluna 2: navegação (fixa) -->
            <nav class="rodape-col rodape-nav">
                <a href="<?= e(url('sobre')) ?>">Sobre nós</a>
                <a href="<?= e(url('meus-pedidos')) ?>">Meus pedidos</a>
                <a href="<?= e(url('regras')) ?>">Regras e prazos</a>
                <a href="<?= e(url('politica-de-privacidade')) ?>">Política de privacidade</a>
                <a href="#" data-cookie-preferencias>Preferências de cookies</a>
            </nav>

            <!-- Coluna 3: redes sociais (de settings) -->
            <?php if (!empty($redes)): ?>
                <ul class="rodape-col rodape-social">
                    <?php foreach ($redes as $nome => $link): ?>
                        <li>
                            <a href="<?= e($link) ?>" target="_blank" rel="noopener">
                                <span class="rodape-social-ico"><?= $icones[$nome] /* SVG fixo */ ?></span>
                                <span class="rodape-social-nome"><?= e($nome) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <p class="copyright">
            &copy; <?= e(date('Y')) ?> D'Olivier. Todos os direitos reservados.
        </p>
    </div>
</footer>
