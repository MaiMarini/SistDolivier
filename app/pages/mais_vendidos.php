<?php
/**
 * Página "Mais vendidos": /mais-vendidos
 * Topo: showcase rotativo (fade em loop) dos produtos em destaque.
 * Abaixo: a MESMA grade/card da listagem de categoria, filtrada por destaque.
 */

// Produtos em destaque, na mesma ordem das outras listagens.
$produtos = db()->query(
    'SELECT slug, nome, preco_centavos, imagem, permite_personalizacao
       FROM products
      WHERE ativo = 1 AND destaque = 1
      ORDER BY id ASC'
)->fetchAll();

ob_start();
?>
<?php if (empty($produtos)): ?>
    <section class="faixa-titulo">
        <p class="eyebrow">Os queridinhos</p>
        <h1>Mais vendidos</h1>
    </section>
    <p>Nenhum produto em destaque no momento.</p>
<?php else: ?>
    <?php $rotativo = count($produtos) > 1; ?>
    <section class="showcase" data-showcase<?= $rotativo ? '' : ' data-showcase-estatico' ?>
             aria-roledescription="carrossel" aria-label="Produtos em destaque">
        <div class="showcase-palco">
            <?php foreach ($produtos as $i => $p): ?>
                <?php $arq = $p['imagem'] ?? ''; ?>
                <div class="showcase-slide<?= $i === 0 ? ' ativo' : '' ?>" data-showcase-slide="<?= (int) $i ?>">
                    <?php if ($arq !== ''): ?>
                        <img class="showcase-bg" src="<?= e(url('assets/uploads/' . $arq)) ?>"
                             alt="<?= e($p['nome'] ?? '') ?>" draggable="false">
                    <?php endif; ?>
                    <span class="showcase-grad" aria-hidden="true"></span>
                    <div class="showcase-conteudo">
                        <span class="showcase-selo">Destaque</span>
                        <h2 class="showcase-nome"><?= e($p['nome'] ?? '') ?></h2>
                        <?php if ((int) ($p['preco_centavos'] ?? 0) > 0): ?>
                            <span class="showcase-preco"><?= e(money((int) $p['preco_centavos'])) ?></span>
                        <?php else: ?>
                            <span class="showcase-preco">Sob consulta</span>
                        <?php endif; ?>
                        <a class="btn showcase-vermais" href="<?= e(url('produto/' . ($p['slug'] ?? ''))) ?>">Ver mais</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($rotativo): ?>
            <div class="showcase-dots">
                <?php foreach ($produtos as $i => $p): ?>
                    <button class="showcase-dot<?= $i === 0 ? ' ativo' : '' ?>" type="button"
                            data-showcase-dot="<?= (int) $i ?>"
                            aria-label="Ver destaque <?= (int) $i + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="secao">
        <h2 class="secao-titulo">Todos os destaques</h2>
        <div class="grade">
            <?php foreach ($produtos as $p): ?>
                <?php view('_card_produto', ['p' => $p]); ?>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php
view('layout', ['titulo' => 'Mais vendidos', 'conteudo' => ob_get_clean()]);
