<?php
/**
 * Home da loja: banner(es) ativos, Destaques e Coleções (categorias ativas).
 * Tudo alimentado pelo que é cadastrado no admin.
 */

// Banner ativo (mostra o primeiro; se houver vários, os demais ficam para um
// carrossel numa evolução futura).
$banner = null;
try {
    $stmt = db()->query(
        'SELECT imagem, titulo, link FROM banners WHERE ativo = 1 ORDER BY ordem ASC, id ASC LIMIT 1'
    );
    $banner = $stmt->fetch() ?: null;
} catch (PDOException $e) {
    $banner = null;
}

// Destaques.
$destaques = [];
try {
    $destaques = db()->query(
        'SELECT slug, nome, preco_centavos, personalizavel, imagem
           FROM products WHERE ativo = 1 AND destaque = 1 ORDER BY id DESC LIMIT 8'
    )->fetchAll();
} catch (PDOException $e) {
    $destaques = [];
}

// Coleções (categorias ativas).
$colecoes = [];
try {
    $colecoes = db()->query(
        'SELECT slug, nome FROM categories WHERE ativo = 1 ORDER BY ordem ASC, nome ASC'
    )->fetchAll();
} catch (PDOException $e) {
    $colecoes = [];
}

ob_start();
?>
<?php if ($banner !== null): ?>
    <section class="banner-hero">
        <?php
        $img = '<img src="' . e(url('assets/uploads/' . $banner['imagem'])) . '" alt="'
             . e($banner['titulo'] ?? '') . '">';
        ?>
        <?php if (!empty($banner['link'])): ?>
            <a href="<?= e($banner['link']) ?>"><?= $img ?></a>
        <?php else: ?>
            <?= $img ?>
        <?php endif; ?>
        <?php if (!empty($banner['titulo'])): ?>
            <h1 class="banner-hero-titulo"><?= e($banner['titulo']) ?></h1>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="banner">
        <h1><?= e(cfg('site_nome', 'Minha Loja')) ?></h1>
        <?php if (cfg('site_descricao')): ?>
            <p><?= e(cfg('site_descricao')) ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (!empty($destaques)): ?>
    <h2>Destaques</h2>
    <div class="grade">
        <?php foreach ($destaques as $p): ?>
            <?php view('_card_produto', ['p' => $p]); ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($colecoes)): ?>
    <h2 class="mt-1">Coleções</h2>
    <div class="grade-colecoes">
        <?php foreach ($colecoes as $c): ?>
            <a class="colecao" href="<?= e(url('categoria/' . $c['slug'])) ?>">
                <span><?= e($c['nome']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
view('layout', ['titulo' => 'Início', 'conteudo' => ob_get_clean()]);
