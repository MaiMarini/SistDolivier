<?php
/**
 * Checkout: /checkout — EXIGE LOGIN.
 * Revisão do pedido + entrega (retirada/motoboy) + endereço + observações +
 * totais (subtotal + frete) + parcelamento + aceite das regras. Cria o pedido
 * (status "realizado", pagamento "pendente"). O pagamento entra na Fase 3.
 * Valores sempre em CENTAVOS; preços recomputados do banco (nunca do cliente).
 */
exigir_login();
$usuario = usuario_atual();

/** Monta as linhas do carrinho a partir do banco (só produtos ativos). */
function _checkout_carrinho(): array
{
    $itens = carrinho();
    $linhas = [];
    $subtotal = 0;
    if (!empty($itens)) {
        $ids = array_keys($itens);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare(
            "SELECT id, slug, nome, preco_centavos FROM products WHERE id IN ($ph) AND ativo = 1"
        );
        $st->execute($ids);
        $por_id = [];
        foreach ($st->fetchAll() as $p) {
            $por_id[(int) $p['id']] = $p;
        }
        foreach ($itens as $pid => $qtd) {
            $pid = (int) $pid;
            if (!isset($por_id[$pid])) {
                carrinho_remover($pid);
                continue;
            }
            $qtd = (int) $qtd;
            $sub = (int) $por_id[$pid]['preco_centavos'] * $qtd;
            $subtotal += $sub;
            $linhas[] = ['produto' => $por_id[$pid], 'qtd' => $qtd, 'subtotal' => $sub];
        }
    }
    return ['linhas' => $linhas, 'subtotal' => $subtotal];
}

// Dados de contato/endereço do cadastro (para pré-preencher).
$stmt = db()->prepare(
    'SELECT nome, telefone, endereco, cep, rua, numero, complemento, bairro, cidade, uf
       FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([(int) $usuario['id']]);
$dados = $stmt->fetch() ?: ['nome' => $usuario['nome'] ?? '', 'telefone' => '', 'endereco' => ''];
$cad = fn ($k) => (string) ($dados[$k] ?? '');
// Endereço do perfil completo o bastante para entregar (pode ser escolhido no checkout).
$tem_end_cad = strlen($cad('cep')) === 8 && $cad('rua') !== '' && $cad('numero') !== ''
    && $cad('cidade') !== '' && preg_match('/^[A-Z]{2}$/', $cad('uf'));

// -----------------------------------------------------------------------------
// POST: criar o pedido
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('checkout');
    }

    $c = _checkout_carrinho();
    if (empty($c['linhas'])) {
        flash('erro', 'Seu carrinho está vazio.');
        redirect('carrinho');
    }

    if (!isset($_POST['aceite'])) {
        flash('erro', 'É preciso aceitar as regras para finalizar.');
        redirect('checkout');
    }

    $entrega      = (($_POST['entrega'] ?? '') === 'motoboy') ? 'motoboy' : 'retirada';
    $observacoes  = trim($_POST['observacoes'] ?? '');
    $contato_nome = trim($_POST['contato_nome'] ?? '') !== '' ? trim($_POST['contato_nome']) : ($dados['nome'] ?? '');
    $contato_tel  = trim($_POST['contato_telefone'] ?? '') !== '' ? trim($_POST['contato_telefone']) : ($dados['telefone'] ?? '');

    $distancia_km     = null;
    $endereco_entrega = null;

    if ($entrega === 'retirada') {
        $frete = frete_calcular('retirada');
        $endereco_entrega = cfg('retirada_endereco', '') !== '' ? cfg('retirada_endereco', '') : 'Retirada no local';
    } else {
        // "cadastro" usa o endereço do perfil (lido do banco, não do formulário).
        $usar_cad = $tem_end_cad && ($_POST['endereco_opcao'] ?? 'cadastro') === 'cadastro';
        $origem = $usar_cad ? $dados : $_POST;
        $cep    = preg_replace('/\D+/', '', (string) ($origem['cep'] ?? ''));
        $rua    = trim((string) ($origem['rua'] ?? ''));
        $numero = trim((string) ($origem['numero'] ?? ''));
        $bairro = trim((string) ($origem['bairro'] ?? ''));
        $cidade = trim((string) ($origem['cidade'] ?? ''));
        $uf     = strtoupper(trim((string) ($origem['uf'] ?? '')));
        $comp   = trim((string) ($origem['complemento'] ?? ''));
        if (strlen($cep) !== 8) {
            flash('erro', 'Informe um CEP válido (8 números).');
            redirect('checkout');
        }
        if ($rua === '' || $numero === '' || $cidade === '' || !preg_match('/^[A-Z]{2}$/', $uf)) {
            flash('erro', 'Preencha o endereço de entrega (rua, número, cidade e UF).');
            redirect('checkout');
        }
        $cep_fmt = substr($cep, 0, 5) . '-' . substr($cep, 5);
        $endereco_entrega = endereco_formatar([
            'cep' => $cep, 'rua' => $rua, 'numero' => $numero, 'complemento' => $comp,
            'bairro' => $bairro, 'cidade' => $cidade, 'uf' => $uf,
        ]);
        $destino = trim("$rua, $numero, $bairro, $cidade - $uf, $cep_fmt", ' ,');
        $chave = $cep . '-' . preg_replace('/\s+/', '', mb_strtolower($numero));
        $frete = frete_calcular('motoboy', $destino, $chave);
        if (empty($frete['ok'])) {
            // Provedor de distância ainda não ativo, ou fora do raio.
            flash('erro', $frete['mensagem'] ?? 'Não foi possível calcular o frete.');
            redirect('checkout');
        }
        $distancia_km = $frete['distancia_km'];
    }

    // Presente: quem recebe é obrigatório; telefone e mensagem do cartão, opcionais.
    $presente      = isset($_POST['presente']) ? 1 : 0;
    $presente_para = $presente ? mb_substr(trim($_POST['presente_para'] ?? ''), 0, 150) : '';
    $presente_tel  = $presente ? mb_substr(trim($_POST['presente_telefone'] ?? ''), 0, 20) : '';
    $presente_msg  = $presente ? mb_substr(trim($_POST['presente_mensagem'] ?? ''), 0, 300) : '';
    if ($presente && mb_strlen($presente_para) < 2) {
        flash('erro', 'Informe o nome de quem vai receber o presente.');
        redirect('checkout');
    }

    $subtotal   = (int) $c['subtotal'];
    $frete_cent = (int) $frete['frete_centavos'];
    $total      = $subtotal + $frete_cent;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO orders
                (user_id, status, entrega, subtotal_centavos, frete_centavos, total_centavos,
                 pagamento_status, aceitou_termos, observacoes, endereco_entrega,
                 contato_nome, contato_telefone, entrega_distancia_km,
                 presente, presente_para, presente_telefone, presente_mensagem)
             VALUES (?, "realizado", ?, ?, ?, ?, "pendente", 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            (int) $usuario['id'], $entrega, $subtotal, $frete_cent, $total,
            $observacoes !== '' ? $observacoes : null,
            $endereco_entrega,
            $contato_nome !== '' ? $contato_nome : null,
            $contato_tel !== '' ? $contato_tel : null,
            $distancia_km,
            $presente,
            $presente_para !== '' ? $presente_para : null,
            $presente_tel !== '' ? $presente_tel : null,
            $presente_msg !== '' ? $presente_msg : null,
        ]);
        $order_id = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, nome, preco_centavos, quantidade)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($c['linhas'] as $l) {
            $item->execute([
                $order_id, (int) $l['produto']['id'], $l['produto']['nome'],
                (int) $l['produto']['preco_centavos'], (int) $l['qtd'],
            ]);
        }
        $pdo->prepare('INSERT INTO order_status_history (order_id, status) VALUES (?, "realizado")')
            ->execute([$order_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('erro', 'Não foi possível criar o pedido. Tente novamente.');
        redirect('checkout');
    }

    carrinho_limpar();
    flash('sucesso', 'Pedido realizado! Veja os detalhes abaixo.');
    redirect('pedido/' . $order_id);
}

// -----------------------------------------------------------------------------
// GET: exibe o checkout
// -----------------------------------------------------------------------------
$c = _checkout_carrinho();

if (empty($c['linhas'])) {
    ob_start();
    ?>
    <h1>Checkout</h1>
    <p>Seu carrinho está vazio.</p>
    <p class="mt-1"><a class="btn" href="<?= e(url()) ?>">Ver produtos</a></p>
    <?php
    view('layout', ['titulo' => 'Checkout', 'conteudo' => ob_get_clean()]);
    return;
}

$subtotal = (int) $c['subtotal'];
$total    = $subtotal; // inicial: retirada (frete 0)

ob_start();
?>
<h1>Finalizar pedido</h1>

<form class="formulario" method="post" action="<?= e(url('checkout')) ?>" style="max-width:720px;" data-checkout>
    <?= csrf_input() ?>

    <!-- Revisão dos itens -->
    <h2>Seu pedido</h2>
    <table class="tabela">
        <thead>
            <tr>
                <th>Produto</th>
                <th class="t-centro">Qtd.</th>
                <th class="col-acoes">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($c['linhas'] as $l): ?>
                <tr>
                    <td><?= e($l['produto']['nome']) ?></td>
                    <td class="t-centro"><?= (int) $l['qtd'] ?></td>
                    <td class="col-acoes"><?= e(money($l['subtotal'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Entrega -->
    <h2 class="mt-1">Entrega</h2>
    <div class="campo">
        <label class="campo-inline" style="gap:.5rem; font-weight:400;">
            <input type="radio" name="entrega" value="retirada" checked data-entrega> Retirada no local (sem taxa)
        </label>
        <label class="campo-inline" style="gap:.5rem; font-weight:400;">
            <input type="radio" name="entrega" value="motoboy" data-entrega> Entrega por motoboy (frete por distância)
        </label>
    </div>

    <!-- Endereço (só motoboy) -->
    <div data-entrega-endereco hidden>
        <?php if ($tem_end_cad): ?>
            <div class="campo">
                <label class="campo-inline" style="gap:.5rem; font-weight:400;">
                    <input type="radio" name="endereco_opcao" value="cadastro" checked data-endereco-opcao>
                    <span>Entregar no meu endereço<br><small><?= e($cad('endereco')) ?></small></span>
                </label>
                <label class="campo-inline" style="gap:.5rem; font-weight:400;">
                    <input type="radio" name="endereco_opcao" value="outro" data-endereco-opcao>
                    Entregar em outro endereço
                </label>
            </div>
        <?php elseif ($cad('endereco') !== ''): ?>
            <p><small>Endereço do seu cadastro: <?= e($cad('endereco')) ?>.
               Atualize-o em <a href="<?= e(url('meu-perfil')) ?>">Meu perfil</a> para escolhê-lo aqui da próxima vez.</small></p>
        <?php endif; ?>

        <div data-endereco-manual<?= $tem_end_cad ? ' hidden' : '' ?>>
            <div class="campo">
                <label for="cep">CEP</label>
                <input type="text" id="cep" name="cep" inputmode="numeric" maxlength="9" placeholder="Somente números" data-cep>
            </div>
            <div class="campo">
                <label for="rua">Rua</label>
                <input type="text" id="rua" name="rua" data-cep-rua>
            </div>
            <div class="campo">
                <label for="numero">Número</label>
                <input type="text" id="numero" name="numero">
            </div>
            <div class="campo">
                <label for="complemento">Complemento (opcional)</label>
                <input type="text" id="complemento" name="complemento">
            </div>
            <div class="campo">
                <label for="bairro">Bairro</label>
                <input type="text" id="bairro" name="bairro" data-cep-bairro>
            </div>
            <div class="campo">
                <label for="cidade">Cidade</label>
                <input type="text" id="cidade" name="cidade" data-cep-cidade>
            </div>
            <div class="campo">
                <label for="uf">UF</label>
                <input type="text" id="uf" name="uf" maxlength="2" placeholder="Ex.: SP"
                       style="text-transform:uppercase; max-width:6rem;" data-cep-uf>
            </div>
        </div>
        <p><small>O valor do frete é calculado ao confirmar o pedido, pela distância até a loja.</small></p>
    </div>

    <!-- Presente -->
    <div class="campo campo-inline mt-1">
        <input type="checkbox" id="presente" name="presente" value="1" data-presente>
        <label for="presente">É um presente</label>
    </div>
    <div data-presente-campos hidden>
        <div class="campo">
            <label for="presente_para">Nome de quem vai receber</label>
            <input type="text" id="presente_para" name="presente_para" maxlength="150">
        </div>
        <div class="campo">
            <label for="presente_telefone">Telefone de quem vai receber (opcional)</label>
            <input type="tel" id="presente_telefone" name="presente_telefone" inputmode="numeric"
                   placeholder="(11) 91234-5678" data-mask-tel>
            <small>Usado só para combinar a entrega, se precisar.</small>
        </div>
        <div class="campo">
            <label for="presente_mensagem">Mensagem para o cartão (opcional)</label>
            <textarea id="presente_mensagem" name="presente_mensagem" rows="3" maxlength="300"
                      placeholder="Ex.: Feliz aniversário! Com carinho, Ana."></textarea>
            <small>Até 300 caracteres.</small>
        </div>
    </div>

    <!-- Contato -->
    <h2 class="mt-1">Contato</h2>
    <div class="campo">
        <label for="contato_nome">Nome</label>
        <input type="text" id="contato_nome" name="contato_nome" value="<?= e($dados['nome'] ?? '') ?>">
    </div>
    <div class="campo">
        <label for="contato_telefone">Telefone / WhatsApp</label>
        <input type="tel" id="contato_telefone" name="contato_telefone" inputmode="numeric"
               value="<?= e($dados['telefone'] ?? '') ?>" placeholder="(11) 91234-5678" data-mask-tel>
    </div>

    <!-- Observações -->
    <div class="campo">
        <label for="observacoes">Observações (opcional)</label>
        <textarea id="observacoes" name="observacoes" rows="2"></textarea>
    </div>

    <!-- Totais -->
    <h2 class="mt-1">Resumo</h2>
    <table class="tabela">
        <tbody>
            <tr><td>Subtotal</td><td class="col-acoes"><?= e(money($subtotal)) ?></td></tr>
            <tr><td>Frete</td><td class="col-acoes" data-frete>Grátis (retirada)</td></tr>
            <tr><td><strong>Total</strong></td><td class="col-acoes"><strong><?= e(money($total)) ?></strong></td></tr>
        </tbody>
    </table>
    <p><small>Pagamento <?= e(parcelamento_texto($total)) ?> (na próxima etapa).</small></p>

    <!-- Aceite das regras -->
    <div class="campo campo-inline" style="margin-top:1rem;">
        <input type="checkbox" id="checkout-aceite" name="aceite" value="1" data-checkout-aceite>
        <label for="checkout-aceite">Li e concordo com as
            <a href="<?= e(url('regras')) ?>" target="_blank" rel="noopener">regras e o prazo de produção</a>.</label>
    </div>

    <button class="btn" type="submit" data-checkout-confirmar>Confirmar pedido</button>
    <p><small>O pagamento (Pix, cartão) será adicionado em breve. Por enquanto o pedido fica
       registrado como "pendente de pagamento".</small></p>
</form>

<script>
(function () {
    var form = document.querySelector('[data-checkout]');
    if (!form) { return; }

    // Mostra o bloco de endereço só para motoboy.
    var endereco = form.querySelector('[data-entrega-endereco]');
    var freteCel = form.querySelector('[data-frete]');
    function aplicarEntrega() {
        var sel = form.querySelector('[data-entrega]:checked');
        var motoboy = sel && sel.value === 'motoboy';
        if (endereco) { endereco.hidden = !motoboy; }
        if (freteCel) { freteCel.textContent = motoboy ? 'Calculado ao confirmar' : 'Grátis (retirada)'; }
    }
    form.querySelectorAll('[data-entrega]').forEach(function (r) {
        r.addEventListener('change', aplicarEntrega);
    });
    aplicarEntrega();

    // Endereço do perfil x outro endereço (campos manuais).
    var manual = form.querySelector('[data-endereco-manual]');
    var opcoes = form.querySelectorAll('[data-endereco-opcao]');
    if (manual && opcoes.length) {
        var aplicarEndereco = function () {
            var sel = form.querySelector('[data-endereco-opcao]:checked');
            manual.hidden = !(sel && sel.value === 'outro');
        };
        opcoes.forEach(function (r) { r.addEventListener('change', aplicarEndereco); });
        aplicarEndereco();
    }

    // Campos do presente só quando marcado.
    var presente = form.querySelector('[data-presente]');
    var presenteCampos = form.querySelector('[data-presente-campos]');
    if (presente && presenteCampos) {
        var aplicarPresente = function () { presenteCampos.hidden = !presente.checked; };
        presente.addEventListener('change', aplicarPresente);
        aplicarPresente();
    }

    // Habilita "Confirmar pedido" só com o aceite marcado.
    var aceite = form.querySelector('[data-checkout-aceite]');
    var confirmar = form.querySelector('[data-checkout-confirmar]');
    if (aceite && confirmar) {
        var sincronizar = function () { confirmar.disabled = !aceite.checked; };
        aceite.addEventListener('change', sincronizar);
        sincronizar();
    }
    // O autopreenchimento por CEP (ViaCEP) é feito de forma genérica no app.js.
})();
</script>
<?php
view('layout', ['titulo' => 'Checkout', 'conteudo' => ob_get_clean()]);
