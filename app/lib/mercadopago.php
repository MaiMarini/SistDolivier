<?php
/**
 * Mercado Pago — Checkout Pro (cliente HTTP mínimo, via cURL, sem SDK).
 *
 * Credenciais no .env do servidor:
 *   MP_ACCESS_TOKEN    -> liga o pagamento online (vazio = checkout como antes)
 *   MP_WEBHOOK_SECRET  -> assinatura secreta do webhook (Suas integrações › Webhooks)
 *
 * Nunca confiamos no que chega pelo navegador ou pelo corpo do webhook: o status
 * do pagamento é sempre lido de novo na API (mp_buscar_pagamento).
 */

const MP_API = 'https://api.mercadopago.com';

/** Pagamento online ligado? (há access token configurado) */
function mp_ativo(): bool
{
    return mp_token() !== '';
}

function mp_token(): string
{
    return trim((string) env('MP_ACCESS_TOKEN', ''));
}

/**
 * Chamada à API. Devolve ['http' => int, 'json' => ?array]; http 0 = falha de rede.
 * $idempotencia evita criar duas preferências iguais se a chamada for repetida.
 */
function mp_requisicao(string $metodo, string $caminho, ?array $corpo = null, ?string $idempotencia = null): array
{
    $cabecalhos = [
        'Authorization: Bearer ' . mp_token(),
        'Content-Type: application/json',
    ];
    if ($idempotencia !== null) {
        $cabecalhos[] = 'X-Idempotency-Key: ' . $idempotencia;
    }
    $ch = curl_init(MP_API . $caminho);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $cabecalhos,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = is_string($resp) ? json_decode($resp, true) : null;
    if ($http < 200 || $http >= 300) {
        // Sem token nem dados do cliente no log: só o código e a mensagem da API.
        error_log('mercadopago: ' . $metodo . ' ' . $caminho . ' -> HTTP ' . $http
            . (is_array($json) && isset($json['message']) ? ' (' . $json['message'] . ')' : ''));
    }
    return ['http' => $http, 'json' => is_array($json) ? $json : null];
}

/**
 * Cria a preferência de pagamento (Checkout Pro).
 * $dados: itens [{titulo, qtd, preco_centavos}], frete_centavos, pedido_id,
 *         email, nome, parcelas, expira_em (DateTimeImmutable).
 * Devolve ['id' => ..., 'init_point' => ...] ou null.
 */
function mp_criar_preferencia(array $dados): ?array
{
    $itens = [];
    foreach ($dados['itens'] as $i => $it) {
        $itens[] = [
            'id'          => 'item-' . ($i + 1),
            'title'       => mb_substr($it['titulo'], 0, 250),
            'quantity'    => (int) $it['qtd'],
            'unit_price'  => round($it['preco_centavos'] / 100, 2),
            'currency_id' => 'BRL',
        ];
    }
    if ((int) $dados['frete_centavos'] > 0) {
        $itens[] = [
            'id' => 'frete', 'title' => 'Entrega por motoboy', 'quantity' => 1,
            'unit_price' => round($dados['frete_centavos'] / 100, 2), 'currency_id' => 'BRL',
        ];
    }

    $pedido_id = (string) $dados['pedido_id'];
    $expira    = $dados['expira_em']->format('Y-m-d\TH:i:s.vP');
    $agora     = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:s.vP');

    $corpo = [
        'items'              => $itens,
        'payer'              => array_filter(['name' => $dados['nome'] ?? '', 'email' => $dados['email'] ?? '']),
        'external_reference' => $pedido_id,
        'statement_descriptor' => 'DOLIVIER',
        // Pix e cartão: tira boleto (ticket) e lotérica (atm).
        'payment_methods'    => [
            'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']],
            'installments'           => max(1, (int) $dados['parcelas']),
        ],
        // Prazo do pedido (24h): depois disso o link não aceita pagamento.
        'expires'               => true,
        'expiration_date_from'  => $agora,
        'expiration_date_to'    => $expira,
        'date_of_expiration'    => $expira,   // vale para o Pix
        'back_urls'          => [
            'success' => url('pagamento/retorno'),
            'pending' => url('pagamento/retorno'),
            'failure' => url('pagamento/retorno'),
        ],
    ];
    // O Mercado Pago só aceita retorno automático e webhook em endereço público https.
    if (stripos(url(), 'https://') === 0) {
        $corpo['auto_return']      = 'approved';
        $corpo['notification_url'] = url('pagamento/webhook');
    }

    $r = mp_requisicao('POST', '/checkout/preferences', $corpo, 'pref-' . $pedido_id . '-' . bin2hex(random_bytes(6)));
    $j = $r['json'];
    if ($r['http'] < 200 || $r['http'] >= 300 || empty($j['id']) || empty($j['init_point'])) {
        return null;
    }
    return ['id' => (string) $j['id'], 'init_point' => (string) $j['init_point']];
}

/** Lê um pagamento na API (fonte da verdade). null em falha. */
function mp_buscar_pagamento(string $payment_id): ?array
{
    if (!preg_match('/^\d+$/', $payment_id)) {
        return null;
    }
    $r = mp_requisicao('GET', '/v1/payments/' . $payment_id);
    return ($r['http'] === 200 && is_array($r['json'])) ? $r['json'] : null;
}

/**
 * Confere o cabeçalho x-signature do webhook ("ts=...,v1=...").
 * Manifesto: "id:{data.id};request-id:{x-request-id};ts:{ts};" com HMAC-SHA256.
 */
function mp_assinatura_valida(string $x_signature, string $x_request_id, string $data_id, string $segredo): bool
{
    $ts = $v1 = '';
    foreach (explode(',', $x_signature) as $parte) {
        $kv = explode('=', trim($parte), 2);
        if (count($kv) === 2) {
            if ($kv[0] === 'ts') { $ts = $kv[1]; }
            if ($kv[0] === 'v1') { $v1 = $kv[1]; }
        }
    }
    if ($ts === '' || $v1 === '' || $segredo === '') {
        return false;
    }
    // O Mercado Pago usa o id em minúsculas quando ele é alfanumérico.
    $id = ctype_alnum($data_id) ? strtolower($data_id) : $data_id;
    $manifesto = 'id:' . $id . ';'
        . ($x_request_id !== '' ? 'request-id:' . $x_request_id . ';' : '')
        . 'ts:' . $ts . ';';
    return hash_equals(hash_hmac('sha256', $manifesto, $segredo), $v1);
}
