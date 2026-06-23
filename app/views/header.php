<?php
/**
 * Cabeçalho do site. Monta o menu a partir das categorias ativas do banco.
 */
$usuario = usuario_atual();

// Categorias ativas, na ordem definida (tolerante se a tabela ainda não existir).
$categorias = [];
try {
    $stmt = db()->query(
        'SELECT slug, nome FROM categories WHERE ativo = 1 ORDER BY ordem ASC, nome ASC'
    );
    $categorias = $stmt->fetchAll();
} catch (PDOException $e) {
    $categorias = [];
}

$qtd_carrinho = carrinho_quantidade();
?>
<header class="cabecalho">
    <div class="container cabecalho-inner">
        <a class="logo" href="<?= e(url()) ?>"><?= e(cfg('site_nome', 'Minha Loja')) ?></a>

        <button class="menu-toggle" type="button" data-menu-toggle
                aria-label="Abrir menu" aria-controls="menu">&#9776;</button>

        <nav class="nav" id="menu">
            <a href="<?= e(url()) ?>">Início</a>

            <?php foreach ($categorias as $categoria): ?>
                <a href="<?= e(url('categoria/' . $categoria['slug'])) ?>">
                    <?= e($categoria['nome']) ?>
                </a>
            <?php endforeach; ?>

            <a href="<?= e(url('sobre')) ?>">Sobre</a>
            <a href="<?= e(url('regras')) ?>">Regras</a>
            <a href="<?= e(url('carrinho')) ?>">
                Carrinho<?php if ($qtd_carrinho > 0): ?> (<?= (int) $qtd_carrinho ?>)<?php endif; ?>
            </a>

            <?php if ($usuario !== null): ?>
                <a href="<?= e(url('meus-pedidos')) ?>">Meus Pedidos</a>
                <a href="<?= e(url('logout')) ?>">Sair</a>
            <?php else: ?>
                <a href="<?= e(url('login')) ?>">Entrar</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
