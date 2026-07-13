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

// Pedidos recentes (últimos 8) para acesso rápido.
$recentes = db()->query(
    'SELECT o.id, o.status, o.total_centavos, o.criado_em, u.nome AS cliente
       FROM orders o LEFT JOIN users u ON u.id = o.user_id
      ORDER BY o.id DESC LIMIT 8'
)->fetchAll();

$STATUS = [
    'realizado' => 'Pedido realizado', 'producao' => 'Em produção',
    'pronto' => 'Pronto p/ entrega', 'finalizado' => 'Finalizado',
];

ob_start();
?>
<p>Resumo da loja.</p>

<div class="grade">
    <a class="pedido" href="<?= e(url('admin/pedidos')) ?>">
        <span class="contador"><?= (int) $total_pedidos ?></span>
        <p>Pedidos</p>
    </a>
    <a class="pedido" href="<?= e(url('admin/produtos')) ?>">
        <span class="contador"><?= (int) $total_produtos ?></span>
        <p>Produtos</p>
    </a>
    <div class="pedido">
        <span class="contador"><?= (int) $total_clientes ?></span>
        <p>Clientes</p>
    </div>
</div>

<h2 class="mt-1">Pedidos recentes</h2>
<?php if (empty($recentes)): ?>
    <p>Nenhum pedido ainda.</p>
<?php else: ?>
    <table class="tabela">
        <thead>
            <tr>
                <th>#</th>
                <th>Cliente</th>
                <th class="t-centro">Data</th>
                <th class="t-centro">Status</th>
                <th class="col-acoes">Total</th>
                <th class="col-acoes"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentes as $p): ?>
                <tr>
                    <td><?= (int) $p['id'] ?></td>
                    <td><?= e($p['cliente'] ?: '—') ?></td>
                    <td class="t-centro"><?= e(date('d/m/Y', strtotime($p['criado_em']))) ?></td>
                    <td class="t-centro"><?= e($STATUS[$p['status']] ?? $p['status']) ?></td>
                    <td class="col-acoes"><?= e(money((int) $p['total_centavos'])) ?></td>
                    <td class="col-acoes">
                        <a class="btn sec" href="<?= e(url('admin/pedidos/' . (int) $p['id'])) ?>">Ver</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="mt-1"><a class="btn" href="<?= e(url('admin/pedidos')) ?>">Ver todos os pedidos</a></p>
<?php endif; ?>
<?php
view('admin_layout', ['titulo' => 'Painel', 'conteudo' => ob_get_clean()]);
