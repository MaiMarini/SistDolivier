<?php
/**
 * PÁGINA DE TESTE (temporária) — só para validar a camada visual base.
 * Será substituída pela home real numa tarefa futura.
 *
 * Demonstra: banner, grade de produtos (do banco), botões, modal de regras,
 * passos de acompanhamento e tabela.
 */

// Alguns produtos ativos para demonstrar a grade (tolerante a banco vazio).
$produtos = [];
try {
    $stmt = db()->query(
        'SELECT slug, nome, preco_centavos, personalizavel
           FROM products WHERE ativo = 1 ORDER BY id ASC LIMIT 8'
    );
    $produtos = $stmt->fetchAll();
} catch (PDOException $e) {
    $produtos = [];
}

ob_start();
?>
<section class="banner">
    <h1><?= e(cfg('site_nome', 'Minha Loja')) ?></h1>
    <p><?= e(cfg('site_descricao', 'Produtos artesanais feitos com carinho.')) ?></p>
    <p class="mt-1">
        <a class="btn" href="<?= e(url('produto/exemplo')) ?>">Ver um produto</a>
        <button class="btn sec" type="button" data-abrir-modal="modal-regras">Ver regras</button>
    </p>
</section>

<h2>Nossos produtos</h2>
<?php if (empty($produtos)): ?>
    <p>Nenhum produto cadastrado ainda. (Importe o <code>seed_exemplo.sql</code>.)</p>
<?php else: ?>
    <div class="grade">
        <?php foreach ($produtos as $p): ?>
            <article class="card">
                <div class="card-img"></div>
                <div class="card-corpo">
                    <?php if (!empty($p['personalizavel'])): ?>
                        <span class="etiqueta">Personalizável</span>
                    <?php endif; ?>
                    <h3 class="card-nome"><?= e($p['nome']) ?></h3>
                    <span class="card-preco"><?= e(money((int) $p['preco_centavos'])) ?></span>
                    <a class="btn" href="<?= e(url('produto/' . $p['slug'])) ?>">Ver</a>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="mt-1">Acompanhamento do pedido (exemplo)</h2>
<ul class="passos">
    <li class="concluido">Realizado</li>
    <li class="ativo">Produção</li>
    <li>Pronto</li>
    <li>Finalizado</li>
</ul>

<h2 class="mt-1">Tabela (exemplo)</h2>
<table class="tabela">
    <thead>
        <tr><th>Item</th><th>Qtd.</th><th>Preço</th></tr>
    </thead>
    <tbody>
        <tr><td>Produto de exemplo</td><td>2</td><td><?= e(money(3500)) ?></td></tr>
        <tr><td>Outro produto</td><td>1</td><td><?= e(money(1800)) ?></td></tr>
    </tbody>
</table>
<?php
$conteudo = ob_get_clean();

view('layout', [
    'titulo'   => 'Início',
    'conteudo' => $conteudo,
]);
