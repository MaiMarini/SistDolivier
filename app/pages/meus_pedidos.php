<?php
/**
 * "Meus pedidos": lista os pedidos do cliente logado (mais recentes primeiro)
 * com uma barra de 4 passos refletindo o status atual.
 * Rota: /meus-pedidos
 */
exigir_login();

$usuario = usuario_atual();

$stmt = db()->prepare(
    'SELECT id, status, total_centavos, criado_em
       FROM orders
      WHERE user_id = ?
      ORDER BY id DESC'
);
$stmt->execute([(int) $usuario['id']]);
$pedidos = $stmt->fetchAll();

// Ordem dos status e seus rótulos (corresponde ao enum da tabela orders).
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
        // Índice do status atual; passos anteriores = concluídos, o atual = ativo.
        $atual = array_search($pedido['status'], $chaves, true);
        if ($atual === false) {
            $atual = 0; // status desconhecido: trata como o primeiro passo
        }
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
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php
view('layout', ['titulo' => 'Meus pedidos', 'conteudo' => ob_get_clean()]);
