<?php
/**
 * Pagamento online (Mercado Pago, Checkout Pro).
 *   GET  /pagamento/retorno  -> volta do Mercado Pago (aprovado, pendente ou recusado)
 *   POST /pagamento/pagar    -> "Pagar agora" na página do pedido (gera um novo link)
 *   POST /pagamento/webhook  -> aviso do Mercado Pago (servidor a servidor)
 *
 * Em todos os casos o status é lido de novo na API: nada que chega pelo
 * navegador ou pelo corpo do aviso é usado como verdade.
 */
$acao = $params[0] ?? '';

// -----------------------------------------------------------------------------
// Webhook: sem login nem CSRF (quem chama é o Mercado Pago); validado pela
// assinatura x-signature e pela consulta do pagamento na API.
// -----------------------------------------------------------------------------
if ($acao === 'webhook') {
    $responder = function (int $http, string $texto = 'ok'): void {
        http_response_code($http);
        header('Content-Type: text/plain; charset=utf-8');
        echo $texto;
        exit;
    };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !mp_ativo()) {
        $responder(200, 'ignorado');
    }

    $corpo   = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $tipo    = (string) ($_GET['type'] ?? $corpo['type'] ?? '');
    // "data.id" na URL chega ao PHP como "data_id".
    $data_id = (string) ($_GET['data_id'] ?? ($corpo['data']['id'] ?? ''));
    if ($tipo !== 'payment' || $data_id === '') {
        $responder(200, 'ignorado');   // outros avisos (ex.: merchant_order) não interessam
    }

    $segredo = trim((string) env('MP_WEBHOOK_SECRET', ''));
    if ($segredo !== '') {
        $ok = mp_assinatura_valida(
            (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''),
            (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''),
            $data_id,
            $segredo
        );
        if (!$ok) {
            error_log('pagamento webhook: assinatura inválida');
            $responder(401, 'assinatura inválida');
        }
    } else {
        // Sem segredo configurado ainda: segue (o status vem da API), mas avisa no log.
        error_log('pagamento webhook: MP_WEBHOOK_SECRET não configurado; assinatura não conferida');
    }

    $pg = mp_buscar_pagamento($data_id);
    if ($pg === null) {
        $responder(500, 'falha ao consultar o pagamento');   // o Mercado Pago tenta de novo
    }
    pagamento_aplicar($pg);
    $responder(200);
}

// -----------------------------------------------------------------------------
// Retorno do Mercado Pago para o navegador da cliente.
// -----------------------------------------------------------------------------
if ($acao === 'retorno') {
    $pedido_id = (int) ($_GET['external_reference'] ?? 0);
    $pay_id    = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');

    // Atualiza já, sem esperar o webhook (o status vem da API).
    if (mp_ativo() && $pay_id !== '' && $pay_id !== 'null') {
        $pg = mp_buscar_pagamento($pay_id);
        if ($pg !== null) {
            $pedido_id = pagamento_aplicar($pg) ?: $pedido_id;
        }
    }
    if ($pedido_id <= 0) {
        redirect('meus-pedidos');
    }

    $st = db()->prepare('SELECT pagamento_status FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$pedido_id]);
    $ps = (string) $st->fetchColumn();
    if ($ps === 'aprovado') {
        flash('sucesso', 'Pagamento aprovado! Seu pedido entrou na fila de produção.');
    } elseif (in_array($ps, ['pendente', 'em_analise'], true) && $pay_id !== '' && $pay_id !== 'null') {
        flash('sucesso', 'Recebemos seu pedido. O pagamento está sendo processado e avisaremos aqui quando for confirmado.');
    } else {
        flash('erro', 'O pagamento não foi concluído. Você pode tentar de novo pelo botão "Pagar agora".');
    }
    redirect('pedido/' . $pedido_id);
}

// -----------------------------------------------------------------------------
// "Pagar agora": novo link de pagamento para um pedido aguardando pagamento.
// -----------------------------------------------------------------------------
if ($acao === 'pagar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_login();
    $pedido_id = (int) ($_POST['pedido_id'] ?? 0);
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('pedido/' . $pedido_id);
    }
    $st = db()->prepare('SELECT user_id, status FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$pedido_id]);
    $p = $st->fetch();
    if (!$p || (int) $p['user_id'] !== (int) usuario_atual()['id']) {
        redirect('meus-pedidos');
    }
    if ($p['status'] !== 'aguardando_pagamento') {
        flash('erro', 'Este pedido não está aguardando pagamento.');
        redirect('pedido/' . $pedido_id);
    }
    $link = pagamento_iniciar($pedido_id);
    if ($link !== null) {
        redirect($link);
    }
    flash('erro', pagamento_segundos_restantes($pedido_id) <= 60
        ? 'O prazo para pagar este pedido terminou.'
        : 'Não conseguimos abrir o pagamento agora. Tente novamente em instantes.');
    redirect('pedido/' . $pedido_id);
}

redirect('meus-pedidos');
