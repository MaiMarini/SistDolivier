<?php
/**
 * Pagamento dos pedidos (Fase 3) — regras da loja em cima do Mercado Pago.
 *
 * Ciclo do pedido com pagamento online:
 *   aguardando_pagamento --(pago)--> realizado --> producao --> pronto --> finalizado
 *            \--(24h sem pagar)--> cancelado   (se o pagamento chegar depois, volta a "realizado")
 *
 * orders.pagamento_status: pendente | em_analise | aprovado | recusado |
 *                          cancelado | estornado | expirado | divergente
 */

const PAGAMENTO_PRAZO_HORAS = 24;

/** Rótulos do status do pedido (admin e cliente). */
function pedido_status_rotulos(): array
{
    return [
        'aguardando_pagamento' => 'Aguardando pagamento',
        'realizado'  => 'Pedido realizado',
        'producao'   => 'Em produção',
        'pronto'     => 'Pronto p/ entrega',
        'finalizado' => 'Finalizado',
        'cancelado'  => 'Cancelado',
    ];
}

/** Rótulos do status do pagamento. */
function pagamento_status_rotulo(?string $s): string
{
    $r = [
        'pendente'   => 'Pendente',
        'em_analise' => 'Em análise',
        'aprovado'   => 'Aprovado',
        'recusado'   => 'Recusado',
        'cancelado'  => 'Cancelado',
        'estornado'  => 'Estornado',
        'expirado'   => 'Expirado',
        'divergente' => 'Valor divergente',
    ];
    return $r[$s ?? ''] ?? 'Pendente';
}

/** Rótulo da forma de pagamento gravada em orders.pagamento. */
function pagamento_forma_rotulo(?string $f): string
{
    $r = ['pix' => 'Pix', 'credito' => 'Cartão de crédito', 'debito' => 'Cartão de débito'];
    return $r[$f ?? ''] ?? ($f ? ucfirst($f) : '—');
}

/** Segundos que faltam para o prazo de pagamento (relógio do banco). Negativo = vencido. */
function pagamento_segundos_restantes(int $pedido_id): int
{
    $st = db()->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(criado_em, INTERVAL ' . PAGAMENTO_PRAZO_HORAS . ' HOUR))
           FROM orders WHERE id = ?'
    );
    $st->execute([$pedido_id]);
    return (int) $st->fetchColumn();
}

/** Prazo de pagamento no horário de Brasília. */
function pagamento_prazo(int $pedido_id): DateTimeImmutable
{
    return (new DateTimeImmutable('@' . (time() + pagamento_segundos_restantes($pedido_id))))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'));
}

/**
 * Cria (ou recria) o link de pagamento de um pedido aguardando pagamento.
 * Devolve a URL do Mercado Pago, ou null (prazo vencido, pedido já pago, erro na API).
 */
function pagamento_iniciar(int $pedido_id): ?string
{
    if (!mp_ativo()) {
        return null;
    }
    $st = db()->prepare(
        'SELECT o.id, o.status, o.frete_centavos, o.total_centavos, u.nome, u.email
           FROM orders o LEFT JOIN users u ON u.id = o.user_id
          WHERE o.id = ? LIMIT 1'
    );
    $st->execute([$pedido_id]);
    $p = $st->fetch();
    if (!$p || $p['status'] !== 'aguardando_pagamento') {
        return null;
    }
    if (pagamento_segundos_restantes($pedido_id) <= 60) {
        pagamento_cancelar_expirados();
        return null;
    }

    $st = db()->prepare('SELECT nome, preco_centavos, quantidade FROM order_items WHERE order_id = ? ORDER BY id');
    $st->execute([$pedido_id]);
    $itens = array_map(fn ($i) => [
        'titulo' => $i['nome'], 'qtd' => (int) $i['quantidade'], 'preco_centavos' => (int) $i['preco_centavos'],
    ], $st->fetchAll());

    $pref = mp_criar_preferencia([
        'itens'          => $itens,
        'frete_centavos' => (int) $p['frete_centavos'],
        'pedido_id'      => $pedido_id,
        'nome'           => (string) $p['nome'],
        'email'          => (string) $p['email'],
        'parcelas'       => parcelamento_parcelas((int) $p['total_centavos']),
        'expira_em'      => pagamento_prazo($pedido_id),
    ]);
    if ($pref === null) {
        return null;
    }
    db()->prepare('UPDATE orders SET mp_preference_id = ? WHERE id = ?')->execute([$pref['id'], $pedido_id]);
    return $pref['init_point'];
}

/**
 * Aplica ao pedido um pagamento lido na API do Mercado Pago. Idempotente: o
 * webhook e a página de retorno podem chamar várias vezes para o mesmo pagamento.
 * Devolve o id do pedido afetado, ou 0.
 */
function pagamento_aplicar(array $pg): int
{
    $pedido_id = (int) ($pg['external_reference'] ?? 0);
    $pay_id    = (string) ($pg['id'] ?? '');
    if ($pedido_id <= 0 || $pay_id === '') {
        return 0;
    }

    $mapa = [
        'approved' => 'aprovado', 'authorized' => 'em_analise', 'in_process' => 'em_analise',
        'in_mediation' => 'em_analise', 'pending' => 'pendente', 'rejected' => 'recusado',
        'cancelled' => 'cancelado', 'refunded' => 'estornado', 'charged_back' => 'estornado',
    ];
    $novo  = $mapa[$pg['status'] ?? ''] ?? 'pendente';
    $tipo  = (string) ($pg['payment_type_id'] ?? '');
    $forma = ($pg['payment_method_id'] ?? '') === 'pix' ? 'pix'
           : ($tipo === 'credit_card' ? 'credito' : ($tipo === 'debit_card' ? 'debito' : $tipo));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT status, total_centavos, pagamento_status, mp_payment_id FROM orders WHERE id = ? FOR UPDATE');
        $st->execute([$pedido_id]);
        $pedido = $st->fetch();
        if (!$pedido) {
            $pdo->rollBack();
            return 0;
        }

        // Já pago por OUTRO pagamento (ex.: tentativa recusada antes): não volta atrás.
        if ($pedido['pagamento_status'] === 'aprovado' && $pedido['mp_payment_id'] !== $pay_id && $novo !== 'aprovado') {
            $pdo->commit();
            return $pedido_id;
        }

        // Aprovado com valor diferente do pedido: não libera a produção, sinaliza no admin.
        if ($novo === 'aprovado') {
            $pago = (int) round(((float) ($pg['transaction_amount'] ?? 0)) * 100);
            if (abs($pago - (int) $pedido['total_centavos']) > 1) {
                $novo = 'divergente';
                error_log('pagamento: pedido ' . $pedido_id . ' pago com valor diferente do total');
            }
        }

        $pdo->prepare(
            'UPDATE orders SET pagamento_status = ?, pagamento = ?, mp_payment_id = ?,
                    pago_em = IF(? = "aprovado" AND pago_em IS NULL, NOW(), pago_em)
              WHERE id = ?'
        )->execute([$novo, $forma !== '' ? $forma : null, $pay_id, $novo, $pedido_id]);

        // Pago: o pedido entra na fila de produção (também se tinha sido cancelado por prazo).
        if ($novo === 'aprovado' && in_array($pedido['status'], ['aguardando_pagamento', 'cancelado'], true)) {
            $pdo->prepare('UPDATE orders SET status = "realizado" WHERE id = ?')->execute([$pedido_id]);
            $pdo->prepare('INSERT INTO order_status_history (order_id, status) VALUES (?, "realizado")')
                ->execute([$pedido_id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $pedido_id;
}

/**
 * Cancela pedidos que passaram do prazo sem pagamento. Chamado ao abrir as telas
 * de pedidos (admin e cliente) — não precisa de cron. Devolve quantos cancelou.
 */
function pagamento_cancelar_expirados(): int
{
    static $feito = false;   // uma vez por requisição basta
    if ($feito) {
        return 0;
    }
    $feito = true;

    $ids = db()->query(
        'SELECT id FROM orders
          WHERE status = "aguardando_pagamento"
            AND criado_em < NOW() - INTERVAL ' . PAGAMENTO_PRAZO_HORAS . ' HOUR'
    )->fetchAll(PDO::FETCH_COLUMN);

    $n = 0;
    foreach ($ids as $id) {
        $up = db()->prepare(
            'UPDATE orders SET status = "cancelado",
                    pagamento_status = IF(pagamento_status IN ("aprovado", "em_analise"), pagamento_status, "expirado")
              WHERE id = ? AND status = "aguardando_pagamento"'
        );
        $up->execute([(int) $id]);
        if ($up->rowCount() > 0) {
            db()->prepare('INSERT INTO order_status_history (order_id, status) VALUES (?, "cancelado")')
                ->execute([(int) $id]);
            $n++;
        }
    }
    return $n;
}
