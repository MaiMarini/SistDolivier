<?php
/**
 * Tarja horizontal (marquee) com loop SEM emenda.
 *
 * Técnica: a "trilha" (track) contém DOIS grupos idênticos lado a lado e é
 * animada de translateX(0) até translateX(-50%). Como há duas cópias, ao chegar
 * em -50% o conteúdo está na mesma posição visual do início -> loop perfeito.
 *
 * Para sempre cobrir a largura da tela (mesmo com poucas frases), a lista é
 * repetida algumas vezes DENTRO de cada grupo.
 */
$padrao = 'Feito à mão | Em pequenos lotes | Receita de família | Ingredientes de verdade';
$frases = explode('|', (string) cfg('tarja_frases', $padrao));
$frases = array_values(array_filter(array_map('trim', $frases), 'strlen'));

if (empty($frases)) {
    return; // sem frases, não mostra a tarja
}

// Repete a lista o suficiente para um grupo já cobrir bem a largura da tela.
$repeticoes = (int) max(1, ceil(12 / count($frases)));
?>
<div class="tarja" aria-label="Destaques da loja">
    <div class="tarja-track">
        <?php for ($g = 0; $g < 2; $g++): ?>
            <div class="tarja-grupo"<?= $g === 1 ? ' aria-hidden="true"' : '' ?>>
                <?php for ($r = 0; $r < $repeticoes; $r++): ?>
                    <?php foreach ($frases as $f): ?>
                        <span class="tarja-item"><?= e($f) ?></span>
                        <span class="tarja-sep" aria-hidden="true">·</span>
                    <?php endforeach; ?>
                <?php endfor; ?>
            </div>
        <?php endfor; ?>
    </div>
</div>
