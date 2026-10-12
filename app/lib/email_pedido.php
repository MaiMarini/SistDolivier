<?php
/**
 * E-mails automáticos do pedido (passo 4.2): "pedido recebido" e "pagamento aprovado".
 *
 *  - Cada um sai uma vez só por pedido: orders.email_recebido_em / email_pago_em são
 *    marcados antes do envio (o Mercado Pago repete os avisos). Se o envio falhar, a
 *    marca volta a vazio e o motivo fica no histórico do pedido.
 *  - pedido_email_depois() deixa o envio para depois da resposta ao navegador
 *    (o cliente não espera o servidor de e-mail). Um erro nunca afeta o pedido.
 *  - Sem a migração migracao_email_pedido.sql, não envia nada (e não quebra nada).
 */

const PEDIDO_EMAILS = [
    'recebido' => 'email_recebido_em',
    'pago'     => 'email_pago_em',
];

/** Agenda o envio para depois da resposta (uma vez por pedido e tipo nesta requisição). */
function pedido_email_depois(int $pedido_id, string $tipo): void
{
    static $agendados = [];
    $chave = $pedido_id . ':' . $tipo;
    if (isset($agendados[$chave]) || !isset(PEDIDO_EMAILS[$tipo])) {
        return;
    }
    $agendados[$chave] = true;
    register_shutdown_function(function () use ($pedido_id, $tipo): void {
        // Libera o navegador antes de falar com o servidor de e-mail, quando a hospedagem permite.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        try {
            pedido_email_enviar($pedido_id, $tipo);
        } catch (Throwable $e) {
            error_log('[email pedido] ' . $e->getMessage());
        }
    });
}

/**
 * Envia agora (se ainda não foi). Devolve 'enviado', 'ja_enviado', 'sem_email',
 * 'sem_migracao' ou 'falhou'.
 */
function pedido_email_enviar(int $pedido_id, string $tipo): string
{
    $coluna = PEDIDO_EMAILS[$tipo] ?? null;
    if ($coluna === null) {
        return 'falhou';
    }
    // Reserva o envio (só um processo consegue): evita e-mail em dobro.
    try {
        $st = db()->prepare("UPDATE orders SET $coluna = NOW() WHERE id = ? AND $coluna IS NULL");
        $st->execute([$pedido_id]);
    } catch (PDOException $e) {
        return 'sem_migracao';
    }
    if ($st->rowCount() !== 1) {
        return 'ja_enviado';
    }

    $p = _pedido_email_dados($pedido_id);
    if ($p === null || !filter_var((string) ($p['cliente_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return 'sem_email';
    }
    [$assunto, $html] = $tipo === 'pago' ? pedido_email_pago($p) : pedido_email_recebido($p);
    $r = email_enviar((string) $p['cliente_email'], $assunto, $html);

    $nome = $tipo === 'pago' ? '“Pagamento aprovado”' : '“Pedido recebido”';
    if (!$r['ok']) {
        db()->prepare("UPDATE orders SET $coluna = NULL WHERE id = ?")->execute([$pedido_id]);
        pedido_historico_gravar($pedido_id, $p['status'], $p['status'], 'sistema', null,
            'E-mail ' . $nome . ' não foi enviado: ' . mb_substr($r['erro'], 0, 200));
        return 'falhou';
    }
    pedido_historico_gravar($pedido_id, $p['status'], $p['status'], 'sistema', null,
        'E-mail ' . $nome . ' enviado para ' . $p['cliente_email']);
    return 'enviado';
}

/** Pedido + cliente + itens. */
function _pedido_email_dados(int $id): ?array
{
    $st = db()->prepare('SELECT o.*, u.nome AS cliente_nome, u.email AS cliente_email
                           FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    $st = db()->prepare('SELECT nome, preco_centavos, quantidade FROM order_items WHERE order_id = ? ORDER BY id');
    $st->execute([$id]);
    $p['itens'] = $st->fetchAll();
    return $p;
}

/** Primeiro nome do cliente (ou vazio). */
function _pedido_email_primeiro_nome(array $p): string
{
    $n = trim((string) ($p['cliente_nome'] ?? ''));
    return $n === '' ? '' : explode(' ', $n)[0];
}

/** Botão (link) no padrão da loja. */
function _pedido_email_botao(string $texto, string $link): string
{
    return '<p style="margin:20px 0 4px;"><a href="' . e($link) . '" style="background:#BC5B38;color:#ffffff;'
        . 'text-decoration:none;padding:12px 20px;border-radius:8px;display:inline-block;font-weight:bold;">'
        . e($texto) . '</a></p>';
}

/** Itens, frete, total, entrega e presente. */
function _pedido_email_resumo(array $p): string
{
    $td = 'padding:8px 0;border-bottom:1px solid #eee4d3;vertical-align:top;';
    $linhas = '';
    foreach ($p['itens'] as $it) {
        $q = (int) $it['quantidade'];
        $linhas .= '<tr><td style="' . $td . '">' . e($it['nome']) . '</td>'
            . '<td style="' . $td . 'text-align:center;white-space:nowrap;">' . $q . ' ×</td>'
            . '<td style="' . $td . 'text-align:right;white-space:nowrap;">' . e(money((int) $it['preco_centavos'] * $q)) . '</td></tr>';
    }
    $frete = (int) $p['frete_centavos'];
    $html = '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;">' . $linhas
        . '<tr><td colspan="2" style="padding:8px 0 2px;">Subtotal</td><td style="padding:8px 0 2px;text-align:right;">' . e(money((int) $p['subtotal_centavos'])) . '</td></tr>'
        . '<tr><td colspan="2" style="padding:2px 0;">Frete</td><td style="padding:2px 0;text-align:right;">' . ($frete > 0 ? e(money($frete)) : 'Grátis') . '</td></tr>'
        . '<tr><td colspan="2" style="padding:6px 0;font-weight:bold;">Total</td><td style="padding:6px 0;text-align:right;font-weight:bold;">' . e(money((int) $p['total_centavos'])) . '</td></tr>'
        . '</table>';

    $retirada = ($p['entrega'] ?? '') !== 'motoboy';
    $endereco = (string) ($p['endereco_entrega'] ?: ($retirada ? 'Retirada no local' : ''));
    $endereco = preg_replace('/\b(\d{5})-(\d{3})\b/', "$1\u{2011}$2", $endereco);   // CEP não quebra no hífen
    $html .= '<p style="margin:16px 0 4px;"><strong>' . ($retirada ? 'Retirada' : 'Entrega por motoboy') . '</strong></p>'
        . '<p style="margin:0;white-space:pre-line;">' . e($endereco) . '</p>';
    if (!empty($p['presente'])) {
        $html .= '<p style="margin:12px 0 0;"><strong>É um presente</strong> para ' . e((string) $p['presente_para']) . '.</p>';
    }
    return $html;
}

/** "Pedido recebido". Com pagamento online: lembra de pagar; sem: combina pelo WhatsApp. */
function pedido_email_recebido(array $p): array
{
    $id = (int) $p['id'];
    $nome = _pedido_email_primeiro_nome($p);
    $aguarda = ($p['status'] ?? '') === 'aguardando_pagamento' && ($p['pagamento_status'] ?? '') !== 'aprovado';
    $corpo = '<p>Olá' . ($nome !== '' ? ', ' . e($nome) : '') . '!</p>'
        . '<p>Recebemos o seu pedido <strong>#' . $id . '</strong>. '
        . ($aguarda
            ? 'Assim que o pagamento for confirmado, ele entra na produção e avisamos você por aqui.'
            : 'Em breve entramos em contato pelo WhatsApp para combinar o pagamento e a entrega.')
        . '</p>'
        . _pedido_email_resumo($p)
        . _pedido_email_botao($aguarda ? 'Ver o pedido e pagar' : 'Ver o pedido', url(pedido_caminho($id)));
    return ['Recebemos o seu pedido #' . $id . ' — ' . cfg('site_nome', 'Loja'), email_layout('Recebemos o seu pedido!', $corpo)];
}

/** "Pagamento aprovado". */
function pedido_email_pago(array $p): array
{
    $id = (int) $p['id'];
    $nome = _pedido_email_primeiro_nome($p);
    $corpo = '<p>Olá' . ($nome !== '' ? ', ' . e($nome) : '') . '!</p>'
        . '<p>O pagamento do pedido <strong>#' . $id . '</strong> foi aprovado. Ele já entrou na nossa fila de produção.</p>'
        . '<p>Você pode acompanhar cada etapa em “Meus pedidos”.</p>'
        . _pedido_email_resumo($p)
        . _pedido_email_botao('Acompanhar o pedido', url(pedido_caminho($id)));
    return ['Pagamento aprovado — pedido #' . $id, email_layout('Pagamento aprovado', $corpo)];
}
