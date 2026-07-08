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
        'SELECT slug, nome, preco_centavos, permite_personalizacao, imagem
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
          ORDER BY c.ordem ASC, c.id ASC"
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
            <button class="carrossel-seta prev" type="button" data-carrossel-prev aria-label="Banner anterior">&lsaquo;</button>
            <button class="carrossel-seta next" type="button" data-carrossel-next aria-label="Próximo banner">&rsaquo;</button>
            <div class="carrossel-dots">
                <?php foreach ($banners as $i => $b): ?>
                    <button class="carrossel-dot" type="button" data-carrossel-dot="<?= (int) $i ?>"
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
    <section class="secao mais-vendidos" data-carrossel-h>
        <div class="mv-cabeca">
            <p class="eyebrow">Os queridinhos</p>
            <h2 class="secao-titulo">Mais vendidos</h2>
            <a class="btn sec" href="<?= e(url('mais-vendidos')) ?>">Ver todos</a>
            <div class="mv-setas">
                <button class="mv-seta" type="button" data-mv-prev aria-label="Anterior" disabled>&lsaquo;</button>
                <button class="mv-seta" type="button" data-mv-next aria-label="Próximo">&rsaquo;</button>
            </div>
        </div>

        <div class="mv-carrossel">
            <div class="mv-trilho" data-mv-trilho>
                <?php foreach ($destaques as $p): ?>
                    <?php $capa = imagem_miniatura($p['imagem'] ?? ''); ?>
                    <a class="mv-card" href="<?= e(url('produto/' . ($p['slug'] ?? ''))) ?>">
                        <?php if ($capa !== ''): ?>
                            <img class="mv-card-img" src="<?= e(url('assets/uploads/' . $capa)) ?>" alt="<?= e($p['nome'] ?? '') ?>"
                                draggable="false">
                        <?php else: ?>
                            <span class="mv-card-img"></span>
                        <?php endif; ?>
                        <span class="mv-card-corpo">
                            <span class="mv-card-nome"><?= e($p['nome'] ?? '') ?></span>
                            <?php if ((int) ($p['preco_centavos'] ?? 0) > 0): ?>
                                <span class="mv-card-preco"><?= e(money((int) $p['preco_centavos'])) ?></span>
                            <?php else: ?>
                                <span class="mv-card-preco">Sob consulta</span>
                            <?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php view('_bloco_destaque'); ?>

<?php if (!empty($colecoes)): ?>
    <?php
    $col_img = cfg('colecoes_imagem_lateral', '');
    $tem_col_img = $col_img !== '' && is_file(ROOT_PATH . '/assets/uploads/' . $col_img);
    ?>
    <section class="secao" id="colecoes">
        <p class="eyebrow">Explore</p>
        <h2 class="secao-titulo">Coleções</h2>
        <div class="colecoes-layout<?= $tem_col_img ? ' tem-lateral' : '' ?>">
            <div class="grade-colecoes">
                <?php foreach ($colecoes as $c): ?>
                    <?php $capa = imagem_miniatura($c['capa'] ?? ''); ?>
                    <a class="colecao<?= $capa !== '' ? ' tem-capa' : '' ?>" href="<?= e(url('categoria/' . $c['slug'])) ?>">
                        <?php if ($capa !== ''): ?>
                            <img class="colecao-capa" src="<?= e(url('assets/uploads/' . $capa)) ?>" alt="">
                        <?php endif; ?>
                        <span class="colecao-nome"><?= e($c['nome']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($tem_col_img): ?>
                <aside class="colecoes-lateral">
                    <img class="colecoes-lateral-img" src="<?= e(asset('assets/uploads/' . $col_img)) ?>" alt="">
                </aside>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="instagram" aria-label="Instagram">
    <!-- <div class="instagram-cabeca">
        <p class="eyebrow">No nosso Instagram</p>
        <h2 class="secao-titulo">@dolivier</h2>
    </div> -->
    <!-- Elfsight Instagram Feed | Untitled Instagram Feed -->
    <!-- Cookie NÃO-ESSENCIAL: o script só é injetado após consentimento "todos". -->
    <script type="text/plain" data-cookie-src="https://elfsightcdn.com/platform.js"
            data-cookie-consent="todos"></script>
    <div class="elfsight-app-0ba2decf-78d5-4762-a2cd-93e975a7f03e" data-elfsight-app-lazy></div>
</section>
<?php
view('layout', ['titulo' => 'Início', 'conteudo' => ob_get_clean()]);
