<?php
/**
 * Cartão de coleção (página da categoria que tem coleções, ex.: Coleções Sazonais).
 * O cartão inteiro é o link para a página da coleção.
 * Espera $c (colecoes_da_categoria: nome, slug, capa, capa_alt, observacao,
 * encomendas_ate, entrega_em, n, sit) e $cat_slug.
 */
$sit = $c['sit'];
$capa = (string) ($c['capa'] ?? '');
$tem_capa = $capa !== '' && is_file(imagem_dir_uploads() . '/' . $capa);
$n = (int) $c['n'];
$degrade = ['is-g1', 'is-g2', 'is-g3'][(int) $c['id'] % 3];   // sem capa: degradê nas cores do tema
?>
<a class="cc is-<?= e($sit['st']) ?>" href="<?= e(url($cat_slug . '/' . $c['slug'])) ?>">
    <div class="cc-capa <?= $tem_capa ? '' : $degrade ?>">
        <?php if ($tem_capa): ?>
            <img <?= colecao_capa_src($capa) ?> sizes="(max-width: 640px) 100vw, 480px"
                 alt="<?= e((string) ($c['capa_alt'] ?? '') !== '' ? $c['capa_alt'] : $c['nome']) ?>" loading="lazy">
        <?php endif; ?>
        <span class="col-st is-<?= e($sit['st']) ?>"><?= e($sit['rotulo']) ?></span>
        <h2 class="cc-nome"><?= e($c['nome']) ?></h2>
    </div>
    <div class="cc-corpo">
        <?php if (trim((string) $c['observacao']) !== ''): ?>
            <p class="cc-desc"><?= nl2br(e($c['observacao'])) ?></p>
        <?php endif; ?>
        <div class="col-datas">
            <span class="col-data">Pedidos até<b><?= e(data_dm($c['encomendas_ate'])) ?></b></span>
            <span class="col-data">Entrega a partir de<b><?= e(data_dm($c['entrega_em'])) ?></b></span>
        </div>
        <span class="col-prazo is-<?= e($sit['st']) ?>"><?= e($sit['prazo']) ?></span>
        <span class="cc-ir">Ver <?= $n === 1 ? '1 produto' : $n . ' produtos' ?> <span aria-hidden="true">→</span></span>
    </div>
</a>
