<?php
/**
 * Tarja horizontal (marquee) com frases curtas que rolam em loop.
 * Frases vêm de settings.tarja_frases (separadas por "|"), com fallback.
 * O grupo de frases é renderizado DUAS vezes para o loop não ter "buraco".
 */
$padrao = 'Feito à mão | Em pequenos lotes | Receita de família | Ingredientes de verdade';
$frases = explode('|', (string) cfg('tarja_frases', $padrao));
$frases = array_values(array_filter(array_map('trim', $frases), 'strlen'));

if (empty($frases)) {
    return; // sem frases, não mostra a tarja
}
?>
<div class="tarja" aria-label="Destaques da loja">
    <div class="tarja-track">
        <?php for ($i = 0; $i < 2; $i++): ?>
            <span class="tarja-grupo"<?= $i === 1 ? ' aria-hidden="true"' : '' ?>>
                <?php foreach ($frases as $f): ?>
                    <span class="tarja-item"><?= e($f) ?></span>
                    <span class="tarja-sep" aria-hidden="true">·</span>
                <?php endforeach; ?>
            </span>
        <?php endfor; ?>
    </div>
</div>
