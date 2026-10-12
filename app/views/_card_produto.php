<?php
/**
 * Card de produto reutilizável (listagens, destaques, categoria).
 * Espera $p: array com slug, nome, preco_centavos, permite_personalizacao, imagem (capa).
 * Usa a miniatura da capa quando existir.
 * Opcional $colecao (página da coleção): mostra "Entrega a partir de dd/mm" e,
 * fora do prazo de pedidos, troca o "Ver" por um botão desativado.
 */
$p = isset($p) && is_array($p) ? $p : [];
$capa = imagem_miniatura($p['imagem'] ?? '');
$colecao = isset($colecao) && is_array($colecao) ? $colecao : null;
$bloqueado = $colecao ? colecao_botao_bloqueado($colecao['sit']) : '';
?>
<article class="card<?= $colecao && $colecao['sit']['st'] === 'encerrada' ? ' is-encerrada' : '' ?>">
    <?php if ($capa !== ''): ?>
        <img class="card-img" src="<?= e(url('assets/uploads/' . $capa)) ?>"
             alt="<?= e($p['nome'] ?? '') ?>">
    <?php else: ?>
        <div class="card-img"></div>
    <?php endif; ?>
    <div class="card-corpo">
        <?php if (!empty($p['permite_personalizacao'])): ?>
            <span class="etiqueta">Personalizável</span>
        <?php endif; ?>
        <h3 class="card-nome"><?= e($p['nome'] ?? '') ?></h3>
        <?php if ((int) ($p['preco_centavos'] ?? 0) > 0): ?>
            <span class="card-preco"><?= e(money((int) $p['preco_centavos'])) ?></span>
        <?php else: ?>
            <span class="card-preco">Sob consulta</span>
        <?php endif; ?>
        <?php if ($colecao): ?>
            <span class="card-entrega">Entrega a partir de <?= e(data_dm($colecao['entrega_em'])) ?></span>
        <?php endif; ?>
        <?php if ($bloqueado !== ''): ?>
            <button class="btn is-bloqueado" type="button" disabled><?= e($bloqueado) ?></button>
        <?php else: ?>
            <a class="btn" href="<?= e(url('produto/' . ($p['slug'] ?? ''))) ?>">Ver</a>
        <?php endif; ?>
    </div>
</article>
