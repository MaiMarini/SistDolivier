<?php
/**
 * Admin: gestão de pedidos.
 *   /admin/pedidos           -> listar + filtrar (status, cliente, período)
 *   /admin/pedidos/{id}      -> detalhe + mudar status (grava histórico)
 *   POST op=status           -> avança/ajusta o status do pedido
 * exigir_admin(), CSRF, PDO preparado.
 */
exigir_admin();

$STATUS = [
    'realizado'  => 'Pedido realizado',
    'producao'   => 'Em produção',
    'pronto'     => 'Pronto p/ entrega',
    'finalizado' => 'Finalizado',
];

// -----------------------------------------------------------------------------
// POST: mudar status
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/pedidos');
    }
    if (($_POST['op'] ?? '') === 'status') {
        $id   = (int) ($_POST['id'] ?? 0);
        $novo = $_POST['status'] ?? '';
        if ($id > 0 && isset($STATUS[$novo])) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$novo, $id]);
                $pdo->prepare('INSERT INTO order_status_history (order_id, status) VALUES (?, ?)')
                    ->execute([$id, $novo]);
                $pdo->commit();
                email_status_pedido($id); // avisa o cliente; best-effort
                flash('sucesso', 'Status atualizado para "' . $STATUS[$novo] . '".');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('erro', 'Não foi possível atualizar o status.');
            }
        }
        redirect('admin/pedidos/' . $id);
    }
    redirect('admin/pedidos');
}

$acao = $params[0] ?? '';

// -----------------------------------------------------------------------------
// DETALHE: /admin/pedidos/{id}
// -----------------------------------------------------------------------------
if ($acao !== '' && ctype_digit((string) $acao)) {
    $id = (int) $acao;
    $stmt = db()->prepare(
        'SELECT o.*, u.nome AS cliente_nome, u.email AS cliente_email, u.telefone AS cliente_tel
           FROM orders o LEFT JOIN users u ON u.id = o.user_id
          WHERE o.id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $pedido = $stmt->fetch();
    if (!$pedido) {
        flash('erro', 'Pedido não encontrado.');
        redirect('admin/pedidos');
    }

    $stmt = db()->prepare(
        'SELECT nome, preco_centavos, quantidade FROM order_items WHERE order_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$id]);
    $itens = $stmt->fetchAll();

    $stmt = db()->prepare(
        'SELECT status, criado_em FROM order_status_history WHERE order_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$id]);
    $hist = $stmt->fetchAll();

    // WhatsApp do cliente (contato do pedido ou telefone do cadastro).
    $tel = preg_replace('/\D+/', '', (string) ($pedido['contato_telefone'] ?: $pedido['cliente_tel']));
    $wpp_link = '';
    if ($tel !== '') {
        $wpp_link = 'https://wa.me/' . (strlen($tel) <= 11 ? '55' . $tel : $tel)
            . '?text=' . rawurlencode('Olá! Sobre o seu pedido #' . $id . ' na D\'Olivier:');
    }

    ob_start();
    ?>
    <p><a href="<?= e(url('admin/pedidos')) ?>">&larr; Voltar para pedidos</a></p>
    <h1>Pedido #<?= (int) $pedido['id'] ?></h1>
    <p class="data"><?= e(date('d/m/Y H:i', strtotime($pedido['criado_em']))) ?></p>

    <!-- Mudar status -->
    <form class="formulario" method="post" action="<?= e(url('admin/pedidos')) ?>" style="max-width:640px;">
        <?= csrf_input() ?>
        <input type="hidden" name="op" value="status">
        <input type="hidden" name="id" value="<?= (int) $pedido['id'] ?>">
        <div class="campo">
            <label for="status">Status do pedido</label>
            <select id="status" name="status">
                <?php foreach ($STATUS as $chave => $rotulo): ?>
                    <option value="<?= e($chave) ?>" <?= $pedido['status'] === $chave ? 'selected' : '' ?>>
                        <?= e($rotulo) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn" type="submit">Atualizar status</button>
    </form>

    <!-- Cliente / contato -->
    <h2 class="mt-1">Cliente</h2>
    <p>
        <strong><?= e($pedido['cliente_nome'] ?: ($pedido['contato_nome'] ?: '—')) ?></strong><br>
        <?php if (!empty($pedido['cliente_email'])): ?><?= e($pedido['cliente_email']) ?><br><?php endif; ?>
        <?php if (!empty($pedido['contato_telefone']) || !empty($pedido['cliente_tel'])): ?>
            <?= e($pedido['contato_telefone'] ?: $pedido['cliente_tel']) ?>
        <?php endif; ?>
    </p>
    <?php if ($wpp_link !== ''): ?>
        <p><a class="btn wpp" href="<?= e($wpp_link) ?>" target="_blank" rel="noopener">Falar no WhatsApp</a></p>
    <?php endif; ?>

    <!-- Entrega -->
    <h2 class="mt-1">Entrega</h2>
    <?php if ($pedido['entrega'] === 'retirada'): ?>
        <p><strong>Retirada no local.</strong></p>
    <?php else: ?>
        <p><strong>Motoboy.</strong>
            <?php if (!empty($pedido['entrega_distancia_km'])): ?>(~<?= e((float) $pedido['entrega_distancia_km']) ?> km)<?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if (!empty($pedido['endereco_entrega'])): ?><p><?= e($pedido['endereco_entrega']) ?></p><?php endif; ?>
    <?php if (!empty($pedido['observacoes'])): ?>
        <p><small>Observações: <?= e($pedido['observacoes']) ?></small></p>
    <?php endif; ?>

    <!-- Itens + totais -->
    <h2 class="mt-1">Itens</h2>
    <table class="tabela">
        <thead>
            <tr><th>Produto</th><th class="t-centro">Qtd.</th><th class="col-acoes">Subtotal</th></tr>
        </thead>
        <tbody>
            <?php foreach ($itens as $it): ?>
                <tr>
                    <td><?= e($it['nome']) ?></td>
                    <td class="t-centro"><?= (int) $it['quantidade'] ?></td>
                    <td class="col-acoes"><?= e(money((int) $it['preco_centavos'] * (int) $it['quantidade'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td colspan="2">Subtotal</td><td class="col-acoes"><?= e(money((int) $pedido['subtotal_centavos'])) ?></td></tr>
            <tr><td colspan="2">Frete</td><td class="col-acoes"><?= (int) $pedido['frete_centavos'] > 0 ? e(money((int) $pedido['frete_centavos'])) : 'Grátis' ?></td></tr>
            <tr><td colspan="2"><strong>Total</strong></td><td class="col-acoes"><strong><?= e(money((int) $pedido['total_centavos'])) ?></strong></td></tr>
        </tfoot>
    </table>
    <p><small>Pagamento: <strong><?= e($pedido['pagamento_status'] ?: 'pendente') ?></strong></small></p>

    <!-- Histórico -->
    <?php if (!empty($hist)): ?>
        <h2 class="mt-1">Histórico</h2>
        <ul>
            <?php foreach ($hist as $h): ?>
                <li><?= e($STATUS[$h['status']] ?? $h['status']) ?>
                    — <?= e(date('d/m/Y H:i', strtotime($h['criado_em']))) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php
    view('admin_layout', ['titulo' => 'Pedido #' . $id, 'conteudo' => ob_get_clean()]);
    return;
}

// -----------------------------------------------------------------------------
// LISTA: /admin/pedidos  (com filtros)
// -----------------------------------------------------------------------------
$f_status = $_GET['status'] ?? '';
$f_busca  = trim($_GET['q'] ?? '');
$f_de     = $_GET['de'] ?? '';
$f_ate    = $_GET['ate'] ?? '';

$where = [];
$args  = [];
if (isset($STATUS[$f_status])) {
    $where[] = 'o.status = ?';
    $args[]  = $f_status;
}
if ($f_busca !== '') {
    $where[] = '(u.nome LIKE ? OR u.email LIKE ?)';
    $args[]  = '%' . $f_busca . '%';
    $args[]  = '%' . $f_busca . '%';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_de)) {
    $where[] = 'o.criado_em >= ?';
    $args[]  = $f_de . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_ate)) {
    $where[] = 'o.criado_em <= ?';
    $args[]  = $f_ate . ' 23:59:59';
}

$sql = 'SELECT o.id, o.status, o.entrega, o.total_centavos, o.pagamento_status, o.criado_em,
               u.nome AS cliente
          FROM orders o LEFT JOIN users u ON u.id = o.user_id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY o.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($args);
$pedidos = $stmt->fetchAll();

ob_start();
?>
<h1>Pedidos</h1>

<form method="get" action="<?= e(url('admin/pedidos')) ?>" class="campo-inline" style="flex-wrap:wrap; gap:.6rem; margin-bottom:1rem;">
    <select name="status">
        <option value="">Todos os status</option>
        <?php foreach ($STATUS as $chave => $rotulo): ?>
            <option value="<?= e($chave) ?>" <?= $f_status === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="q" value="<?= e($f_busca) ?>" placeholder="Cliente (nome ou e-mail)">
    <input type="date" name="de" value="<?= e($f_de) ?>" aria-label="De">
    <input type="date" name="ate" value="<?= e($f_ate) ?>" aria-label="Até">
    <button class="btn sec" type="submit">Filtrar</button>
    <?php if ($f_status !== '' || $f_busca !== '' || $f_de !== '' || $f_ate !== ''): ?>
        <a class="btn sec" href="<?= e(url('admin/pedidos')) ?>">Limpar</a>
    <?php endif; ?>
</form>

<?php if (empty($pedidos)): ?>
    <p>Nenhum pedido encontrado.</p>
<?php else: ?>
    <table class="tabela">
        <thead>
            <tr>
                <th>#</th>
                <th>Cliente</th>
                <th class="t-centro">Data</th>
                <th class="t-centro">Entrega</th>
                <th class="t-centro">Pagamento</th>
                <th class="t-centro">Status</th>
                <th class="col-acoes">Total</th>
                <th class="col-acoes"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pedidos as $p): ?>
                <tr>
                    <td><?= (int) $p['id'] ?></td>
                    <td><?= e($p['cliente'] ?: '—') ?></td>
                    <td class="t-centro"><?= e(date('d/m/Y', strtotime($p['criado_em']))) ?></td>
                    <td class="t-centro"><?= $p['entrega'] === 'motoboy' ? 'Motoboy' : 'Retirada' ?></td>
                    <td class="t-centro"><?= e($p['pagamento_status'] ?: 'pendente') ?></td>
                    <td class="t-centro"><?= e($STATUS[$p['status']] ?? $p['status']) ?></td>
                    <td class="col-acoes"><?= e(money((int) $p['total_centavos'])) ?></td>
                    <td class="col-acoes">
                        <a class="btn sec" href="<?= e(url('admin/pedidos/' . (int) $p['id'])) ?>">Ver</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php
view('admin_layout', ['titulo' => 'Pedidos', 'conteudo' => ob_get_clean()]);
