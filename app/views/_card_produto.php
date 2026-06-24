<?php
/**
 * Card de produto reutilizável (listagens, destaques, categoria).
 * Espera $p: array com slug, nome, preco_centavos, personalizavel, imagem (capa).
 * Usa a miniatura da capa quando existir.
 */
$p = isset($p) && is_array($p) ? $p : [];
$capa = imagem_miniatura($p['imagem'] ?? '');
?>
<article class="card">
    <?php if ($capa !== ''): ?>
        <img class="card-img" src="<?= e(url('assets/uploads/' . $capa)) ?>"
             alt="<?= e($p['nome'] ?? '') ?>">
    <?php else: ?>
        <div class="card-img"></div>
    <?php endif; ?>
    <div class="card-corpo">
        <?php if (!empty($p['personalizavel'])): ?>
            <span class="etiqueta">Personalizável</span>
        <?php endif; ?>
        <h3 class="card-nome"><?= e($p['nome'] ?? '') ?></h3>
        <?php if (empty($p['personalizavel'])): ?>
            <span class="card-preco"><?= e(money((int) ($p['preco_centavos'] ?? 0))) ?></span>
        <?php else: ?>
            <span class="card-preco">Sob consulta</span>
        <?php endif; ?>
        <a class="btn" href="<?= e(url('produto/' . ($p['slug'] ?? ''))) ?>">Ver</a>
    </div>
</article>
