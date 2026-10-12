<?php
/**
 * Página de uma coleção: /{slug da categoria}/{slug da coleção}
 * (ex.: /colecoes-sazonais/halloween). Chamada pelo index.php quando o caminho
 * tem dois segmentos e o primeiro não é uma rota da loja.
 * Faixa com nome, situação, descrição, prazo e datas; abaixo, os produtos ativos
 * da coleção com o card de produto + "Entrega a partir de".
 */
$cat_slug = $params[0] ?? '';
$col_slug = $params[1] ?? '';

$categoria = null;
$colecao = null;
if ($cat_slug !== '' && $col_slug !== '') {
    $st = db()->prepare('SELECT id, slug, nome FROM categories WHERE slug = ? AND ativo = 1 LIMIT 1');
    $st->execute([$cat_slug]);
    $categoria = $st->fetch() ?: null;
    $colecao = $categoria ? colecao_por_slug((int) $categoria['id'], $col_slug) : null;
}

$produtos = [];
if ($colecao) {
    $st = db()->prepare(
        'SELECT slug, nome, preco_centavos, imagem, permite_personalizacao
           FROM products
          WHERE colecao_id = ? AND category_id = ? AND ativo = 1
          ORDER BY ordem ASC, id ASC'
    );
    $st->execute([(int) $colecao['id'], (int) $categoria['id']]);
    $produtos = $st->fetchAll();
}

// Coleção inexistente, desligada ou sem produtos ativos -> 404 estilizado.
if (!$colecao || !$produtos) {
    http_response_code(404);
    ob_start();
    ?>
    <h1>Coleção não encontrada</h1>
    <p>A coleção que você procura não existe ou não está disponível.</p>
    <p class="mt-1"><a class="btn" href="<?= e($categoria ? url('categoria/' . $categoria['slug']) : url()) ?>"><?= $categoria ? 'Ver ' . e($categoria['nome']) : 'Voltar ao início' ?></a></p>
    <?php
    view('layout', ['titulo' => 'Não encontrado', 'conteudo' => ob_get_clean()]);
    return;
}

$sit = $colecao['sit'];
$capa = (string) ($colecao['capa'] ?? '');
$tem_capa = $capa !== '' && is_file(imagem_dir_uploads() . '/' . $capa);

ob_start();
?>
<div class="cols">
    <nav class="col-migalha" aria-label="Você está em">
        <a href="<?= e(url('categoria/' . $categoria['slug'])) ?>"><span aria-hidden="true">←</span> <?= e($categoria['nome']) ?></a>
        <span class="col-migalha-sep" aria-hidden="true">›</span>
        <span aria-current="page"><?= e($colecao['nome']) ?></span>
    </nav>

    <section class="col-faixa is-<?= e($sit['st']) ?>" aria-labelledby="col-titulo">
        <?php if ($tem_capa): ?>
            <img class="col-faixa-capa" <?= colecao_capa_src($capa) ?> sizes="(max-width: 1100px) 100vw, 1100px"
                 alt="<?= e((string) ($colecao['capa_alt'] ?? '') !== '' ? $colecao['capa_alt'] : $colecao['nome']) ?>">
        <?php endif; ?>
        <div class="col-faixa-corpo">
            <div class="col-faixa-txt">
                <div class="col-faixa-topo">
                    <h1 id="col-titulo"><?= e($colecao['nome']) ?></h1>
                    <span class="col-st is-<?= e($sit['st']) ?>"><?= e($sit['rotulo']) ?></span>
                </div>
                <?php if (trim((string) $colecao['observacao']) !== ''): ?>
                    <p class="cc-desc"><?= nl2br(e($colecao['observacao'])) ?></p>
                <?php endif; ?>
                <span class="col-prazo is-<?= e($sit['st']) ?>"><?= e($sit['prazo']) ?></span>
            </div>
            <div class="col-datas">
                <span class="col-data">Pedidos até<b><?= e(data_dm($colecao['encomendas_ate'])) ?></b></span>
                <span class="col-data">Entrega a partir de<b><?= e(data_dm($colecao['entrega_em'])) ?></b></span>
            </div>
        </div>
    </section>

    <div class="grade">
        <?php foreach ($produtos as $p): ?>
            <?php view('_card_produto', ['p' => $p, 'colecao' => $colecao]); ?>
        <?php endforeach; ?>
    </div>
</div>
<?php
view('layout', ['titulo' => $colecao['nome'], 'conteudo' => ob_get_clean()]);
