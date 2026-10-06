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
            "SELECT id, slug, nome, preco_centavos, imagem FROM products WHERE id IN ($ph) AND ativo = 1"
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

// Prévia do frete (app.js chama /frete/calcular). O POST acima recalcula sempre.
$frete_ativo = cfg('frete_provedor', 'off') !== 'off';
// Começa em Motoboy quando dá para estimar já ao abrir (endereço do perfil completo).
$modo_inicial = ($frete_ativo && $tem_end_cad) ? 'motoboy' : 'retirada';
// Fase 3 (Mercado Pago) ainda não existe: o botão só confirma o pedido.
$pagamento_online = false;

// "Seus dados": com nome e telefone já preenchidos, mostra uma linha só.
$contato_completo = $cad('nome') !== '' && $cad('telefone') !== '';

$ico_moto  = '<svg class="ck-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5.5" cy="17" r="3"/><circle cx="18.5" cy="17" r="3"/><path d="M8.5 17h6l-3-7H8M14 6h3l1.5 8M11.5 10h5"/></svg>';
$ico_loja  = '<svg class="ck-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8M10 20v-5h4v5"/></svg>';
$ico_gift  = '<svg class="ck-ico ck-ico-presente" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v8h14v-8M12 8v12M12 8S10.5 4 8 4.5 7.5 8 12 8zM12 8s1.5-4 4-3.5S16.5 8 12 8z"/></svg>';
$ico_aviso = '<svg class="ck-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l9.5 17h-19z"/><path d="M12 10v4M12 17v.5"/></svg>';
$ico_cadeado = '<svg class="ck-ico ck-ico-p" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';

ob_start();
?>
<h1>Finalizar pedido</h1>

<form class="checkout" method="post" action="<?= e(url('checkout')) ?>" data-checkout
      data-subtotal="<?= $subtotal ?>" data-frete-url="<?= e(url('frete/calcular')) ?>"
      data-csrf="<?= e(csrf_token()) ?>" data-frete-ativo="<?= $frete_ativo ? '1' : '0' ?>">
    <?= csrf_input() ?>

    <div class="checkout-grid">
        <div class="checkout-col">

            <!-- 1. Entrega -->
            <section class="ck-card" aria-labelledby="ck-titulo-entrega">
                <h2 class="ck-titulo" id="ck-titulo-entrega">
                    <span class="ck-num" aria-hidden="true">1</span>
                    <span>Entrega <span class="ck-est" data-ck-est<?= $modo_inicial === 'motoboy' ? '' : ' hidden' ?>>– Estimado</span></span>
                </h2>

                <div class="ck-modos" role="radiogroup" aria-labelledby="ck-titulo-entrega">
                    <label class="ck-modo ck-modo--moto" data-ck-modo="motoboy">
                        <input class="ck-sr" type="radio" name="entrega" value="motoboy" data-entrega
                               <?= $modo_inicial === 'motoboy' ? 'checked' : '' ?><?= $frete_ativo ? '' : ' disabled' ?>>
                        <span class="ck-dot" aria-hidden="true"></span>
                        <span class="ck-modo-rotulo"><?= $ico_moto ?> Motoboy</span>
                        <span class="ck-modo-valor" data-ck-moto-valor><?= $frete_ativo ? '—' : 'Indisponível' ?></span>
                        <span class="ck-pequeno" data-ck-moto-detalhe><?= $frete_ativo ? 'Informe o endereço' : 'Entrega por motoboy indisponível no momento' ?></span>
                    </label>
                    <label class="ck-modo ck-modo--retirada" data-ck-modo="retirada">
                        <input class="ck-sr" type="radio" name="entrega" value="retirada" data-entrega
                               <?= $modo_inicial === 'retirada' ? 'checked' : '' ?>>
                        <span class="ck-dot" aria-hidden="true"></span>
                        <span class="ck-modo-rotulo"><?= $ico_loja ?> Retirada</span>
                        <span class="ck-modo-valor ck-gratis">Grátis</span>
                        <span class="ck-pequeno">Na loja</span>
                    </label>
                </div>

                <!-- Entregar em (só motoboy) -->
                <div class="ck-sub" data-entrega-endereco<?= $modo_inicial === 'motoboy' ? '' : ' hidden' ?>>
                    <span class="ck-sub-titulo" id="ck-entregar-em">Entregar em</span>

                    <?php if ($tem_end_cad): ?>
                        <div class="ck-enderecos" role="radiogroup" aria-labelledby="ck-entregar-em">
                            <label class="ck-end">
                                <input class="ck-sr" type="radio" name="endereco_opcao" value="cadastro" checked data-endereco-opcao>
                                <span class="ck-r" aria-hidden="true"></span>
                                <span class="ck-end-txt"><b>Meu endereço</b>
                                    <span class="ck-pequeno"><?= e($cad('endereco')) ?></span></span>
                            </label>
                            <label class="ck-end">
                                <input class="ck-sr" type="radio" name="endereco_opcao" value="outro" data-endereco-opcao>
                                <span class="ck-r" aria-hidden="true"></span>
                                <span class="ck-end-txt"><b>Outro endereço</b>
                                    <span class="ck-pequeno">Para entregar em outro lugar, como no endereço de quem vai ganhar o presente</span></span>
                            </label>
                        </div>
                    <?php elseif ($cad('endereco') !== ''): ?>
                        <p class="ck-pequeno">Endereço do seu cadastro: <?= e($cad('endereco')) ?>.
                           Atualize-o em <a href="<?= e(url('meu-perfil')) ?>">Meu perfil</a> para escolhê-lo aqui da próxima vez.</p>
                    <?php endif; ?>

                    <div class="ck-manual" data-endereco-manual<?= $tem_end_cad ? ' hidden' : '' ?>>
                        <div class="ck-linha3">
                            <div class="campo">
                                <label for="cep">CEP</label>
                                <input type="text" id="cep" name="cep" inputmode="numeric" maxlength="9"
                                       placeholder="00000-000" data-cep data-ck-end-campo>
                            </div>
                            <div class="campo">
                                <label for="numero">Número</label>
                                <input type="text" id="numero" name="numero" data-ck-end-campo>
                            </div>
                            <div class="campo">
                                <label for="complemento">Complemento <span class="ck-opcional">(opcional)</span></label>
                                <input type="text" id="complemento" name="complemento">
                            </div>
                        </div>
                        <div class="campo">
                            <label for="rua">Rua</label>
                            <input type="text" id="rua" name="rua" data-cep-rua data-ck-end-campo>
                        </div>
                        <div class="ck-linha3 ck-linha3--local">
                            <div class="campo">
                                <label for="bairro">Bairro</label>
                                <input type="text" id="bairro" name="bairro" data-cep-bairro data-ck-end-campo>
                            </div>
                            <div class="campo">
                                <label for="cidade">Cidade</label>
                                <input type="text" id="cidade" name="cidade" data-cep-cidade data-ck-end-campo>
                            </div>
                            <div class="campo">
                                <label for="uf">UF</label>
                                <input type="text" id="uf" name="uf" maxlength="2" placeholder="SP"
                                       style="text-transform:uppercase;" data-cep-uf data-ck-end-campo>
                            </div>
                        </div>
                        <p class="ck-pequeno">Rua, bairro, cidade e UF são preenchidos pelo CEP.</p>
                    </div>

                    <div class="ck-aviso" data-ck-aviso role="status" hidden>
                        <?= $ico_aviso ?>
                        <span>Este endereço fica fora da nossa área de entrega por motoboy. Você pode retirar na
                              loja sem custo ou informar outro endereço.</span>
                    </div>
                </div>
            </section>

            <!-- Presente -->
            <section class="ck-card">
                <label class="ck-switch" for="presente">
                    <input class="ck-sr" type="checkbox" id="presente" name="presente" value="1"
                           role="switch" data-presente>
                    <?= $ico_gift ?>
                    <span class="ck-switch-txt"><b>É um presente?</b>
                        <span class="ck-pequeno">Incluímos um cartão com a sua mensagem e falamos com quem vai receber.</span></span>
                    <span class="ck-tog" aria-hidden="true"></span>
                </label>
                <div class="ck-presente-campos" data-presente-campos hidden>
                    <div class="ck-linha2">
                        <div class="campo">
                            <label for="presente_para">Nome de quem vai receber</label>
                            <input type="text" id="presente_para" name="presente_para" maxlength="150">
                        </div>
                        <div class="campo">
                            <label for="presente_telefone">Telefone de quem vai receber <span class="ck-opcional">(opcional)</span></label>
                            <input type="tel" id="presente_telefone" name="presente_telefone" inputmode="numeric"
                                   placeholder="(11) 91234-5678" data-mask-tel>
                        </div>
                    </div>
                    <div class="campo">
                        <label for="presente_mensagem">Mensagem para o cartão <span class="ck-opcional">(opcional)</span></label>
                        <textarea id="presente_mensagem" name="presente_mensagem" rows="3" maxlength="300"
                                  placeholder="Ex.: Feliz aniversário! Com carinho, Ana."
                                  aria-describedby="presente_contador" data-ck-mensagem></textarea>
                        <span class="ck-pequeno" id="presente_contador" data-ck-contador aria-live="polite">0 de 300 caracteres</span>
                    </div>
                </div>
            </section>

            <!-- 2. Seus dados -->
            <section class="ck-card" aria-labelledby="ck-titulo-dados">
                <h2 class="ck-titulo" id="ck-titulo-dados"><span class="ck-num" aria-hidden="true">2</span> Seus dados</h2>
                <?php if ($contato_completo): ?>
                    <div class="ck-contato" data-ck-contato-resumo>
                        <span class="ck-contato-txt"><b><?= e($cad('nome')) ?></b> · <span data-ck-tel-resumo><?= e($cad('telefone')) ?></span></span>
                        <button type="button" class="ck-link" data-ck-contato-alterar
                                aria-expanded="false" aria-controls="ck-contato-campos">Alterar</button>
                    </div>
                <?php endif; ?>
                <div class="ck-linha2" id="ck-contato-campos" data-ck-contato-campos<?= $contato_completo ? ' hidden' : '' ?>>
                    <div class="campo">
                        <label for="contato_nome">Nome</label>
                        <input type="text" id="contato_nome" name="contato_nome" value="<?= e($cad('nome')) ?>">
                    </div>
                    <div class="campo">
                        <label for="contato_telefone">Telefone / WhatsApp</label>
                        <input type="tel" id="contato_telefone" name="contato_telefone" inputmode="numeric"
                               value="<?= e($cad('telefone')) ?>" placeholder="(11) 91234-5678" data-mask-tel>
                    </div>
                </div>
                <div class="campo">
                    <label for="observacoes">Observações <span class="ck-opcional">(opcional)</span></label>
                    <textarea id="observacoes" name="observacoes" rows="2" placeholder="Algo que a loja precisa saber?"></textarea>
                </div>
            </section>
        </div>

        <!-- Resumo (sticky no computador) -->
        <aside class="checkout-resumo" data-ck-resumo>
            <section class="ck-card" aria-labelledby="ck-titulo-resumo">
                <h2 class="ck-titulo" id="ck-titulo-resumo">Resumo</h2>
                <ul class="ck-itens">
                    <?php foreach ($c['linhas'] as $l): ?>
                        <li class="ck-item">
                            <?php if (!empty($l['produto']['imagem'])): ?>
                                <img class="ck-thumb" src="<?= e(url('assets/uploads/' . $l['produto']['imagem'])) ?>" alt="">
                            <?php else: ?>
                                <span class="ck-thumb" aria-hidden="true"></span>
                            <?php endif; ?>
                            <span class="ck-item-txt"><b><?= e($l['produto']['nome']) ?></b>
                                <span class="ck-pequeno"><?= (int) $l['qtd'] ?> × <?= e(money((int) $l['produto']['preco_centavos'])) ?></span></span>
                            <span class="ck-preco"><?= e(money($l['subtotal'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div class="ck-soma">
                    <div class="ck-soma-linha"><span class="ck-rot">Subtotal</span><span class="ck-preco ck-preco-leve"><?= e(money($subtotal)) ?></span></div>
                    <div class="ck-soma-linha">
                        <span class="ck-rot" data-ck-frete-rotulo>Retirada na loja</span>
                        <span data-ck-frete-valor><span class="ck-preco ck-gratis">Grátis</span></span>
                    </div>
                    <p class="ck-pequeno ck-nota" data-ck-nota hidden>Não conseguimos calcular o frete agora. Ele é confirmado ao finalizar o pedido.</p>
                    <div class="ck-soma-linha ck-total"><span class="ck-rot">Total</span><span class="ck-preco" data-ck-total><?= e(money($subtotal)) ?></span></div>
                </div>

                <label class="ck-termos" for="checkout-aceite">
                    <input type="checkbox" id="checkout-aceite" name="aceite" value="1" data-checkout-aceite>
                    <span>Li e concordo com as
                        <a href="<?= e(url('regras')) ?>" target="_blank" rel="noopener">regras e o prazo de produção</a>.</span>
                </label>

                <button class="ck-cta" type="submit" data-checkout-confirmar>
                    <span><?= $pagamento_online ? 'Ir para o pagamento' : 'Confirmar pedido' ?></span>
                    <span class="ck-cta-sep" aria-hidden="true">·</span>
                    <span data-ck-cta-total><?= e(money($subtotal)) ?></span>
                </button>
                <p class="ck-pagamento"><?= $ico_cadeado ?> Pagamento <?= e(parcelamento_texto($subtotal)) ?> na próxima etapa</p>
            </section>
        </aside>
    </div>
</form>
<?php
view('layout', ['titulo' => 'Checkout', 'conteudo' => ob_get_clean()]);
