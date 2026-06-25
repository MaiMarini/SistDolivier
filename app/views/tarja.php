<?php
/**
 * Tarja horizontal (marquee) com loop SEM emenda.
 *
 * Frases vindas da tabela marquee_frases (ordenadas por `ordem`). Se não houver
 * nenhuma frase cadastrada, a tarja não é renderizada.
 *
 * Técnica: a "trilha" contém DOIS grupos idênticos lado a lado, animados de
 * translateX(0) até translateX(-50%) -> emenda perfeita. A lista é repetida o
 * suficiente dentro de cada grupo para cobrir a largura da tela.
 */
$frases = [];
try {
    $rows = db()->query('SELECT texto FROM marquee_frases ORDER BY ordem ASC, id ASC')->fetchAll();
    foreach ($rows as $r) {
        $texto = trim((string) $r['texto']);
        if ($texto !== '') {
            $frases[] = $texto;
        }
    }
} catch (PDOException $e) {
    $frases = [];
}

if (empty($frases)) {
    return; // sem frases cadastradas: não renderiza a faixa
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
