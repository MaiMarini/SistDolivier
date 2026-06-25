<?php
/**
 * Bloco editorial da home em duas colunas (texto + foto). Conteúdo de settings
 * (chaves bloco_editorial_*), com fallback. A imagem é um arquivo em
 * assets/uploads; se não existir, mostra um placeholder elegante.
 */
$titulo      = cfg('bloco_editorial_titulo', 'Novidades feitas à mão');
$subtitulo   = cfg('bloco_editorial_subtitulo', 'Conheça nossa seleção especial, preparada em pequenos lotes com ingredientes de verdade.');
$botao_texto = cfg('bloco_editorial_botao_texto', 'Ver mais');
$botao_link  = cfg('bloco_editorial_botao_link', '');
$imagem      = cfg('bloco_editorial_imagem', '');

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

        <?php if ($botao_link !== '' && $botao_texto !== ''): ?>
            <a class="btn" href="<?= e($botao_link) ?>"><?= e($botao_texto) ?></a>
        <?php endif; ?>
    </div>

    <div class="bloco-foto">
        <?php if ($tem_imagem): ?>
            <img src="<?= e(asset('assets/uploads/' . $imagem)) ?>" alt="<?= e($titulo) ?>">
        <?php else: ?>
            <div class="bloco-foto-placeholder" aria-hidden="true"></div>
        <?php endif; ?>
    </div>
</section>
