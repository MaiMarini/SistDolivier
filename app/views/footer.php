<?php
/**
 * Rodapé do site.
 * - Textos da marca e links de navegação são FIXOS.
 * - Redes sociais/contato vêm de settings (o banco guarda o dado puro; o link é
 *   montado aqui na exibição). Toda saída escapada com e().
 */

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

    <div class="container rodape-inner">
        <div class="rodape-conteudo">
            <h3 class="rodape-marca">D'Olivier</h3>
            <p class="rodape-slogan">Confeitaria Artesanal</p>

            <nav class="rodape-nav">
                <a href="<?= e(url('sobre')) ?>">Sobre nós</a>
                <a href="<?= e(url('meus-pedidos')) ?>">Meus pedidos</a>
                <a href="<?= e(url('regras')) ?>">Regras e prazos</a>
            </nav>

            <?php if (!empty($redes)): ?>
                <p class="rodape-redes">
                    <?php foreach ($redes as $nome => $link): ?>
                        <a href="<?= e($link) ?>" target="_blank" rel="noopener"><?= e($nome) ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <p class="copyright">
                &copy; <?= e(date('Y')) ?> D'Olivier. Todos os direitos reservados.
            </p>
        </div>

        <div class="rodape-logo">
            <img src="<?= e(asset('assets/img/trigo.png')) ?>" height="64"
                 alt="D'Olivier Confeitaria Artesanal">
        </div>
    </div>
</footer>
