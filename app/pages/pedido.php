<?php
/**
 * Detalhe / confirmação de um pedido: /pedido/{id}
 * Só o dono do pedido (ou admin) pode ver. Serve tanto de "pedido recebido"
 * (logo após o checkout) quanto de detalhe acessado por "Meus pedidos".
 */
exigir_login();
$usuario = usuario_atual();
$eh_admin = !empty($usuario['is_admin']);

$id = (int) ($params[0] ?? 0);

$stmt = db()->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$pedido = $stmt->fetch();

// Inexistente ou de outro cliente (e não sou admin) -> 404.
if (!$pedido || (!$eh_admin && (int) $pedido['user_id'] !== (int) $usuario['id'])) {
    http_response_code(404);
    ob_start();
    ?>
    <h1>Pedido não encontrado</h1>
    <p>Este pedido não existe ou não está disponível para você.</p>
    <p class="mt-1"><a class="btn" href="<?= e(url('meus-pedidos')) ?>">Meus pedidos</a></p>
    <?php
    view('layout', ['titulo' => 'Pedido não encontrado', 'conteudo' => ob_get_clean()]);
    return;
}

// Itens do pedido (retrato salvo em order_items).
$stmt = db()->prepare(
    'SELECT nome, preco_centavos, quantidade
       FROM order_items WHERE order_id = ? ORDER BY id ASC'
);
$stmt->execute([$id]);
$itens = $stmt->fetchAll();

// Timeline de status (mesma ordem do enum de orders.status).
$passos = [
    'realizado'  => 'Pedido realizado',
    'producao'   => 'Em produção',
    'pronto'     => 'Pronto p/ entrega',
    'finalizado' => 'Finalizado',
];
$chaves = array_keys($passos);
$atual = array_search($pedido['status'], $chaves, true);
if ($atual === false) {
    $atual = 0;
}

$pago = ($pedido['pagamento_status'] ?? '') === 'aprovado';

// Link de WhatsApp para combinar o pagamento (se configurado).
$wpp = preg_replace('/\D+/', '', (string) cfg('whatsapp_numero', ''));
$wpp_link = '';
if ($wpp !== '') {
    $msg = 'Olá! Quero combinar o pagamento do pedido #' . (int) $pedido['id'] . '.';
    $wpp_link = 'https://wa.me/' . $wpp . '?text=' . rawurlencode($msg);
}

ob_start();
?>
<p><a href="<?= e(url('meus-pedidos')) ?>">&larr; Meus pedidos</a></p>

<h1>Pedido #<?= (int) $pedido['id'] ?></h1>
<p class="data"><?= e(date('d/m/Y H:i', strtotime($pedido['criado_em']))) ?></p>

<!-- Status -->
<ul class="passos">
    <?php foreach ($chaves as $i => $chave): ?>
        <?php $classe = $i < $atual ? 'concluido' : ($i === $atual ? 'ativo' : ''); ?>
        <li class="<?= $classe ?>"><?= e($passos[$chave]) ?></li>
    <?php endforeach; ?>
</ul>

<!-- Itens -->
<h2 class="mt-1">Itens</h2>
<table class="tabela">
    <thead>
        <tr>
            <th>Produto</th>
            <th class="t-centro">Qtd.</th>
            <th class="col-acoes">Subtotal</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($itens as $item): ?>
            <tr>
                <td><?= e($item['nome']) ?></td>
                <td class="t-centro"><?= (int) $item['quantidade'] ?></td>
                <td class="col-acoes">
                    <?= e(money((int) $item['preco_centavos'] * (int) $item['quantidade'])) ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Totais -->
<h2 class="mt-1">Resumo</h2>
<table class="tabela">
    <tbody>
        <tr><td>Subtotal</td><td class="col-acoes"><?= e(money((int) $pedido['subtotal_centavos'])) ?></td></tr>
        <tr>
            <td>Frete<?= $pedido['entrega'] === 'retirada' ? ' (retirada)' : '' ?></td>
            <td class="col-acoes">
                <?= (int) $pedido['frete_centavos'] > 0 ? e(money((int) $pedido['frete_centavos'])) : 'Grátis' ?>
            </td>
        </tr>
        <tr><td><strong>Total</strong></td>
            <td class="col-acoes"><strong><?= e(money((int) $pedido['total_centavos'])) ?></strong></td></tr>
    </tbody>
</table>
<p><small>Pagamento <?= e(parcelamento_texto((int) $pedido['total_centavos'])) ?>.</small></p>

<!-- Entrega -->
<h2 class="mt-1">Entrega</h2>
<?php if ($pedido['entrega'] === 'retirada'): ?>
    <p><strong>Retirada no local.</strong></p>
    <?php if (!empty($pedido['endereco_entrega'])): ?>
        <p><?= nl2br(e($pedido['endereco_entrega'])) ?></p>
    <?php endif; ?>
<?php else: ?>
    <p><strong>Entrega por motoboy.</strong>
       <?php if (!empty($pedido['entrega_distancia_km'])): ?>
           (~<?= e(rtrim(rtrim(number_format((float) $pedido['entrega_distancia_km'], 1, ',', ''), '0'), ',')) ?> km)
       <?php endif; ?>
    </p>
    <?php if (!empty($pedido['endereco_entrega'])): ?>
        <p><?= e($pedido['endereco_entrega']) ?></p>
    <?php endif; ?>
<?php endif; ?>
<?php if (!empty($pedido['contato_nome']) || !empty($pedido['contato_telefone'])): ?>
    <p><small>Contato: <?= e(trim(($pedido['contato_nome'] ?? '') . ' · ' . ($pedido['contato_telefone'] ?? ''), ' ·')) ?></small></p>
<?php endif; ?>
<?php if (!empty($pedido['observacoes'])): ?>
    <p><small>Observações: <?= e($pedido['observacoes']) ?></small></p>
<?php endif; ?>

<!-- Pagamento -->
<h2 class="mt-1">Pagamento</h2>
<?php if ($pago): ?>
    <p>Pagamento confirmado. 🎉</p>
<?php else: ?>
    <p>Pagamento <strong>pendente</strong>. O pagamento online (Pix, cartão) será
       adicionado em breve — por enquanto, combine com a gente para concluir.</p>
    <?php if ($wpp_link !== ''): ?>
        <p class="mt-1"><a class="btn wpp" href="<?= e($wpp_link) ?>" target="_blank" rel="noopener">
            Combinar pagamento no WhatsApp</a></p>
    <?php endif; ?>
<?php endif; ?>
<?php
view('layout', ['titulo' => 'Pedido #' . (int) $pedido['id'], 'conteudo' => ob_get_clean()]);
