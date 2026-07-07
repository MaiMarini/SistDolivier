<?php
/**
 * Bloco editorial da home (mídia + texto). TODO o conteúdo vem de settings
 * (chaves bloco_editorial_*), sem texto fixo. Regras:
 *  - a mídia é foto OU vídeo, conforme bloco_editorial_tipo_midia;
 *  - mídia ausente (do tipo escolhido): a coluna da mídia é escondida e o
 *    texto ocupa a largura toda;
 *  - botão só aparece se texto E link estiverem preenchidos;
 *  - se nada estiver configurado, a seção inteira não é renderizada.
 * Toda saída escapada com e().
 */
$titulo      = (string) cfg('bloco_editorial_titulo', '');
$subtitulo   = (string) cfg('bloco_editorial_subtitulo', '');
$botao_texto = (string) cfg('bloco_editorial_botao_texto', '');
$botao_link  = (string) cfg('bloco_editorial_botao_link', '');
$tipo_midia  = cfg('bloco_editorial_tipo_midia', 'foto') === 'video' ? 'video' : 'foto';
$imagem      = (string) cfg('bloco_editorial_imagem', '');
$video       = (string) cfg('bloco_editorial_video', '');

$tem_imagem = $tipo_midia === 'foto'
    && $imagem !== '' && is_file(ROOT_PATH . '/assets/uploads/' . $imagem);
$tem_video  = $tipo_midia === 'video'
    && $video !== '' && is_file(ROOT_PATH . '/assets/uploads/' . $video);
$tem_midia  = $tem_imagem || $tem_video;
$tem_botao  = $botao_texto !== '' && $botao_link !== '';

// Bloco sem nenhum conteúdo configurado: não renderiza nada.
if ($titulo === '' && $subtitulo === '' && !$tem_midia && !$tem_botao) {
    return;
}
?>
<section class="bloco-duplo<?= $tem_midia ? '' : ' sem-foto' ?>">
    <?php if ($tem_imagem): ?>
        <div class="bloco-foto">
            <img src="<?= e(asset('assets/uploads/' . $imagem)) ?>" alt="<?= e($titulo) ?>">
        </div>
    <?php elseif ($tem_video): ?>
        <div class="bloco-foto">
            <video src="<?= e(asset('assets/uploads/' . $video)) ?>"
                   autoplay loop muted playsinline
                   aria-hidden="true"></video>
        </div>
    <?php endif; ?>

    <div class="bloco-texto">
        <?php if ($titulo !== ''): ?>
            <h2 class="bloco-titulo"><?= e($titulo) ?></h2>

            <!-- Divisor com linha ondulada sutil -->
            <svg class="bloco-divisor" width="120" height="12" viewBox="0 0 120 12"
                 fill="none" aria-hidden="true">
                <path d="M0 6 Q 10 0 20 6 T 40 6 T 60 6 T 80 6 T 100 6 T 120 6"
                      stroke="currentColor" stroke-width="2" fill="none"
                      stroke-linecap="round"/>
            </svg>
        <?php endif; ?>

        <?php if ($subtitulo !== ''): ?>
            <p class="bloco-sub"><?= e($subtitulo) ?></p>
        <?php endif; ?>

        <?php if ($tem_botao): ?>
            <a class="btn" href="<?= e($botao_link) ?>"><?= e($botao_texto) ?></a>
        <?php endif; ?>
    </div>
</section>
