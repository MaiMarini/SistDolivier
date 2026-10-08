<?php
/**
 * Conteúdo do pedido no admin: painel lateral (fetch de /admin/pedidos/{id}?parcial=1)
 * ou página própria /admin/pedidos/{id} (sem JS).
 * Espera: $p (orders + cliente_nome/email/tel), $itens, $linha_tempo ([em, texto, pag]),
 *         $em_painel (bool: mostra o X) e $voltar_qs (querystring da lista, para os formulários).
 */
$pid      = (int) $p['id'];
$entrega  = $p['entrega'] === 'motoboy' ? 'motoboy' : 'retirada';
$total    = money((int) $p['total_centavos']);
$prox     = pedido_proximo($p);
$ant      = pedido_anterior($p);
$pendente = pedido_estorno_pendente($p);
[$pag_rot, $pag_cls] = pagamento_etiqueta_admin($p);
$resumo   = pagamento_resumo_admin($p);
$tel      = preg_replace('/\D+/', '', (string) ($p['contato_telefone'] ?: ($p['cliente_tel'] ?? '')));
$wpp      = $tel !== '' ? 'https://wa.me/' . (strlen($tel) <= 11 ? '55' . $tel : $tel)
          . '?text=' . rawurlencode('Olá! Sobre o seu pedido #' . $pid . ' na D\'Olivier:') : '';
$por      = ['loja' => 'Loja', 'sistema' => 'Sistema', 'cliente' => 'Cliente'][$p['cancelado_por'] ?? ''] ?? '—';

/** Formulário de ação (avançar, voltar, estornar...). O JS envia por fetch; sem JS, posta normal. */
$form = function (string $op, array $campos, string $rotulo, string $classe) use ($pid, $voltar_qs): string {
    $h = '<form method="post" action="' . e(url('admin/pedidos')) . '" data-ap-acao data-reabrir="' . $pid . '">'
       . csrf_input()
       . '<input type="hidden" name="op" value="' . e($op) . '">'
       . '<input type="hidden" name="id" value="' . $pid . '">'
       . '<input type="hidden" name="voltar" value="' . e($voltar_qs) . '">';
    foreach ($campos as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $h . '<button type="submit" class="ap-btn ' . $classe . '">' . e($rotulo) . '</button></form>';
};
?>
<div class="ap-painel">
    <div class="ap-dhead">
        <div class="ap-bloco">
            <h2 id="ap-painel-titulo" tabindex="-1">Pedido #<?= $pid ?></h2>
            <span class="ap-sub">Feito em <?= e(date('d/m/Y', strtotime($p['criado_em'])) . ' às ' . date('H:i', strtotime($p['criado_em']))) ?></span>
            <span class="ap-pills">
                <span class="ap-pill <?= e(pedido_status_classe($p['status'])) ?>"><?= e(pedido_status_rotulo($p['status'], $entrega)) ?></span>
                <span class="ap-pill <?= e($pag_cls) ?>"><?= e($pag_rot) ?></span>
            </span>
        </div>
        <?php if (!empty($em_painel)): ?>
            <button type="button" class="ap-x" data-ap-fechar aria-label="Fechar">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        <?php endif; ?>
    </div>

    <?php if ($pendente): ?>
        <div class="ap-alerta">
            <span>Cancelado por: <?= e($por) ?>. Motivo: <?= e($p['cancelamento_motivo'] ?: '—') ?>.
                O pagamento de <?= e($total) ?> continua aprovado.
                <?php if (($p['estorno_status'] ?? '') === 'falhou'): ?>
                    <?php $erro_estorno = pedido_ultimo_erro_estorno($pid); ?>
                    <br>O estorno automático falhou<?= $erro_estorno !== '' ? ': ' . e(pedido_estorno_erro_texto($erro_estorno)) : '.' ?>
                    <br>Se tentar de novo e falhar, estorne pelo painel do Mercado Pago em Atividade → venda → Devolver dinheiro.
                <?php endif; ?></span>
            <?= $form('estornar', [], 'Estornar ' . $total, 'ap-btn-perigo-cheio') ?>
        </div>
    <?php elseif ($p['status'] === 'cancelado'): ?>
        <div class="ap-caixa">
            <b>Cancelado por: <?= e($por) ?></b>
            <span>Motivo: <?= e($p['cancelamento_motivo'] ?: pedido_cancelamento_texto($p)) ?><?= !empty($p['cancelamento_obs']) ? ' · ' . e($p['cancelamento_obs']) : '' ?></span>
            <span><?= e(pedido_reembolso_texto($p) ?: (($p['pagamento_status'] ?? '') === 'aprovado' ? '' : 'Nenhum valor foi cobrado.')) ?></span>
        </div>
    <?php elseif ($p['status'] === 'aguardando_pagamento'): ?>
        <div class="ap-caixa<?= $resumo['alerta'] ? ' is-alerta' : '' ?>"><span><?= e($resumo['texto']) ?></span></div>
    <?php endif; ?>

    <?php if (!in_array($p['status'], ['cancelado', 'aguardando_pagamento'], true)): ?>
        <div>
            <p class="ap-eyebrow">Produção</p>
            <?php $fluxo = pedido_fluxo($entrega); $idx = array_search($p['status'], $fluxo, true); ?>
            <ol class="ap-etapas" style="--n: <?= count($fluxo) ?>">
                <?php foreach ($fluxo as $i => $e):
                    $estado = ($p['status'] === 'finalizado' || $i < $idx) ? 'done' : ($i === $idx ? 'cur' : ''); ?>
                    <li class="<?= $estado ?>"><i aria-hidden="true"></i><?= e(['realizado' => 'Novo', 'producao' => 'Em produção',
                        'embalagem' => 'Embalagem', 'pronto' => 'Pronto', 'em_rota' => 'Em rota', 'finalizado' => 'Finalizado'][$e]) ?>
                        <?php if ($estado === 'cur'): ?><span class="ap-sr">(etapa atual)</span><?php endif; ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php if ($prox): ?>
            <?= $form('etapa', ['de' => $p['status'], 'para' => $prox[0]], $prox[1], 'ap-btn-primario ap-btn-largo') ?>
        <?php endif; ?>
    <?php endif; ?>

    <div class="ap-bloco">
        <p class="ap-eyebrow">Cliente</p>
        <b><?= e($p['cliente_nome'] ?: ($p['contato_nome'] ?: '—')) ?></b>
        <?php if (!empty($p['contato_telefone']) || !empty($p['cliente_tel'])): ?>
            <span class="ap-num"><?= e($p['contato_telefone'] ?: $p['cliente_tel']) ?></span>
        <?php endif; ?>
        <?php if (!empty($p['cliente_email'])): ?><span class="ap-sub"><?= e($p['cliente_email']) ?></span><?php endif; ?>
        <?php if ($wpp !== ''): ?>
            <div class="ap-acoes-linha"><a class="ap-btn ap-btn-oliva" href="<?= e($wpp) ?>" target="_blank" rel="noopener">WhatsApp do cliente</a></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($p['presente'])): ?>
        <div class="ap-caixa">
            <p class="ap-eyebrow">Presente</p>
            <b>Para: <?= e((string) $p['presente_para']) ?><?= !empty($p['presente_telefone']) ? ' · ' . e($p['presente_telefone']) : '' ?></b>
            <?php if (!empty($p['presente_mensagem'])): ?><span>Cartão: “<?= e($p['presente_mensagem']) ?>”</span><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="ap-bloco">
        <p class="ap-eyebrow">Entrega</p>
        <b><?= $entrega === 'motoboy'
            ? 'Motoboy' . (!empty($p['entrega_distancia_km']) ? ' (' . e(number_format((float) $p['entrega_distancia_km'], 1, ',', '')) . ' km)' : '')
            : 'Retirada na loja' ?></b>
        <?php if ($entrega === 'motoboy' && !empty($p['endereco_entrega'])): ?><span><?= e($p['endereco_entrega']) ?></span><?php endif; ?>
        <?php if (!empty($p['observacoes'])): ?><span class="ap-sub">Observações: <?= e($p['observacoes']) ?></span><?php endif; ?>
    </div>

    <div>
        <p class="ap-eyebrow">Itens</p>
        <div class="ap-linhas">
            <?php foreach ($itens as $it): ?>
                <div><span><?= (int) $it['quantidade'] ?>× <?= e($it['nome']) ?></span>
                    <span class="ap-num"><?= e(money((int) $it['preco_centavos'] * (int) $it['quantidade'])) ?></span></div>
            <?php endforeach; ?>
            <div><span><?= $entrega === 'motoboy' ? 'Frete' : 'Retirada' ?></span>
                <span class="ap-num"><?= (int) $p['frete_centavos'] > 0 ? e(money((int) $p['frete_centavos'])) : 'Grátis' ?></span></div>
            <div class="ap-total"><span>Total</span><span class="ap-num"><?= e($total) ?></span></div>
        </div>
    </div>

    <div class="ap-bloco">
        <p class="ap-eyebrow">Pagamento</p>
        <b><?= e(!empty($p['pagamento']) ? pagamento_forma_rotulo($p['pagamento']) : 'Sem pagamento online') ?> · <?= e($pag_rot) ?></b>
        <?php if ($p['status'] !== 'aguardando_pagamento' && !$pendente): ?><span class="ap-sub"><?= e($resumo['texto']) ?></span><?php endif; ?>
        <?php if (!empty($p['mp_payment_id'])): ?>
            <span class="ap-sub">Operação nº <?= e($p['mp_payment_id']) ?> ·
                <a href="<?= e('https://www.mercadopago.com.br/activities/detail/' . rawurlencode((string) $p['mp_payment_id'])) ?>"
                   target="_blank" rel="noopener">Ver no Mercado Pago</a></span>
        <?php endif; ?>
    </div>

    <?php if (!empty($linha_tempo)): ?>
        <details class="ap-historico">
            <summary>Histórico</summary>
            <ul>
                <?php foreach ($linha_tempo as $h): ?>
                    <li<?= $h['pag'] ? ' class="is-pagamento"' : '' ?>><?= e($h['texto']) ?>
                        <span class="ap-sub">— <?= e(date('d/m/Y H:i', strtotime($h['em']))) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <?php if ($ant || !in_array($p['status'], ['cancelado', 'finalizado'], true)): ?>
        <div class="ap-outras">
            <p class="ap-eyebrow">Outras ações</p>
            <div class="ap-acoes-linha">
                <?php if ($ant): ?>
                    <?= $form('etapa', ['de' => $p['status'], 'para' => $ant],
                        '← Voltar para ' . pedido_status_rotulo($ant, $entrega), 'ap-btn-linha') ?>
                <?php endif; ?>
            </div>
            <?php if (!in_array($p['status'], ['cancelado', 'finalizado'], true)): ?>
                <!-- <details>: abre o formulário sem confirm() e funciona sem JS -->
                <details class="ap-cancelar">
                    <summary class="ap-btn ap-btn-perigo">Cancelar pedido</summary>
                    <form method="post" action="<?= e(url('admin/pedidos')) ?>" class="ap-cancelbox" data-ap-acao data-reabrir="<?= $pid ?>">
                        <?= csrf_input() ?>
                        <input type="hidden" name="op" value="cancelar">
                        <input type="hidden" name="id" value="<?= $pid ?>">
                        <input type="hidden" name="de" value="<?= e($p['status']) ?>">
                        <input type="hidden" name="voltar" value="<?= e($voltar_qs) ?>">
                        <p class="ap-eyebrow">Cancelar pedido #<?= $pid ?></p>
                        <label class="ap-campo" for="ap-motivo-<?= $pid ?>">Motivo
                            <select id="ap-motivo-<?= $pid ?>" name="motivo" required>
                                <option value="">Escolha o motivo</option>
                                <?php foreach (CANCELAMENTO_MOTIVOS as $m): ?>
                                    <option><?= e($m) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="ap-campo" for="ap-obs-<?= $pid ?>">Observação <span class="ap-sub">(opcional, a cliente vê)</span>
                            <textarea id="ap-obs-<?= $pid ?>" name="obs" maxlength="300"
                                      placeholder="Ex.: acabou o recheio de goiabada para esta data."></textarea>
                        </label>
                        <?php if (($p['pagamento_status'] ?? '') === 'aprovado'): ?>
                            <label class="ap-check" for="ap-estornar-<?= $pid ?>">
                                <input type="checkbox" id="ap-estornar-<?= $pid ?>" name="estornar" value="1" checked>
                                <span>Estornar <?= e($total) ?> pelo Mercado Pago</span>
                            </label>
                        <?php endif; ?>
                        <div class="ap-acoes-linha">
                            <button type="submit" class="ap-btn ap-btn-perigo">Confirmar cancelamento</button>
                        </div>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
