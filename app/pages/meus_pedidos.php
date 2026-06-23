<?php
/**
 * "Meus pedidos": lista os pedidos do cliente logado (mais recentes primeiro),
 * com a barra de 4 passos de status e os itens de cada pedido (foto, nome,
 * descrição, quantidade e preço unitário).
 * Rota: /meus-pedidos
 */
exigir_login();

$usuario = usuario_atual();

// 1) Pedidos do usuário.
$stmt = db()->prepare(
    'SELECT id, status, total_centavos, criado_em
       FROM orders
      WHERE user_id = ?
      ORDER BY id DESC'
);
$stmt->execute([(int) $usuario['id']]);
$pedidos = $stmt->fetchAll();

// 2) Itens de TODOS os pedidos em uma única consulta (evita N+1).
//    LEFT JOIN com products para continuar funcionando se o produto foi removido.
$itens_por_pedido = [];
if (!empty($pedidos)) {
    $ids = array_map(static function ($p) {
        return (int) $p['id'];
    }, $pedidos);

    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT oi.order_id, oi.nome, oi.preco_centavos, oi.quantidade,
                p.imagem, p.descricao
           FROM order_items oi
           LEFT JOIN products p ON p.id = oi.product_id
          WHERE oi.order_id IN ($marcadores)
          ORDER BY oi.id ASC"
    );
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $item) {
        $itens_por_pedido[(int) $item['order_id']][] = $item;
    }
}

// Ordem dos status e rótulos (corresponde ao enum de orders.status).
$passos = [
    'realizado'  => 'Pedido realizado',
    'producao'   => 'Em produção',
    'pronto'     => 'Pronto p/ entrega',
    'finalizado' => 'Finalizado',
];
$chaves = array_keys($passos);

ob_start();
?>
<h1>Meus pedidos</h1>

<?php if (empty($pedidos)): ?>
    <p>Você ainda não fez nenhum pedido.</p>
    <p class="mt-1"><a class="btn" href="<?= e(url()) ?>">Ver produtos</a></p>
<?php else: ?>
    <?php foreach ($pedidos as $pedido): ?>
        <?php
        $atual = array_search($pedido['status'], $chaves, true);
        if ($atual === false) {
            $atual = 0;
        }
        $itens = $itens_por_pedido[(int) $pedido['id']] ?? [];
        ?>
        <section class="pedido">
            <div class="pedido-cab">
                <h3>Pedido #<?= (int) $pedido['id'] ?></h3>
                <span class="total"><?= e(money((int) $pedido['total_centavos'])) ?></span>
                <span class="data">
                    <?= e(date('d/m/Y H:i', strtotime($pedido['criado_em']))) ?>
                </span>
            </div>

            <ul class="passos">
                <?php foreach ($chaves as $i => $chave): ?>
                    <?php
                    $classe = '';
                    if ($i < $atual) {
                        $classe = 'concluido';
                    } elseif ($i === $atual) {
                        $classe = 'ativo';
                    }
                    ?>
                    <li class="<?= $classe ?>"><?= e($passos[$chave]) ?></li>
                <?php endforeach; ?>
            </ul>

            <?php if (!empty($itens)): ?>
                <div class="mt-1">
                    <?php foreach ($itens as $item): ?>
                        <div class="item-pedido">
                            <?php if (!empty($item['imagem'])): ?>
                                <img class="item-thumb"
                                     src="<?= e(url('assets/uploads/' . $item['imagem'])) ?>"
                                     alt="<?= e($item['nome']) ?>">
                            <?php else: ?>
                                <span class="item-thumb">sem imagem</span>
                            <?php endif; ?>

                            <div class="item-corpo">
                                <h4 class="item-nome"><?= e($item['nome']) ?></h4>
                                <?php if (!empty($item['descricao'])): ?>
                                    <p class="item-desc"><?= e($item['descricao']) ?></p>
                                <?php endif; ?>
                                <span class="qtd-preco">
                                    <?= (int) $item['quantidade'] ?> ×
                                    <?= e(money((int) $item['preco_centavos'])) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php
view('layout', ['titulo' => 'Meus pedidos', 'conteudo' => ob_get_clean()]);
