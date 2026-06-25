<?php
/**
 * Home da loja: banner(es) ativos, Destaques e Coleções (categorias ativas).
 * Tudo alimentado pelo que é cadastrado no admin.
 */

// Banners ativos (todos, na ordem) -> carrossel.
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

// Coleções (categorias ativas). A "capa" é a imagem do produto mais recente da
// categoria (se houver) — sem alterar o schema das categorias.
$colecoes = [];
try {
    $colecoes = db()->query(
        "SELECT c.slug, c.nome,
                (SELECT p.imagem FROM products p
                  WHERE p.category_id = c.id AND p.ativo = 1
                        AND p.imagem IS NOT NULL AND p.imagem <> ''
                  ORDER BY p.id DESC LIMIT 1) AS capa
           FROM categories c
          WHERE c.ativo = 1
          ORDER BY c.ordem ASC, c.nome ASC"
    )->fetchAll();
} catch (PDOException $e) {
    $colecoes = [];
}

ob_start();
?>
<?php if (!empty($banners)): ?>
    <section class="carrossel" data-carrossel aria-roledescription="carrossel" aria-label="Banners">
        <div class="carrossel-trilho">
            <?php foreach ($banners as $i => $b): ?>
                <div class="carrossel-slide<?= $i === 0 ? ' ativo' : '' ?>">
                    <?php
                    $img = '<img src="' . e(url('assets/uploads/' . $b['imagem'])) . '"'
                         . ' alt="' . e($b['titulo'] ?? '') . '" draggable="false">';
                    ?>
                    <?php if (!empty($b['link'])): ?>
                        <a href="<?= e($b['link']) ?>"><?= $img ?></a>
                    <?php else: ?>
                        <?= $img ?>
                    <?php endif; ?>
                    <?php if (!empty($b['titulo'])): ?>
                        <h2 class="carrossel-titulo"><?= e($b['titulo']) ?></h2>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (count($banners) > 1): ?>
            <button class="carrossel-seta prev" type="button"
                    data-carrossel-prev aria-label="Banner anterior">&lsaquo;</button>
            <button class="carrossel-seta next" type="button"
                    data-carrossel-next aria-label="Próximo banner">&rsaquo;</button>
            <div class="carrossel-dots">
                <?php foreach ($banners as $i => $b): ?>
                    <button class="carrossel-dot" type="button"
                            data-carrossel-dot="<?= (int) $i ?>"
                            aria-label="Ir para o slide <?= (int) $i + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
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

<?php view('tarja'); ?>

<?php if (!empty($destaques)): ?>
    <section class="secao">
        <p class="eyebrow">Os queridinhos</p>
        <h2 class="secao-titulo">Mais vendidos</h2>
        <div class="grade">
            <?php foreach ($destaques as $p): ?>
                <?php view('_card_produto', ['p' => $p]); ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php view('_bloco_destaque'); ?>

<?php if (!empty($colecoes)): ?>
    <section class="secao">
        <p class="eyebrow">Explore</p>
        <h2 class="secao-titulo">Coleções</h2>
        <div class="grade-colecoes">
            <?php foreach ($colecoes as $c): ?>
                <?php $capa = imagem_miniatura($c['capa'] ?? ''); ?>
                <a class="colecao<?= $capa !== '' ? ' tem-capa' : '' ?>"
                   href="<?= e(url('categoria/' . $c['slug'])) ?>">
                    <?php if ($capa !== ''): ?>
                        <img class="colecao-capa" src="<?= e(url('assets/uploads/' . $capa)) ?>" alt="">
                    <?php endif; ?>
                    <span class="colecao-nome"><?= e($c['nome']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php
view('layout', ['titulo' => 'Início', 'conteudo' => ob_get_clean()]);
