<?php
/**
 * PÁGINA DE TESTE (temporária) — valida a camada visual e os recursos do admin.
 * Será substituída pela home "real" numa tarefa futura.
 *
 * Mostra: banners ativos (do admin), seção "Destaques" (produtos marcados como
 * destaque) e uma grade geral de produtos.
 */

// Banners ativos, na ordem definida (tolerante a banco vazio).
$banners = [];
try {
    $banners = db()->query(
        'SELECT imagem, titulo, link FROM banners WHERE ativo = 1 ORDER BY ordem ASC, id ASC'
    )->fetchAll();
} catch (PDOException $e) {
    $banners = [];
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

// Grade geral.
$produtos = [];
try {
    $produtos = db()->query(
        'SELECT slug, nome, preco_centavos, personalizavel, imagem
           FROM products WHERE ativo = 1 ORDER BY id ASC LIMIT 8'
    )->fetchAll();
} catch (PDOException $e) {
    $produtos = [];
}

/** Pequeno helper local para renderizar um card de produto. */
$render_card = static function (array $p): void {
    ?>
    <article class="card">
        <?php if (!empty($p['imagem'])): ?>
            <img class="card-img" src="<?= e(url('assets/uploads/' . imagem_miniatura($p['imagem']))) ?>" alt="">
        <?php else: ?>
            <div class="card-img"></div>
        <?php endif; ?>
        <div class="card-corpo">
            <?php if (!empty($p['personalizavel'])): ?>
                <span class="etiqueta">Personalizável</span>
            <?php endif; ?>
            <h3 class="card-nome"><?= e($p['nome']) ?></h3>
            <?php if (empty($p['personalizavel'])): ?>
                <span class="card-preco"><?= e(money((int) $p['preco_centavos'])) ?></span>
            <?php else: ?>
                <span class="card-preco">Sob consulta</span>
            <?php endif; ?>
            <a class="btn" href="<?= e(url('produto/' . $p['slug'])) ?>">Ver</a>
        </div>
    </article>
    <?php
};

ob_start();
?>
<?php if (!empty($banners)): ?>
    <?php foreach ($banners as $b): ?>
        <?php $img = '<img src="' . e(url('assets/uploads/' . $b['imagem'])) . '" alt="' . e($b['titulo']) . '">'; ?>
        <section class="banner">
            <?php if (!empty($b['link'])): ?>
                <a href="<?= e($b['link']) ?>"><?= $img /* já escapado acima */ ?></a>
            <?php else: ?>
                <?= $img ?>
            <?php endif; ?>
            <?php if (!empty($b['titulo'])): ?>
                <h2><?= e($b['titulo']) ?></h2>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php else: ?>
    <section class="banner">
        <h1><?= e(cfg('site_nome', 'Minha Loja')) ?></h1>
        <p><?= e(cfg('site_descricao', 'Produtos artesanais feitos com carinho.')) ?></p>
    </section>
<?php endif; ?>

<?php if (!empty($destaques)): ?>
    <h2>Destaques</h2>
    <div class="grade">
        <?php foreach ($destaques as $p) { $render_card($p); } ?>
    </div>
<?php endif; ?>

<h2 class="mt-1">Nossos produtos</h2>
<?php if (empty($produtos)): ?>
    <p>Nenhum produto cadastrado ainda.</p>
<?php else: ?>
    <div class="grade">
        <?php foreach ($produtos as $p) { $render_card($p); } ?>
    </div>
<?php endif; ?>
<?php
view('layout', ['titulo' => 'Início', 'conteudo' => ob_get_clean()]);
