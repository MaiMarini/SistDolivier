<?php
/**
 * Painel administrativo: /admin
 * Exige admin. Mostra contadores e cartões das áreas que virão (em breve).
 */
exigir_admin();

$total_pedidos  = (int) db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$total_produtos = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
$total_clientes = (int) db()->query(
    "SELECT COUNT(*) FROM users WHERE papel = 'cliente'"
)->fetchColumn();

// Áreas futuras (apenas indicativas por enquanto).
$areas = [
    ['titulo' => 'Pedidos',       'desc' => 'Acompanhar e mudar status dos pedidos.'],
    ['titulo' => 'Produtos',      'desc' => 'Cadastrar produtos e categorias.'],
    ['titulo' => 'Home / Banners', 'desc' => 'Gerenciar banners e destaques.'],
    ['titulo' => 'E-mails',       'desc' => 'Campanhas de e-mail promocional.'],
];

ob_start();
?>
<h1>Painel</h1>

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

<h2 class="mt-1">Áreas</h2>
<div class="grade">
    <?php foreach ($areas as $area): ?>
        <div class="pedido em-breve">
            <h3><?= e($area['titulo']) ?></h3>
            <span class="etiqueta">Em breve</span>
            <p><?= e($area['desc']) ?></p>
        </div>
    <?php endforeach; ?>
</div>

<p class="mt-1"><a class="btn sec" href="<?= e(url('admin/sair')) ?>">Sair do painel</a></p>
<?php
view('admin_layout', ['titulo' => 'Painel', 'conteudo' => ob_get_clean()]);
