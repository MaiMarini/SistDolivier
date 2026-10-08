<?php
/**
 * Detalhe de um pedido — usado em "Meus pedidos" (painel à direita) e em /pedido/{id}.
 * Espera: $pedido (linha de orders), $itens (order_items), $quando (status => data,
 * de pedido_historico) e $voltar (bool: mostra "← Meus pedidos").
 * Quem inclui já conferiu que o pedido é da cliente logada.
 */
$pid   = (int) $pedido['id'];
$sit   = pedido_situacao($pedido);
$passos = pedido_andamento($pedido, $quando);
$total = money((int) $pedido['total_centavos']);
$motoboy = $pedido['entrega'] === 'motoboy';
$ps    = (string) ($pedido['pagamento_status'] ?? '');

// Pagamento: forma + situação (o cancelamento vem primeiro: um pedido pago pode ter sido cancelado).
if ($pedido['status'] === 'cancelado') {
    if ($ps === 'estornado' || in_array((string) ($pedido['estorno_status'] ?? ''), ['aprovado', 'approved'], true)) {
        $pag_titulo = 'Reembolsado';
        $pag_texto  = pedido_reembolso_texto($pedido);
    } elseif ($ps === 'aprovado') {
        $pag_titulo = pagamento_forma_rotulo($pedido['pagamento'] ?? null);
        $pag_texto  = 'Pago. A loja vai devolver o valor.';
    } else {
        $pag_titulo = 'Não pago';
        $pag_texto  = 'Nenhum valor foi cobrado.';
    }
} elseif ($ps === 'aprovado') {
    $pag_titulo = pagamento_forma_rotulo($pedido['pagamento'] ?? null);
    $pag_texto  = !empty($pedido['pago_em']) ? 'Aprovado em ' . date('d/m, H:i', strtotime($pedido['pago_em'])) : 'Aprovado';
} elseif (in_array($pedido['status'], ['producao', 'embalagem', 'pronto', 'em_rota', 'finalizado'], true)) {
    $pag_titulo = 'Confirmado';
    $pag_texto  = 'Combinado com a loja';
} else {
    $pag_titulo = !empty($pedido['pagamento']) ? pagamento_forma_rotulo($pedido['pagamento']) : 'Pendente';
    $pag_texto  = $sit['rotulo'];
}

// Prazo para pagar (só enquanto aguarda pagamento).
$prazo = $pedido['status'] === 'aguardando_pagamento' ? pagamento_prazo($pid) : null;

// WhatsApp da loja com o nº do pedido.
$wpp = preg_replace('/\D+/', '', (string) cfg('whatsapp_numero', ''));
$wpp_link = $wpp !== ''
    ? 'https://wa.me/' . $wpp . '?text=' . rawurlencode('Olá! Quero falar sobre o pedido #' . $pid . '.')
    : '';

$ico = fn ($d) => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
?>
<article class="mp-detalhe" aria-labelledby="mp-titulo-<?= $pid ?>">
    <?php if (!empty($voltar)): ?>
        <a class="mp-voltar" href="<?= e(url('meus-pedidos')) ?>"><?= $ico('<path d="M15 6l-6 6 6 6"/>') ?> Meus pedidos</a>
    <?php endif; ?>

    <div class="mp-dhead">
        <div>
            <h2 id="mp-titulo-<?= $pid ?>">Pedido #<?= $pid ?></h2>
            <span class="mp-quando">Feito em <?= e(date('d/m/Y, H:i', strtotime($pedido['criado_em']))) ?></span>
        </div>
        <span class="mp-pill <?= e($sit['classe']) ?>"><?= e($sit['rotulo']) ?></span>
    </div>

    <?php if ($sit['aviso'] !== ''): ?>
        <div class="mp-callout <?= $sit['classe'] === 'st-pagar' ? '' : ($sit['classe'] === 'st-cancelado' ? 'is-cancelado' : 'is-neutro') ?>">
            <span><?= e($sit['aviso']) ?>
                <?php if ($prazo && $sit['acao'] !== null): ?>
                    <small class="mp-prazo">Pague até <?= e($prazo->format('d/m') . ' às ' . $prazo->format('H:i')) ?>.</small>
                <?php endif; ?>
            </span>
            <?php if ($sit['acao'] === 'pagar' || $sit['acao'] === 'tentar'): ?>
                <form method="post" action="<?= e(url('pagamento/pagar')) ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="pedido_id" value="<?= $pid ?>">
                    <button class="mp-btn mp-btn-primary" type="submit">
                        <?= $sit['acao'] === 'pagar' ? 'Pagar agora' : 'Tentar novamente' ?> · <?= e($total) ?>
                    </button>
                </form>
            <?php elseif ($sit['acao'] === 'ticket'): ?>
                <a class="mp-btn mp-btn-primary" href="<?= e($pedido['pagamento_ticket_url']) ?>" target="_blank" rel="noopener">
                    <?= ($pedido['pagamento_tipo'] ?? '') === 'ticket' ? 'Ver boleto' : 'Ver código do Pix' ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div>
        <p class="mp-eyebrow">Andamento</p>
        <ol class="mp-timeline">
            <?php foreach ($passos as [$rotulo, $texto, $estado]): ?>
                <li class="is-<?= e($estado) ?>">
                    <span class="mp-rail" aria-hidden="true"><span class="mp-dot"></span><span class="mp-line"></span></span>
                    <span class="mp-tl-texto">
                        <?php if ($estado === 'todo'): ?>
                            <span class="mp-todo"><?= e($rotulo) ?></span>
                        <?php else: ?>
                            <b><?= e($rotulo) ?></b>
                        <?php endif; ?>
                        <?php if ($texto !== ''): ?><span class="mp-quando"><?= e($texto) ?></span><?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <div class="mp-info">
        <div>
            <p class="mp-eyebrow">Entrega</p>
            <?php if ($motoboy): ?>
                <b>Motoboy<?php if (!empty($pedido['entrega_distancia_km'])): ?>
                    (<?= e(number_format((float) $pedido['entrega_distancia_km'], 1, ',', '')) ?> km)<?php endif; ?></b>
                <span><?= e((string) $pedido['endereco_entrega']) ?></span>
            <?php else: ?>
                <b>Retirada na loja</b>
                <span><?= !empty($pedido['endereco_entrega']) && $pedido['endereco_entrega'] !== 'Retirada no local'
                    ? e((string) $pedido['endereco_entrega']) : 'Avisamos quando estiver pronto para retirar.' ?></span>
            <?php endif; ?>
            <?php if (!empty($pedido['presente'])): ?>
                <span class="mp-quando">Presente para <?= e((string) $pedido['presente_para']) ?></span>
            <?php endif; ?>
        </div>
        <div>
            <p class="mp-eyebrow">Pagamento</p>
            <b><?= e($pag_titulo) ?></b>
            <span><?= e($pag_texto) ?></span>
        </div>
    </div>

    <div class="mp-totais">
        <?php foreach ($itens as $it): ?>
            <div><span><?= (int) $it['quantidade'] ?>× <?= e($it['nome']) ?></span>
                <span class="mp-num"><?= e(money((int) $it['preco_centavos'] * (int) $it['quantidade'])) ?></span></div>
        <?php endforeach; ?>
        <?php if ($motoboy): ?>
            <div><span>Frete</span><span class="mp-num"><?= e(money((int) $pedido['frete_centavos'])) ?></span></div>
        <?php else: ?>
            <div><span>Retirada</span><span class="mp-num">Grátis</span></div>
        <?php endif; ?>
        <div class="mp-total"><span>Total</span><span class="mp-num"><?= e($total) ?></span></div>
    </div>

    <?php if (!empty($pedido['observacoes'])): ?>
        <p class="mp-quando">Observações: <?= e($pedido['observacoes']) ?></p>
    <?php endif; ?>

    <div class="mp-acoes">
        <?php if ($wpp_link !== ''): ?>
            <a class="mp-btn mp-btn-oliva" href="<?= e($wpp_link) ?>" target="_blank" rel="noopener">
                <?= $ico('<path d="M4 20l1.3-4A8 8 0 1 1 8 18.8z"/>') ?> Falar sobre este pedido</a>
        <?php endif; ?>
        <?php if (!empty($itens)): ?>
            <form method="post" action="<?= e(url('carrinho')) ?>" data-carrinho-ajax>
                <?= csrf_input() ?>
                <input type="hidden" name="acao" value="repetir">
                <input type="hidden" name="pedido_id" value="<?= $pid ?>">
                <button class="mp-btn mp-btn-marrom" type="submit">Pedir de novo</button>
            </form>
        <?php endif; ?>
    </div>
</article>
