<?php

/**
 * E-mails transacionais (confirmação de pedido e mudança de status).
 * Usa a função mail() do PHP (hospedagem compartilhada, sem dependências).
 * O envio é "best-effort": nunca lança/quebra o fluxo do pedido se falhar.
 *
 * Configuráveis em settings:
 *   email_remetente -> endereço "De:" (ex.: no-reply@dolivier.com.br)
 *   email_loja      -> recebe aviso de novo pedido (vazio = não notifica a loja)
 */

/** Envia um e-mail HTML. Retorna true/false; nunca lança exceção. */
function enviar_email(string $para, string $assunto, string $html): bool
{
    $para = trim($para);
    if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $de = trim((string) cfg('email_remetente', ''));
    if ($de === '') {
        $host = parse_url((string) ($GLOBALS['config']['base_url'] ?? ''), PHP_URL_HOST)
            ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $de = 'no-reply@' . preg_replace('/^www\./', '', $host);
    }
    $nome_loja = (string) cfg('site_nome', 'Loja');

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: =?UTF-8?B?' . base64_encode($nome_loja) . '?= <' . $de . '>',
        'Reply-To: ' . $de,
    ];
    $assunto_enc = '=?UTF-8?B?' . base64_encode($assunto) . '?=';

    try {
        // 5º parâmetro: envelope sender (-f). Muitas hospedagens compartilhadas
        // (HostGator) só entregam quando o Return-Path é um e-mail real do domínio.
        $ok = @mail($para, $assunto_enc, $html, implode("\r\n", $headers), '-f' . $de);
        if (!$ok) {
            error_log('[email] mail() retornou false ao enviar para ' . $para . ' (De: ' . $de . ')');
        }
        return $ok;
    } catch (\Throwable $e) {
        error_log('[email] exceção ao enviar para ' . $para . ': ' . $e->getMessage());
        return false;
    }
}

/** Envelopa o conteúdo num HTML simples nas cores da marca (estilos inline). */
function _email_layout(string $titulo, string $conteudo_html): string
{
    $nome = e((string) cfg('site_nome', 'Loja'));
    return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;background:#F6EEDD;font-family:Arial,Helvetica,sans-serif;color:#4A3320;">'
        . '<div style="max-width:560px;margin:0 auto;padding:24px;">'
        . '<div style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.08);">'
        . '<div style="background:#6B4A2C;color:#fff;padding:16px 20px;font-size:18px;font-weight:bold;">' . $nome . '</div>'
        . '<div style="padding:20px;line-height:1.6;font-size:15px;">'
        . '<h1 style="color:#6B4A2C;font-size:20px;margin:0 0 12px;">' . e($titulo) . '</h1>'
        . $conteudo_html
        . '</div></div>'
        . '<p style="text-align:center;color:#8a7256;font-size:12px;margin:16px 0 0;">'
        . 'Este é um e-mail automático — por favor não responda.</p>'
        . '</div></body></html>';
}

/** Carrega o pedido + cliente + itens (ou null). */
function _email_pedido(int $id): ?array
{
    $st = db()->prepare(
        'SELECT o.*, u.nome AS cliente, u.email AS cliente_email
           FROM orders o LEFT JOIN users u ON u.id = o.user_id
          WHERE o.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) {
        return null;
    }
    $st = db()->prepare('SELECT nome, preco_centavos, quantidade FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $st->execute([$id]);
    $o['itens'] = $st->fetchAll();
    return $o;
}

/** Rótulos dos status (iguais aos do site/admin). */
function _email_status_labels(): array
{
    return [
        'realizado'  => 'Pedido realizado',
        'producao'   => 'Em produção',
        'pronto'     => 'Pronto para entrega',
        'finalizado' => 'Finalizado',
    ];
}

/** Bloco HTML com o resumo do pedido (itens + totais + entrega + link). */
function _email_resumo_pedido(array $o): string
{
    $linhas = '';
    foreach (($o['itens'] ?? []) as $it) {
        $sub = (int) $it['preco_centavos'] * (int) $it['quantidade'];
        $linhas .= '<tr>'
            . '<td style="padding:6px 0;">' . e($it['nome']) . '</td>'
            . '<td style="padding:6px 0;text-align:center;">' . (int) $it['quantidade'] . '×</td>'
            . '<td style="padding:6px 0;text-align:right;">' . e(money($sub)) . '</td>'
            . '</tr>';
    }
    $frete = (int) $o['frete_centavos'] > 0 ? e(money((int) $o['frete_centavos'])) : 'Grátis';
    $entrega = $o['entrega'] === 'motoboy' ? 'Entrega por motoboy' : 'Retirada no local';

    $html = '<table style="width:100%;border-collapse:collapse;font-size:14px;">' . $linhas . '</table>'
        . '<hr style="border:none;border-top:1px solid #eee;margin:12px 0;">'
        . '<p style="margin:4px 0;">Subtotal: ' . e(money((int) $o['subtotal_centavos'])) . '<br>'
        . 'Frete: ' . $frete . '<br>'
        . '<strong>Total: ' . e(money((int) $o['total_centavos'])) . '</strong></p>'
        . '<p style="margin:8px 0;"><strong>Entrega:</strong> ' . e($entrega);
    if (!empty($o['endereco_entrega'])) {
        $html .= '<br>' . e($o['endereco_entrega']);
    }
    $html .= '</p>';

    $link = url('pedido/' . (int) $o['id']);
    $html .= '<p style="margin:16px 0 0;">'
        . '<a href="' . e($link) . '" style="background:#BC5B38;color:#fff;text-decoration:none;'
        . 'padding:10px 18px;border-radius:8px;display:inline-block;">Ver o pedido</a></p>';
    return $html;
}

/** Novo pedido: avisa o cliente (e a loja, se configurada). */
function email_novo_pedido(int $order_id): void
{
    $o = _email_pedido($order_id);
    if (!$o) {
        return;
    }
    $nome_loja = (string) cfg('site_nome', 'Loja');

    if (!empty($o['cliente_email'])) {
        $corpo = '<p>Olá, ' . e($o['cliente'] ?: 'tudo bem') . '!</p>'
            . '<p>Recebemos o seu pedido <strong>#' . (int) $o['id'] . '</strong>. '
            . 'O pagamento está pendente — em breve combinamos os próximos passos.</p>'
            . _email_resumo_pedido($o);
        enviar_email(
            $o['cliente_email'],
            'Pedido #' . (int) $o['id'] . ' recebido — ' . $nome_loja,
            _email_layout('Recebemos o seu pedido!', $corpo)
        );
    }

    $loja = trim((string) cfg('email_loja', ''));
    if ($loja !== '') {
        $corpo = '<p>Um novo pedido <strong>#' . (int) $o['id'] . '</strong> foi realizado por '
            . e($o['cliente'] ?: ($o['contato_nome'] ?? 'cliente')) . '.</p>'
            . _email_resumo_pedido($o);
        enviar_email($loja, 'Novo pedido #' . (int) $o['id'], _email_layout('Novo pedido', $corpo));
    }
}

/** Mudança de status: avisa o cliente. */
function email_status_pedido(int $order_id): void
{
    $o = _email_pedido($order_id);
    if (!$o || empty($o['cliente_email'])) {
        return;
    }
    $labels = _email_status_labels();
    $label  = $labels[$o['status']] ?? $o['status'];

    $corpo = '<p>Olá, ' . e($o['cliente'] ?: 'tudo bem') . '!</p>'
        . '<p>O seu pedido <strong>#' . (int) $o['id'] . '</strong> foi atualizado para: '
        . '<strong>' . e($label) . '</strong>.</p>'
        . _email_resumo_pedido($o);

    enviar_email(
        $o['cliente_email'],
        'Pedido #' . (int) $o['id'] . ': ' . $label,
        _email_layout('Atualização do seu pedido', $corpo)
    );
}
