<?php
/**
 * Página de categoria: /categoria/{slug}
 * Lista os produtos ativos da categoria usando o card.
 */
$slug = isset($params[0]) ? $params[0] : '';

$categoria = null;
if ($slug !== '') {
    $stmt = db()->prepare(
        'SELECT id, nome FROM categories WHERE slug = ? AND ativo = 1 LIMIT 1'
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

// Produtos ativos da categoria.
$stmt = db()->prepare(
    'SELECT slug, nome, preco_centavos, imagem, permite_personalizacao
       FROM products
      WHERE category_id = ? AND ativo = 1
      ORDER BY id ASC'
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
