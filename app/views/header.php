<?php
/**
 * Cabeçalho do site. Monta o menu a partir das categorias ativas do banco.
 * No desktop, navegação horizontal (.nav-desktop). No celular, o hambúrguer
 * abre um overlay em tela cheia (#menu-mobile).
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
$eh_admin = ($usuario !== null && ($usuario['papel'] ?? '') === 'admin');
?>
<header class="cabecalho">
    <div class="container cabecalho-inner">
        <a class="logo" href="<?= e(url()) ?>"><?= e(cfg('site_nome', 'Minha Loja')) ?></a>

        <button class="menu-toggle" type="button" data-menu-toggle
                aria-label="Abrir menu" aria-controls="menu-mobile" aria-expanded="false">&#9776;</button>

        <!-- Navegação desktop (horizontal) -->
        <nav class="nav-desktop">
            <a href="<?= e(url()) ?>">Início</a>
            <?php foreach ($categorias as $categoria): ?>
                <a href="<?= e(url('categoria/' . $categoria['slug'])) ?>"><?= e($categoria['nome']) ?></a>
            <?php endforeach; ?>
            <a href="<?= e(url('sobre')) ?>">Sobre</a>
            <a href="<?= e(url('regras')) ?>">Regras</a>
            <a href="<?= e(url('carrinho')) ?>">
                Carrinho<?php if ($qtd_carrinho > 0): ?> (<?= (int) $qtd_carrinho ?>)<?php endif; ?>
            </a>
            <?php if ($usuario !== null): ?>
                <?php if ($eh_admin): ?>
                    <a href="<?= e(url('admin')) ?>">Painel</a>
                <?php else: ?>
                    <a href="<?= e(url('meus-pedidos')) ?>">Meus Pedidos</a>
                <?php endif; ?>
                <a href="<?= e(url('sair')) ?>">Sair</a>
            <?php else: ?>
                <a href="<?= e(url('entrar')) ?>">Entrar</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<!-- Menu mobile em tela cheia (overlay) -->
<div class="menu-overlay" id="menu-mobile" role="dialog" aria-modal="true" aria-label="Menu">
    <div class="menu-overlay-topo">
        <span class="menu-overlay-titulo">Menu</span>
        <button class="menu-fechar" type="button" data-menu-fechar aria-label="Fechar menu">&times;</button>
    </div>

    <nav class="menu-overlay-nav">
        <a href="<?= e(url()) ?>" data-menu-link>Início</a>
        <?php foreach ($categorias as $categoria): ?>
            <a href="<?= e(url('categoria/' . $categoria['slug'])) ?>" data-menu-link><?= e($categoria['nome']) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(url('sobre')) ?>" data-menu-link>Sobre</a>
        <a href="<?= e(url('regras')) ?>" data-menu-link>Regras</a>
    </nav>

    <div class="menu-overlay-conta">
        <a href="<?= e(url('carrinho')) ?>" data-menu-link>
            Carrinho<?php if ($qtd_carrinho > 0): ?> (<?= (int) $qtd_carrinho ?>)<?php endif; ?>
        </a>
        <?php if ($usuario !== null): ?>
            <?php if ($eh_admin): ?>
                <a href="<?= e(url('admin')) ?>" data-menu-link>Painel</a>
            <?php else: ?>
                <a href="<?= e(url('meus-pedidos')) ?>" data-menu-link>Meus Pedidos</a>
            <?php endif; ?>
            <a href="<?= e(url('sair')) ?>" data-menu-link>Sair</a>
        <?php else: ?>
            <a href="<?= e(url('entrar')) ?>" data-menu-link>Entrar</a>
        <?php endif; ?>
    </div>
</div>
