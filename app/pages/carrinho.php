<?php
/**
 * Carrinho de compras (armazenado na sessão como [product_id => quantidade]).
 *
 * Trata as ações por POST (com CSRF) e usa o padrão "processa -> redireciona ->
 * exibe" para o contador do cabeçalho atualizar e evitar reenvio do formulário.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('carrinho');
    }

    $acao = $_POST['acao'] ?? '';

    if ($acao === 'adicionar') {
        // Vem da página de produto.
        $pid = (int) ($_POST['produto_id'] ?? 0);
        $qtd = (int) ($_POST['quantidade'] ?? 1);
        if ($pid > 0) {
            carrinho_adicionar($pid, $qtd);
            flash('sucesso', 'Produto adicionado ao carrinho.');
        }
    } elseif ($acao === 'atualizar') {
        // Atualiza várias quantidades de uma vez; qtd 0 remove o item.
        $quantidades = $_POST['qtd'] ?? [];
        if (is_array($quantidades)) {
            foreach ($quantidades as $pid => $q) {
                carrinho_atualizar((int) $pid, (int) $q);
            }
        }
        flash('sucesso', 'Carrinho atualizado.');
    } elseif ($acao === 'remover') {
        $pid = (int) ($_POST['produto_id'] ?? 0);
        if ($pid > 0) {
            carrinho_remover($pid);
            flash('sucesso', 'Item removido do carrinho.');
        }
    }

    redirect('carrinho');
}

// --- Montagem da exibição (GET) ---------------------------------------------
$itens = carrinho(); // [product_id => quantidade]
$linhas = [];
$subtotal_geral = 0;

if (!empty($itens)) {
    $ids = array_keys($itens);
    $marcadores = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT id, slug, nome, preco_centavos
           FROM products
          WHERE id IN ($marcadores) AND ativo = 1"
    );
    $stmt->execute($ids);

    $por_id = [];
    foreach ($stmt->fetchAll() as $p) {
        $por_id[(int) $p['id']] = $p;
    }

    foreach ($itens as $pid => $qtd) {
        $pid = (int) $pid;
        if (!isset($por_id[$pid])) {
            // Produto saiu do catálogo: limpa do carrinho silenciosamente.
            carrinho_remover($pid);
            continue;
        }
        $produto  = $por_id[$pid];
        $subtotal = (int) $produto['preco_centavos'] * (int) $qtd;
        $subtotal_geral += $subtotal;
        $linhas[] = [
            'produto'  => $produto,
            'qtd'      => (int) $qtd,
            'subtotal' => $subtotal,
        ];
    }
}

ob_start();
?>
<h1>Seu carrinho</h1>

<?php if (empty($linhas)): ?>
    <p>Seu carrinho está vazio.</p>
    <p class="mt-1"><a class="btn" href="<?= e(url()) ?>">Ver produtos</a></p>
<?php else: ?>

    <form method="post" action="<?= e(url('carrinho')) ?>" id="form-atualizar">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="atualizar">

        <table class="tabela">
            <thead>
                <tr>
                    <th>Produto</th>
                    <th>Preço</th>
                    <th>Qtd.</th>
                    <th>Subtotal</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($linhas as $linha): $p = $linha['produto']; ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('produto/' . $p['slug'])) ?>"><?= e($p['nome']) ?></a>
                        </td>
                        <td><?= e(money((int) $p['preco_centavos'])) ?></td>
                        <td>
                            <input type="number" name="qtd[<?= (int) $p['id'] ?>]"
                                   value="<?= (int) $linha['qtd'] ?>" min="0" max="99"
                                   style="width:70px;">
                        </td>
                        <td><?= e(money($linha['subtotal'])) ?></td>
                        <td>
                            <button class="btn sec" type="submit"
                                    form="form-remover-<?= (int) $p['id'] ?>">Remover</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3">Subtotal</th>
                    <th colspan="2"><?= e(money($subtotal_geral)) ?></th>
                </tr>
            </tfoot>
        </table>

        <div class="produto-acoes mt-1">
            <button class="btn sec" type="submit">Atualizar quantidades</button>
            <button class="btn" type="button" data-abrir-modal="modal-regras">Finalizar compra</button>
        </div>
    </form>

    <?php
    // Formulários de remoção (fora do form de atualizar para não aninhar forms;
    // os botões "Remover" se ligam a eles via atributo form="...").
    foreach ($linhas as $linha): $p = $linha['produto']; ?>
        <form method="post" action="<?= e(url('carrinho')) ?>" id="form-remover-<?= (int) $p['id'] ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="acao" value="remover">
            <input type="hidden" name="produto_id" value="<?= (int) $p['id'] ?>">
        </form>
    <?php endforeach; ?>

<?php endif; ?>
<?php
view('layout', ['titulo' => 'Carrinho', 'conteudo' => ob_get_clean()]);
