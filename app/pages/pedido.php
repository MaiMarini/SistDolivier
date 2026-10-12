<?php
/**
 * /pedido/{id}: endereço antigo do pedido (e-mails já enviados, favoritos).
 * Não tem tela própria: leva para "Meus pedidos" com o pedido aberto (a
 * administradora, se o pedido não for dela, vai para o pedido no admin).
 */
exigir_login();
$usuario = usuario_atual();
$id = (int) ($params[0] ?? 0);

$st = db()->prepare('SELECT user_id FROM orders WHERE id = ? LIMIT 1');
$st->execute([$id]);
$dono = $st->fetchColumn();

if ($dono !== false && (int) $dono === (int) $usuario['id']) {
    redirect(pedido_caminho($id));
}
if ($dono !== false && !empty($usuario['is_admin'])) {
    redirect('admin/pedidos?abrir=' . $id);
}
flash('erro', 'Pedido não encontrado.');
redirect('meus-pedidos');
