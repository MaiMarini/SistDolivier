<?php
/**
 * Admin: gestão de pedidos.
 *   /admin/pedidos           -> listar + filtrar (status, cliente, período)
 *   /admin/pedidos/{id}      -> detalhe + mudar status (grava histórico)
 *   POST op=status           -> avança/ajusta o status do pedido
 * exigir_admin(), CSRF, PDO preparado.
 */
exigir_admin();

$STATUS = pedido_status_rotulos();

pagamento_cancelar_expirados();   // pedidos não pagos em 24h viram "cancelado"

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

    // Histórico único: mudanças do pedido + tentativas de pagamento, por data.
    $linha_tempo = [];
    foreach ($hist as $h) {
        $linha_tempo[] = ['em' => $h['criado_em'], 'texto' => $STATUS[$h['status']] ?? $h['status'], 'pag' => false];
    }
    try {
        $stmt = db()->prepare('SELECT * FROM order_payment_events WHERE order_id = ? ORDER BY id ASC');
        $stmt->execute([$id]);
        foreach ($stmt->fetchAll() as $ev) {
            $linha_tempo[] = ['em' => $ev['criado_em'], 'texto' => pagamento_evento_texto($ev), 'pag' => true];
        }
    } catch (PDOException $e) {
        // Tabela de eventos ainda não criada (migração pendente): só o histórico do pedido.
    }
    // Por data; no mesmo instante, o pagamento vem antes (é ele que muda o status do pedido).
    usort($linha_tempo, fn ($a, $b) => strcmp($a['em'], $b['em']) ?: ((int) $b['pag'] <=> (int) $a['pag']));

    $sit_cliente = pedido_situacao($pedido);
    $resumo_pag  = pagamento_resumo_admin($pedido);

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
        <!-- O que está acontecendo com o pagamento (o status do pedido sozinho não diz). -->
        <div class="adm-pagamento<?= $resumo_pag['alerta'] ? ' is-alerta' : '' ?>">
            <p><strong>Pagamento:</strong> <?= e(pagamento_status_rotulo($pedido['pagamento_status'])) ?>
                · <span class="adm-cliente-ve">a cliente vê
                    <span class="mp-pill <?= e($sit_cliente['classe']) ?>"><?= e($sit_cliente['rotulo']) ?></span></span></p>
            <p><?= e($resumo_pag['texto']) ?></p>
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

    <?php if (!empty($pedido['presente'])): ?>
        <!-- Presente -->
        <h2 class="mt-1">Presente</h2>
        <p>Para: <strong><?= e((string) $pedido['presente_para']) ?></strong>
           <?php if (!empty($pedido['presente_telefone'])): ?> · <?= e($pedido['presente_telefone']) ?><?php endif; ?></p>
        <?php if (!empty($pedido['presente_mensagem'])): ?>
            <p>Mensagem do cartão:</p>
            <blockquote><?= nl2br(e($pedido['presente_mensagem'])) ?></blockquote>
        <?php endif; ?>
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
    <!-- Pagamento -->
    <h2 class="mt-1">Pagamento</h2>
    <?php if (($pedido['pagamento_status'] ?? '') === 'divergente'): ?>
        <p class="pedido-aviso is-erro">O valor pago no Mercado Pago é diferente do total do pedido.
            Confira no painel do Mercado Pago antes de produzir.</p>
    <?php endif; ?>
    <p>
        Status: <strong><?= e(pagamento_status_rotulo($pedido['pagamento_status'])) ?></strong><br>
        <?php if (!empty($pedido['pagamento'])): ?>Forma: <?= e(pagamento_forma_rotulo($pedido['pagamento'])) ?><br><?php endif; ?>
        <?php if (!empty($pedido['pago_em'])): ?>Pago em: <?= e(date('d/m/Y H:i', strtotime($pedido['pago_em']))) ?><br><?php endif; ?>
        <?php if (!empty($pedido['mp_payment_id'])): ?>
            Nº da operação no Mercado Pago: <strong><?= e($pedido['mp_payment_id']) ?></strong>
            <small>(busque por este número em “Vendas” no painel do Mercado Pago)</small>
        <?php endif; ?>
    </p>

    <!-- Histórico -->
    <?php if (!empty($linha_tempo)): ?>
        <h2 class="mt-1">Histórico</h2>
        <ul class="adm-historico">
            <?php foreach ($linha_tempo as $h): ?>
                <li<?= $h['pag'] ? ' class="is-pagamento"' : '' ?>><?= e($h['texto']) ?>
                    — <?= e(date('d/m/Y H:i', strtotime($h['em']))) ?></li>
            <?php endforeach; ?>
        </ul>
        <p><small>Linhas marcadas com 💳 são tentativas de pagamento no Mercado Pago; as demais, mudanças no status do pedido.</small></p>
    <?php endif; ?>
    <?php
    view('admin_layout', ['titulo' => 'Pedido #' . $id, 'conteudo' => ob_get_clean()]);
    return;
}

// -----------------------------------------------------------------------------
// LISTA: /admin/pedidos  (com filtros)
// -----------------------------------------------------------------------------
$f_status = $_GET['status'] ?? '';
$f_pag    = $_GET['pagamento'] ?? '';
$f_busca  = trim($_GET['q'] ?? '');
$f_de     = $_GET['de'] ?? '';
$f_ate    = $_GET['ate'] ?? '';

$where = [];
$args  = [];
if (isset($STATUS[$f_status])) {
    $where[] = 'o.status = ?';
    $args[]  = $f_status;
}
$PAG_FILTRO = ['aprovado', 'pendente', 'em_analise', 'recusado', 'expirado', 'estornado', 'divergente'];
if (in_array($f_pag, $PAG_FILTRO, true)) {
    // "pendente" inclui pedidos antigos sem status de pagamento gravado.
    $where[] = $f_pag === 'pendente' ? '(o.pagamento_status = ? OR o.pagamento_status IS NULL)' : 'o.pagamento_status = ?';
    $args[]  = $f_pag;
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

$sql = 'SELECT o.id, o.status, o.entrega, o.presente, o.total_centavos, o.pagamento_status, o.criado_em,
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
<form method="get" action="<?= e(url('admin/pedidos')) ?>" class="filtros">
    <select name="status">
        <option value="">Todos os status</option>
        <?php foreach ($STATUS as $chave => $rotulo): ?>
            <option value="<?= e($chave) ?>" <?= $f_status === $chave ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="pagamento" aria-label="Pagamento">
        <option value="">Todos os pagamentos</option>
        <?php foreach ($PAG_FILTRO as $s): ?>
            <option value="<?= e($s) ?>" <?= $f_pag === $s ? 'selected' : '' ?>><?= e(pagamento_status_rotulo($s)) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="text" name="q" value="<?= e($f_busca) ?>" placeholder="Cliente (nome ou e-mail)">
    <input type="date" name="de" value="<?= e($f_de) ?>" aria-label="De">
    <input type="date" name="ate" value="<?= e($f_ate) ?>" aria-label="Até">
    <button class="btn sec" type="submit">Filtrar</button>
    <?php if ($f_status !== '' || $f_pag !== '' || $f_busca !== '' || $f_de !== '' || $f_ate !== ''): ?>
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
                    <td class="t-centro"><?= $p['entrega'] === 'motoboy' ? 'Motoboy' : 'Retirada' ?><?= !empty($p['presente']) ? '<br><small>Presente</small>' : '' ?></td>
                    <td class="t-centro"><?= e(pagamento_status_rotulo($p['pagamento_status'])) ?></td>
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
