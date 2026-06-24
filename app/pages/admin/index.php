<?php
/**
 * Painel administrativo: /admin
 * Exige admin. Mostra um resumo com contadores. A navegação entre as seções
 * fica no menu lateral do admin_layout.
 */
exigir_admin();

$total_pedidos  = (int) db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$total_produtos = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
$total_clientes = (int) db()->query(
    "SELECT COUNT(*) FROM users WHERE papel = 'cliente'"
)->fetchColumn();

ob_start();
?>
<p>Resumo da loja.</p>

<div class="grade">
    <div class="pedido">
        <span class="contador"><?= (int) $total_pedidos ?></span>
        <p>Pedidos</p>
    </div>
    <div class="pedido">
        <span class="contador"><?= (int) $total_produtos ?></span>
        <p>Produtos</p>
    </div>
    <div class="pedido">
        <span class="contador"><?= (int) $total_clientes ?></span>
        <p>Clientes</p>
    </div>
</div>
<?php
view('admin_layout', ['titulo' => 'Painel', 'conteudo' => ob_get_clean()]);
