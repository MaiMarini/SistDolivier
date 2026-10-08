<?php
/**
 * Etapas do pedido, mudanças de status (com histórico), cancelamento e estorno.
 *
 *   aguardando_pagamento -> realizado ("Novo") -> producao -> embalagem -> pronto
 *      retirada: pronto -> finalizado ("Retirado")
 *      motoboy:  pronto -> em_rota -> finalizado ("Entregue")
 *   cancelado: pela loja (admin), pelo sistema (24h sem pagamento) ou pela cliente.
 *
 * Toda mudança usa "UPDATE ... WHERE id = ? AND status = ?": se outra tela mudou
 * o pedido antes, nada é gravado e a tela avisa.
 */

const CANCELAMENTO_MOTIVOS = [
    'Pedido do cliente',
    'Produto indisponível',
    'Fora da área / sem entregador',
    'Pagamento não identificado',
    'Outro',
];

/** Etapas de produção na ordem, conforme a entrega (sem "aguardando" e "cancelado"). */
function pedido_fluxo(string $entrega): array
{
    return $entrega === 'motoboy'
        ? ['realizado', 'producao', 'embalagem', 'pronto', 'em_rota', 'finalizado']
        : ['realizado', 'producao', 'embalagem', 'pronto', 'finalizado'];
}

/** Rótulo de um status. $para = 'admin' ou 'cliente' (só "realizado" e "em_rota" mudam). */
function pedido_status_rotulo(string $status, string $entrega, string $para = 'admin'): string
{
    $motoboy = $entrega === 'motoboy';
    switch ($status) {
        case 'aguardando_pagamento': return 'Aguardando pagamento';
        case 'realizado':  return $para === 'admin' ? 'Novo' : 'Pagamento aprovado';
        case 'producao':   return 'Em produção';
        case 'embalagem':  return 'Embalagem';
        case 'pronto':     return $motoboy ? 'Pronto para entrega' : 'Pronto para retirada';
        case 'em_rota':    return $para === 'admin' ? 'Em rota de entrega' : 'Saiu para entrega';
        case 'finalizado': return $motoboy ? 'Entregue' : 'Retirado';
        case 'cancelado':  return 'Cancelado';
    }
    return $status;
}

/** Classe da etiqueta do status no admin. */
function pedido_status_classe(string $status): string
{
    return [
        'aguardando_pagamento' => 'ap-p-pagar', 'realizado' => 'ap-p-novo', 'producao' => 'ap-p-prod',
        'embalagem' => 'ap-p-emb', 'pronto' => 'ap-p-pronto', 'em_rota' => 'ap-p-rota',
        'finalizado' => 'ap-p-fim', 'cancelado' => 'ap-p-cancel',
    ][$status] ?? 'ap-p-neutro';
}

/** Etiqueta do pagamento no admin: [rótulo, classe]. */
function pagamento_etiqueta_admin(array $p): array
{
    $ps = (string) ($p['pagamento_status'] ?? '');
    if ($ps === 'em_analise' && pagamento_aguardando_pix_boleto($p)) {
        return [($p['pagamento_tipo'] ?? '') === 'ticket' ? 'Pendente (boleto)' : 'Pendente (Pix)', 'ap-p-neutro'];
    }
    $classe = [
        'aprovado' => 'ap-p-fim', 'recusado' => 'ap-p-cancel', 'cancelado' => 'ap-p-cancel',
        'divergente' => 'ap-p-cancel', 'estornado' => 'ap-p-neutro',
    ][$ps] ?? 'ap-p-neutro';
    return [pagamento_status_rotulo($ps !== '' ? $ps : null), $classe];
}

/** Próxima etapa e o texto do botão, ou null. */
function pedido_proximo(array $p): ?array
{
    $motoboy = ($p['entrega'] ?? '') === 'motoboy';
    switch ($p['status']) {
        case 'realizado': return ['producao', 'Iniciar produção'];
        case 'producao':  return ['embalagem', 'Enviar para embalagem'];
        case 'embalagem': return ['pronto', 'Marcar como pronto'];
        case 'pronto':    return $motoboy ? ['em_rota', 'Saiu para entrega'] : ['finalizado', 'Confirmar retirada'];
        case 'em_rota':   return ['finalizado', 'Confirmar entrega'];
    }
    return null;
}

/** Etapa anterior (para "← Voltar para ..."), ou null. Não volta para antes de "Novo". */
function pedido_anterior(array $p): ?string
{
    $fluxo = pedido_fluxo((string) ($p['entrega'] ?? ''));
    $i = array_search($p['status'], $fluxo, true);
    return ($i !== false && $i > 0) ? $fluxo[$i - 1] : null;
}

/** Grava uma linha em pedido_historico (sem a tabela, não faz nada). */
function pedido_historico_gravar(int $id, ?string $de, string $para, string $origem, ?int $usuario = null, ?string $obs = null): void
{
    try {
        db()->prepare(
            'INSERT INTO pedido_historico (order_id, status_de, status_para, usuario_id, origem, observacao)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $de, $para, $usuario, $origem, $obs !== null ? mb_substr($obs, 0, 300) : null]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') {
            throw $e;
        }
    }
}

/**
 * Avança ou volta UMA etapa no admin. Só grava se o status atual for $de e se $para
 * for a etapa seguinte ou a anterior (o "Desfazer" é sempre uma dessas duas).
 * Devolve 'ok' | 'conflito' | 'invalido'.
 */
function pedido_mudar_etapa(int $id, string $de, string $para, ?int $usuario): string
{
    $st = db()->prepare('SELECT id, status, entrega FROM orders WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        return 'invalido';
    }
    if ($p['status'] !== $de) {
        return 'conflito';
    }
    $prox = pedido_proximo($p);
    if ($para !== ($prox[0] ?? null) && $para !== pedido_anterior($p)) {
        return 'invalido';
    }
    $up = db()->prepare('UPDATE orders SET status = ? WHERE id = ? AND status = ?');
    $up->execute([$para, $id, $de]);
    if ($up->rowCount() === 0) {
        return 'conflito';
    }
    pedido_historico_gravar($id, $de, $para, 'admin', $usuario);
    return 'ok';
}

/** Cancelado com pagamento aprovado e sem estorno concluído: a loja precisa decidir. */
function pedido_estorno_pendente(array $p): bool
{
    return ($p['status'] ?? '') === 'cancelado'
        && ($p['pagamento_status'] ?? '') === 'aprovado'
        && !in_array((string) ($p['estorno_status'] ?? ''), ['aprovado', 'approved', 'in_process'], true);
}

/** Texto do cancelamento (cliente e admin): quem, motivo, observação. */
function pedido_cancelamento_texto(array $p): string
{
    $por = (string) ($p['cancelado_por'] ?? '');
    if ($por === 'loja') {
        $t = 'Cancelado pela loja' . (!empty($p['cancelamento_motivo']) ? ': ' . $p['cancelamento_motivo'] : '') . '.';
        return $t . (!empty($p['cancelamento_obs']) ? ' ' . rtrim($p['cancelamento_obs'], '. ') . '.' : '');
    }
    if ($por === 'cliente') {
        return 'Cancelado a seu pedido.' . (!empty($p['cancelamento_obs']) ? ' ' . $p['cancelamento_obs'] : '');
    }
    if ($por === 'sistema' || ($p['pagamento_status'] ?? '') === 'expirado') {
        return 'Cancelado: o pagamento não foi feito em ' . PAGAMENTO_PRAZO_HORAS . ' h.';
    }
    return 'Pedido cancelado.';
}

/** "Reembolso de R$ X enviado em dd/mm/aaaa", ou ''. */
function pedido_reembolso_texto(array $p): string
{
    if (($p['pagamento_status'] ?? '') !== 'estornado' && !in_array((string) ($p['estorno_status'] ?? ''), ['aprovado', 'approved'], true)) {
        return '';
    }
    $valor = (int) ($p['estorno_valor_centavos'] ?? 0) ?: (int) $p['total_centavos'];
    $quando = !empty($p['estorno_em']) ? ' em ' . date('d/m/Y', strtotime($p['estorno_em'])) : '';
    return 'Reembolso de ' . money($valor) . ' enviado' . $quando . '.';
}

/** Explica em português um erro de estorno do Mercado Pago (mantém o original entre parênteses). */
function pedido_estorno_erro_texto(string $erro): string
{
    $conhecidos = [
        'live credentials'  => 'As credenciais do Mercado Pago no servidor não podem estornar este pagamento '
                             . '(credenciais de produção não ativadas na aplicação, ou pagamento de teste com credenciais da conta real).',
        'insufficient'      => 'Saldo insuficiente na conta do Mercado Pago para devolver o valor.',
        'not found'         => 'O Mercado Pago não encontrou este pagamento com as credenciais do servidor.',
        'already refunded'  => 'Este pagamento já foi estornado no Mercado Pago.',
        'invalid status'    => 'O pagamento não está num estado que permite estorno.',
    ];
    foreach ($conhecidos as $chave => $texto) {
        if (stripos($erro, $chave) !== false) {
            return $texto . ' (' . $erro . ')';
        }
    }
    return $erro;
}

/** Último erro de estorno gravado no histórico do pedido, ou ''. */
function pedido_ultimo_erro_estorno(int $id): string
{
    try {
        $st = db()->prepare("SELECT observacao FROM pedido_historico
                              WHERE order_id = ? AND observacao LIKE 'Estorno falhou:%' ORDER BY id DESC LIMIT 1");
        $st->execute([$id]);
        return trim(substr((string) $st->fetchColumn(), strlen('Estorno falhou:')));
    } catch (PDOException $e) {
        return '';
    }
}

/**
 * Estorna (total) o pagamento aprovado de um pedido. Se falhar, grava "falhou" e o
 * alerta do admin continua. Devolve ['ok' => bool, 'mensagem' => string].
 */
function pedido_estornar(int $id, ?int $usuario): array
{
    $st = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p || ($p['pagamento_status'] ?? '') !== 'aprovado') {
        return ['ok' => false, 'mensagem' => 'Este pedido não tem pagamento aprovado para estornar.'];
    }
    if (in_array((string) $p['estorno_status'], ['aprovado', 'approved', 'in_process'], true)) {
        return ['ok' => true, 'mensagem' => 'O estorno deste pedido já foi feito.'];
    }
    $falhou = function (string $erro) use ($id, $p, $usuario): array {
        db()->prepare('UPDATE orders SET estorno_status = "falhou" WHERE id = ?')->execute([$id]);
        pedido_historico_gravar($id, $p['status'], $p['status'], 'admin', $usuario, 'Estorno falhou: ' . $erro);
        return ['ok' => false, 'mensagem' => 'O Mercado Pago não fez o estorno: ' . pedido_estorno_erro_texto($erro)
            . ' Estorne pelo painel do Mercado Pago em Atividade → venda → Devolver dinheiro.'];
    };

    // Antes de estornar: o pagamento é desta conta e ainda está aprovado?
    $c = mp_conferir_estorno((string) ($p['mp_payment_id'] ?? ''));
    if ($c['estornado']) {
        // Já foi devolvido (ex.: pelo painel do Mercado Pago): só atualiza o pedido.
        $valor = $c['valor_centavos'] ?? (int) $p['total_centavos'];
        db()->prepare('UPDATE orders SET estorno_status = "aprovado", estorno_valor_centavos = ?,
                              estorno_em = COALESCE(estorno_em, NOW()), pagamento_status = "estornado" WHERE id = ?')
            ->execute([$valor, $id]);
        pedido_historico_gravar($id, $p['status'], $p['status'], 'admin', $usuario,
            'Estorno de ' . money($valor) . ' já constava no Mercado Pago');
        return ['ok' => true, 'mensagem' => 'Este pagamento já estava estornado no Mercado Pago. Pedido atualizado.'];
    }
    if (!$c['ok']) {
        return $falhou((string) $c['erro']);
    }

    // Chave de idempotência nova a cada tentativa: depois de uma falha, o Mercado Pago
    // devolveria a mesma resposta guardada se a chave se repetisse.
    try {
        db()->prepare('UPDATE orders SET estorno_tentativa = LAST_INSERT_ID(estorno_tentativa + 1) WHERE id = ?')->execute([$id]);
        $chave = 'estorno-' . $id . '-' . (int) db()->lastInsertId();
    } catch (PDOException $e) {
        $chave = 'estorno-' . $id . '-' . bin2hex(random_bytes(6));   // sem a migração da tentativa
    }

    $r = mp_estornar($p, $chave);
    if (!$r['ok']) {
        // Quem pagou ajuda a achar a causa (ex.: comprador real numa venda de vendedor de teste).
        $pagador = $c['pagador'] !== '' ? ' · comprador no MP: ' . $c['pagador'] : '';
        return $falhou(mb_substr((string) $r['erro'] . $pagador, 0, 280));
    }
    $aprovado = in_array($r['status'], ['approved', 'aprovado'], true);
    db()->prepare(
        'UPDATE orders SET estorno_id = ?, estorno_status = ?, estorno_valor_centavos = ?, estorno_em = NOW(),
                pagamento_status = IF(?, "estornado", pagamento_status)
          WHERE id = ?'
    )->execute([$r['id'], $aprovado ? 'aprovado' : $r['status'], $r['valor_centavos'] ?? (int) $p['total_centavos'], $aprovado ? 1 : 0, $id]);
    pedido_historico_gravar($id, $p['status'], $p['status'], 'admin', $usuario,
        'Estorno de ' . money($r['valor_centavos'] ?? (int) $p['total_centavos']) . ' enviado (nº ' . $r['id'] . ')');
    return ['ok' => true, 'mensagem' => 'Estorno de ' . money($r['valor_centavos'] ?? (int) $p['total_centavos'])
        . ' enviado. A cliente recebe conforme o meio de pagamento (no cartão, em até 2 faturas).'];
}

/**
 * Cancela pelo admin, com motivo. Se $estornar e o pagamento estiver aprovado,
 * estorna; se houver pagamento pendente (Pix/cartão em análise), cancela no Mercado Pago.
 * Devolve ['ok' => bool, 'conflito' => bool, 'mensagem' => string].
 */
function pedido_cancelar_loja(int $id, string $de, string $motivo, string $obs, bool $estornar, ?int $usuario): array
{
    if (!in_array($motivo, CANCELAMENTO_MOTIVOS, true)) {
        return ['ok' => false, 'conflito' => false, 'mensagem' => 'Escolha o motivo do cancelamento.'];
    }
    if (in_array($de, ['cancelado', 'finalizado'], true)) {
        return ['ok' => false, 'conflito' => false, 'mensagem' => 'Este pedido não pode mais ser cancelado.'];
    }
    $obs = mb_substr(trim($obs), 0, 300);
    $up = db()->prepare(
        'UPDATE orders SET status = "cancelado", cancelado_por = "loja", cancelamento_motivo = ?,
                cancelamento_obs = ?, cancelado_em = NOW()
          WHERE id = ? AND status = ?'
    );
    $up->execute([$motivo, $obs !== '' ? $obs : null, $id, $de]);
    if ($up->rowCount() === 0) {
        return ['ok' => false, 'conflito' => true, 'mensagem' => 'Este pedido foi alterado em outra tela.'];
    }
    pedido_historico_gravar($id, $de, 'cancelado', 'admin', $usuario, $motivo . ($obs !== '' ? ' — ' . $obs : ''));

    $st = db()->prepare('SELECT pagamento_status, mp_payment_id FROM orders WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    $msg = 'Pedido #' . $id . ' cancelado.';
    if (($p['pagamento_status'] ?? '') === 'aprovado') {
        if ($estornar) {
            $r = pedido_estornar($id, $usuario);
            return ['ok' => true, 'conflito' => false, 'mensagem' => $msg . ' ' . $r['mensagem'], 'estorno_ok' => $r['ok']];
        }
        return ['ok' => true, 'conflito' => false, 'mensagem' => $msg . ' O pagamento continua aprovado: estorne quando decidir.'];
    }
    if (($p['pagamento_status'] ?? '') === 'em_analise' && !empty($p['mp_payment_id']) && mp_ativo()) {
        mp_cancelar_pagamento((string) $p['mp_payment_id']);   // Pix/cartão pendente: não deixa ser pago depois
    }
    return ['ok' => true, 'conflito' => false, 'mensagem' => $msg];
}
