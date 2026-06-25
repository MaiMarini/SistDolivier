<?php
/**
 * Bloco de destaque em duas colunas (texto + foto). Conteúdo de settings:
 *   home_bloco_titulo, home_bloco_subtitulo, home_bloco_imagem, home_bloco_link
 * com fallback. Imagem é um arquivo em assets/uploads; se não existir, placeholder.
 */
$titulo    = cfg('home_bloco_titulo', 'Novidades feitas à mão');
$subtitulo = cfg('home_bloco_subtitulo', 'Conheça nossa seleção especial, preparada em pequenos lotes com ingredientes de verdade.');
$link      = cfg('home_bloco_link', '');
$imagem    = cfg('home_bloco_imagem', '');

$tem_imagem = $imagem !== '' && is_file(ROOT_PATH . '/assets/uploads/' . $imagem);
?>
<section class="bloco-duplo">
    <div class="bloco-texto">
        <h2 class="bloco-titulo"><?= e($titulo) ?></h2>

        <!-- Divisor com linha ondulada sutil -->
        <svg class="bloco-divisor" width="120" height="12" viewBox="0 0 120 12"
             fill="none" aria-hidden="true">
            <path d="M0 6 Q 10 0 20 6 T 40 6 T 60 6 T 80 6 T 100 6 T 120 6"
                  stroke="currentColor" stroke-width="2" fill="none"
                  stroke-linecap="round"/>
        </svg>

        <p class="bloco-sub"><?= e($subtitulo) ?></p>

        <?php if ($link !== ''): ?>
            <a class="btn" href="<?= e($link) ?>">Ver mais</a>
        <?php endif; ?>
    </div>

    <div class="bloco-foto">
        <?php if ($tem_imagem): ?>
            <img src="<?= e(url('assets/uploads/' . $imagem)) ?>" alt="<?= e($titulo) ?>">
        <?php else: ?>
            <div class="bloco-foto-placeholder" aria-hidden="true"></div>
        <?php endif; ?>
    </div>
</section>
