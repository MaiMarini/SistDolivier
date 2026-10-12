<?php
/**
 * Página de categoria: /categoria/{slug}
 * Lista os produtos ativos da categoria usando o card.
 */
$slug = isset($params[0]) ? $params[0] : '';

$categoria = null;
if ($slug !== '') {
    $stmt = db()->prepare(
        'SELECT id, slug, nome FROM categories WHERE slug = ? AND ativo = 1 LIMIT 1'
    );
    $stmt->execute([$slug]);
    $categoria = $stmt->fetch();
}

// Categoria inexistente/inativa -> 404 estilizado.
if (!$categoria) {
    http_response_code(404);
    ob_start();
    ?>
    <h1>Categoria não encontrada</h1>
    <p>A categoria que você procura não existe ou não está disponível.</p>
    <p class="mt-1"><a class="btn" href="<?= e(url()) ?>">Voltar ao início</a></p>
    <?php
    view('layout', ['titulo' => 'Não encontrado', 'conteudo' => ob_get_clean()]);
    return;
}

// Categoria com coleções (ex.: Coleções Sazonais): um cartão por coleção; cada
// cartão leva para /{categoria}/{coleção} (app/pages/colecao.php).
$colecoes = colecoes_da_categoria((int) $categoria['id']);
if ($colecoes !== null) {
    // Produtos da categoria que não estão em coleção nenhuma.
    $stmt = db()->prepare(
        'SELECT slug, nome, preco_centavos, imagem, permite_personalizacao
           FROM products
          WHERE category_id = ? AND ativo = 1 AND colecao_id IS NULL
          ORDER BY ordem ASC, id ASC'
    );
    $stmt->execute([$categoria['id']]);
    $avulsos = $stmt->fetchAll();
    ob_start();
    ?>
    <div class="cols">
        <h1><?= e($categoria['nome']) ?></h1>
        <p class="cols-intro">Escolha a coleção. Cada uma tem um prazo para pedir e uma data de entrega.</p>
        <?php if (empty($colecoes) && empty($avulsos)): ?>
            <p>Nenhuma coleção disponível por enquanto.</p>
        <?php elseif (!empty($colecoes)): ?>
            <div class="cc-grade">
                <?php foreach ($colecoes as $c): ?>
                    <?php view('_card_colecao', ['c' => $c, 'cat_slug' => $categoria['slug']]); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($avulsos)): ?>
            <?php if (!empty($colecoes)): ?><h2 class="cols-outros">Outros produtos</h2><?php endif; ?>
            <div class="grade">
                <?php foreach ($avulsos as $p): ?>
                    <?php view('_card_produto', ['p' => $p]); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    view('layout', ['titulo' => $categoria['nome'], 'conteudo' => ob_get_clean()]);
    return;
}

// Produtos ativos da categoria, na ordem definida em Admin › Produtos.
$stmt = db()->prepare(
    'SELECT slug, nome, preco_centavos, imagem, permite_personalizacao
       FROM products
      WHERE category_id = ? AND ativo = 1
      ORDER BY ordem ASC, id ASC'
);
$stmt->execute([$categoria['id']]);
$produtos = $stmt->fetchAll();

ob_start();
?>
<h1><?= e($categoria['nome']) ?></h1>

<?php if (empty($produtos)): ?>
    <p>Nenhum produto disponível nesta categoria por enquanto.</p>
<?php else: ?>
    <div class="grade">
        <?php foreach ($produtos as $p): ?>
            <?php view('_card_produto', ['p' => $p]); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
view('layout', ['titulo' => $categoria['nome'], 'conteudo' => ob_get_clean()]);
