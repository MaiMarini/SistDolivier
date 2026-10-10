<?php
/**
 * Carrinho de compras (armazenado na sessão como [product_id => quantidade]).
 *
 * Trata as ações por POST (com CSRF) e usa o padrão "processa -> redireciona ->
 * exibe" para o contador do cabeçalho atualizar e evitar reenvio do formulário.
 *
 * O carrinho lateral (app.js) usa a mesma rota:
 *   GET  /carrinho?formato=json            -> conteúdo do carrinho em JSON
 *   POST acao=adicionar|set_qtd (AJAX)     -> JSON com o carrinho atualizado
 */

/** Linhas do carrinho a partir do banco (só produtos ativos; os outros saem). */
function _carrinho_montar(): array
{
    $itens = carrinho(); // [product_id => quantidade]
    $linhas = [];
    $subtotal_geral = 0;
    $removidos = 0;
    if (!empty($itens)) {
        $ids = array_keys($itens);
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare(
            "SELECT id, slug, nome, preco_centavos, imagem
               FROM products
              WHERE id IN ($marcadores) AND ativo = 1 AND preco_centavos > 0"
        );
        $stmt->execute($ids);
        $por_id = [];
        foreach ($stmt->fetchAll() as $p) {
            $por_id[(int) $p['id']] = $p;
        }
        foreach ($itens as $pid => $qtd) {
            $pid = (int) $pid;
            if (!isset($por_id[$pid])) {
                // Produto saiu do catálogo (inativo/excluído): remove e avisa.
                carrinho_remover($pid);
                $removidos++;
                continue;
            }
            $subtotal = (int) $por_id[$pid]['preco_centavos'] * (int) $qtd;
            $subtotal_geral += $subtotal;
            $linhas[] = ['produto' => $por_id[$pid], 'qtd' => (int) $qtd, 'subtotal' => $subtotal];
        }
    }
    return ['linhas' => $linhas, 'subtotal' => $subtotal_geral, 'removidos' => $removidos];
}

/** O carrinho no formato usado pelo painel lateral. */
function _carrinho_json(): array
{
    $c = _carrinho_montar();
    $itens = [];
    foreach ($c['linhas'] as $l) {
        $p = $l['produto'];
        $itens[] = [
            'id'       => (int) $p['id'],
            'nome'     => $p['nome'],
            'url'      => url('produto/' . $p['slug']),
            'imagem'   => !empty($p['imagem']) ? url('assets/uploads/' . $p['imagem']) : '',
            'preco'    => money((int) $p['preco_centavos']),
            'qtd'      => $l['qtd'],
            'subtotal' => money($l['subtotal']),
        ];
    }
    return [
        'itens'     => $itens,
        'subtotal'  => money($c['subtotal']),
        'qtd_total' => carrinho_quantidade(),
        'removidos' => $c['removidos'],
    ];
}

function _carrinho_responder(array $dados): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['formato'] ?? '') === 'json') {
    _carrinho_responder(['ok' => true, 'carrinho' => _carrinho_json()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
    if (!csrf_validar()) {
        if ($ajax) {
            _carrinho_responder(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.']);
        }
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('carrinho');
    }
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('carrinho');
    }

    $acao = $_POST['acao'] ?? '';

    // AJAX: define a quantidade de um item (pílula −/+). Responde JSON.
    if ($acao === 'set_qtd') {
        $pid = (int) ($_POST['produto_id'] ?? 0);
        $qtd = (int) ($_POST['quantidade'] ?? 1);
        if ($pid > 0) {
            carrinho_atualizar($pid, $qtd); // teto 99; 0 remove
        }
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            $itens = carrinho();
            $subtotal_geral = 0;
            $qtd_total = 0;
            $item_sub = 0;
            $qtd_item = 0;
            $existe = false;
            if (!empty($itens)) {
                $ids = array_keys($itens);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = db()->prepare("SELECT id, preco_centavos FROM products WHERE id IN ($ph) AND ativo = 1 AND preco_centavos > 0");
                $st->execute($ids);
                $preco = [];
                foreach ($st->fetchAll() as $r) {
                    $preco[(int) $r['id']] = (int) $r['preco_centavos'];
                }
                foreach ($itens as $ipid => $iq) {
                    $ipid = (int) $ipid;
                    $iq = (int) $iq;
                    if (!isset($preco[$ipid])) {
                        carrinho_remover($ipid);
                        continue;
                    }
                    $sub = $preco[$ipid] * $iq;
                    $subtotal_geral += $sub;
                    $qtd_total += $iq;
                    if ($ipid === $pid) {
                        $item_sub = $sub;
                        $qtd_item = $iq;
                        $existe = true;
                    }
                }
            }
            echo json_encode([
                'ok' => true,
                'existe' => $existe,
                'qtd' => $qtd_item,
                'item_subtotal' => money($item_sub),
                'subtotal_geral' => money($subtotal_geral),
                'qtd_total' => $qtd_total,
                'carrinho' => _carrinho_json(),   // painel lateral redesenha com isto
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        redirect('carrinho');
    }

    if ($acao === 'repetir') {
        // "Pedir de novo": põe no carrinho os itens de um pedido DA CLIENTE LOGADA.
        $u   = usuario_atual();
        $pid = (int) ($_POST['pedido_id'] ?? 0);
        $st  = db()->prepare(
            'SELECT i.product_id, i.quantidade, p.id AS ativo
               FROM orders o
               JOIN order_items i ON i.order_id = o.id
               LEFT JOIN products p ON p.id = i.product_id AND p.ativo = 1 AND p.preco_centavos > 0
              WHERE o.id = ? AND o.user_id = ?'
        );
        $st->execute([$pid, $u ? (int) $u['id'] : 0]);
        $linhas = $st->fetchAll();
        $ok = false;
        $fora = 0;
        foreach ($linhas as $l) {
            if ($l['ativo'] === null) {
                $fora++;
                continue;
            }
            carrinho_adicionar((int) $l['product_id'], (int) $l['quantidade']);
            $ok = true;
        }
        if (!$linhas) {
            $msg = 'Pedido não encontrado.';
        } elseif (!$ok) {
            $msg = 'Os produtos deste pedido não estão mais disponíveis.';
        } else {
            $msg = 'Itens do pedido #' . $pid . ' adicionados ao carrinho.'
                 . ($fora ? ' ' . ($fora === 1 ? 'Um item não está mais disponível.' : $fora . ' itens não estão mais disponíveis.') : '');
        }
        if ($ajax) {
            _carrinho_responder(['ok' => $ok, 'mensagem' => $msg, 'carrinho' => _carrinho_json()]);
        }
        flash($ok ? 'sucesso' : 'erro', $msg);
    } elseif ($acao === 'adicionar') {
        // Vem da página de produto. Só adiciona se o produto existir e estiver ativo.
        $pid = (int) ($_POST['produto_id'] ?? 0);
        $qtd = (int) ($_POST['quantidade'] ?? 1);
        $ok = false;
        if ($pid > 0) {
            // Sem preço ("Sob consulta") não entra no carrinho: é combinado pelo WhatsApp.
            $stmt = db()->prepare('SELECT preco_centavos FROM products WHERE id = ? AND ativo = 1 LIMIT 1');
            $stmt->execute([$pid]);
            $preco_p = $stmt->fetchColumn();
            if ($preco_p === false) {
                $msg = 'Este produto não está mais disponível.';
            } elseif ((int) $preco_p <= 0) {
                $msg = 'Este produto é sob consulta: fale com a loja pelo WhatsApp.';
            } else {
                carrinho_adicionar($pid, $qtd);
                $ok = true;
                $msg = 'Produto adicionado ao carrinho.';
            }
            if ($ajax) {
                _carrinho_responder(['ok' => $ok, 'mensagem' => $msg, 'carrinho' => _carrinho_json()]);
            }
            flash($ok ? 'sucesso' : 'erro', $msg);
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
$montado        = _carrinho_montar();
$linhas         = $montado['linhas'];
$subtotal_geral = $montado['subtotal'];
$removidos      = $montado['removidos']; // itens que saíram do catálogo

ob_start();
?>
<h1>Seu carrinho</h1>

<?php if ($removidos > 0): ?>
    <div class="flash erro">
        <?= $removidos === 1
            ? 'Um item saiu do catálogo e foi removido do seu carrinho.'
            : (int) $removidos . ' itens saíram do catálogo e foram removidos do seu carrinho.' ?>
    </div>
<?php endif; ?>

<?php if (empty($linhas)): ?>
    <p>Seu carrinho está vazio.</p>
    <p class="mt-1"><a class="btn" href="<?= e(url()) ?>">Ver produtos</a></p>
<?php else: ?>

    <table class="tabela">
        <thead>
            <tr>
                <th>Produto</th>
                <th class="t-centro">Preço</th>
                <th class="t-centro">Qtd.</th>
                <th class="col-acoes">Subtotal</th>
                <th class="col-acoes"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($linhas as $linha): $p = $linha['produto']; ?>
                <tr>
                    <td>
                        <a href="<?= e(url('produto/' . $p['slug'])) ?>"><?= e($p['nome']) ?></a>
                    </td>
                    <td class="t-centro"><?= e(money((int) $p['preco_centavos'])) ?></td>
                    <td class="t-centro">
                        <div class="qtd-pilula qtd-sm" data-cart-qtd data-produto-id="<?= (int) $p['id'] ?>">
                            <button type="button" data-menos aria-label="Diminuir">&minus;</button>
                            <span class="qtd-num" data-num><?= (int) $linha['qtd'] ?></span>
                            <button type="button" data-mais aria-label="Aumentar">+</button>
                        </div>
                    </td>
                    <td class="col-acoes" data-subtotal="<?= (int) $p['id'] ?>"><?= e(money($linha['subtotal'])) ?></td>
                    <td class="col-acoes">
                        <button class="btn sec" type="submit"
                                form="form-remover-<?= (int) $p['id'] ?>">Remover</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3">Subtotal</th>
                <th colspan="2" class="col-acoes" data-subtotal-geral><?= e(money($subtotal_geral)) ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="produto-acoes mt-1">
        <a class="btn" href="<?= e(url('checkout')) ?>">Ir para o pagamento</a>
    </div>

    <?php
    // Formulários de remoção (os botões "Remover" se ligam via atributo form="...").
    foreach ($linhas as $linha): $p = $linha['produto']; ?>
        <form method="post" action="<?= e(url('carrinho')) ?>" id="form-remover-<?= (int) $p['id'] ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="acao" value="remover">
            <input type="hidden" name="produto_id" value="<?= (int) $p['id'] ?>">
        </form>
    <?php endforeach; ?>

    <script>
    (function () {
        var csrf = <?= json_encode(csrf_token()) ?>;
        var endpoint = <?= json_encode(url('carrinho')) ?>;
        var geral = document.querySelector('[data-subtotal-geral]');
        var badge = document.querySelector('[data-cart-badge]');

        document.querySelectorAll('[data-cart-qtd]').forEach(function (pill) {
            var pid = pill.getAttribute('data-produto-id');
            var num = pill.querySelector('[data-num]');
            var cell = document.querySelector('[data-subtotal="' + pid + '"]');
            var timer = null;

            function enviar(v) {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    var body = new URLSearchParams();
                    body.append('acao', 'set_qtd');
                    body.append('_csrf', csrf);
                    body.append('produto_id', pid);
                    body.append('quantidade', v);
                    fetch(endpoint, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'fetch' },
                        credentials: 'same-origin',
                        body: body
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (!d || !d.ok) { return; }
                            if (!d.existe) { window.location.reload(); return; }
                            if (d.qtd && d.qtd !== v) { num.textContent = d.qtd; }
                            if (cell) { cell.textContent = d.item_subtotal; }
                            if (geral) { geral.textContent = d.subtotal_geral; }
                            if (badge) { badge.textContent = d.qtd_total; }
                        })
                        .catch(function () { window.location.reload(); });
                }, 300);
            }
            function ajustar(delta) {
                var v = Math.max(1, Math.min(99, (parseInt(num.textContent, 10) || 1) + delta));
                num.textContent = v;
                enviar(v);
            }
            var menos = pill.querySelector('[data-menos]');
            var mais = pill.querySelector('[data-mais]');
            if (menos) { menos.addEventListener('click', function () { ajustar(-1); }); }
            if (mais) { mais.addEventListener('click', function () { ajustar(1); }); }
        });
    })();
    </script>

<?php endif; ?>
<?php
view('layout', ['titulo' => 'Carrinho', 'conteudo' => ob_get_clean()]);
