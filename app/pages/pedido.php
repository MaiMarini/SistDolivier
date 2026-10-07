<?php
/**
 * Detalhe de um pedido: /pedido/{id}
 * Só o dono do pedido (ou admin) pode ver. É a página que o celular abre ao
 * tocar num pedido de "Meus pedidos", e para onde o checkout e o retorno do
 * pagamento levam. O conteúdo é o mesmo do painel de "Meus pedidos".
 */
exigir_login();
$usuario  = usuario_atual();
$eh_admin = !empty($usuario['is_admin']);

$id = (int) ($params[0] ?? 0);

pagamento_cancelar_expirados();   // pedidos não pagos em 24h viram "cancelado"

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

$comp = pedidos_complementos([$id]);

ob_start();
?>
<div class="mp-pagina-pedido">
    <?php view('pedido-detalhe', [
        'pedido' => $pedido,
        'itens'  => $comp['itens'][$id] ?? [],
        'quando' => $comp['quando'][$id] ?? [],
        'voltar' => true,
    ]); ?>
</div>
<?php
view('layout', ['titulo' => 'Pedido #' . $id, 'conteudo' => ob_get_clean()]);
