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

/**
 * Pode gerar um NOVO pagamento? Não, se já há um aprovado, um em análise (cartão
 * em análise, Pix/boleto gerado e não pago) ou um com valor divergente.
 */
function pagamento_pode_pagar(array $p): bool
{
    return !in_array((string) ($p['pagamento_status'] ?? ''), ['aprovado', 'em_analise', 'divergente'], true);
}

/** Pix ou boleto gerado e ainda não pago (o "pending" do Mercado Pago). */
function pagamento_aguardando_pix_boleto(array $p): bool
{
    return ($p['pagamento_status'] ?? '') === 'em_analise'
        && in_array((string) ($p['pagamento_tipo'] ?? ''), ['bank_transfer', 'ticket'], true);
}

/**
 * Situação do pedido para a cliente — o MESMO texto na lista e no detalhe.
 * Devolve: rotulo, classe (st-*), aviso (faixa) e acao:
 *   'pagar'   -> "Pagar agora · R$ X"       'tentar' -> "Tentar novamente · R$ X"
 *   'ticket'  -> link do Pix/boleto já gerado (sem pagamento novo)
 *   null      -> sem botão
 */
function pedido_situacao(array $p): array
{
    $status = (string) $p['status'];
    $ps     = (string) ($p['pagamento_status'] ?? '');
    $motoboy = ($p['entrega'] ?? '') === 'motoboy';
    $s = ['rotulo' => '', 'classe' => 'st-analise', 'aviso' => '', 'acao' => null];

    if ($status === 'cancelado') {
        return ['rotulo' => 'Cancelado', 'classe' => 'st-cancelado', 'aviso' => '', 'acao' => null];
    }
    if ($status === 'aguardando_pagamento') {
        if (pagamento_aguardando_pix_boleto($p)) {
            $boleto = ($p['pagamento_tipo'] ?? '') === 'ticket';
            return [
                'rotulo' => $boleto ? 'Aguardando o pagamento do boleto' : 'Aguardando o pagamento do Pix',
                'classe' => 'st-pagar',
                'aviso'  => $boleto
                    ? 'O boleto foi gerado. O preparo começa depois que ele for compensado.'
                    : 'O Pix foi gerado. O preparo começa assim que o pagamento cair.',
                'acao'   => !empty($p['pagamento_ticket_url']) ? 'ticket' : null,
            ];
        }
        if ($ps === 'em_analise') {
            return ['rotulo' => 'Pagamento em análise', 'classe' => 'st-analise',
                    'aviso' => 'O Mercado Pago está analisando o pagamento. Avisaremos aqui quando for confirmado.', 'acao' => null];
        }
        if ($ps === 'divergente') {
            return ['rotulo' => 'Pagamento em análise', 'classe' => 'st-analise',
                    'aviso' => 'Estamos conferindo o pagamento. Se precisar, fale com a gente.', 'acao' => null];
        }
        if (in_array($ps, ['recusado', 'cancelado'], true)) {
            return ['rotulo' => 'Pagamento não aprovado', 'classe' => 'st-pagar',
                    'aviso' => 'O pagamento não foi aprovado.', 'acao' => mp_ativo() ? 'tentar' : null];
        }
        return ['rotulo' => 'Aguardando pagamento', 'classe' => 'st-pagar',
                'aviso' => 'O preparo começa depois do pagamento.', 'acao' => mp_ativo() ? 'pagar' : null];
    }
    switch ($status) {
        case 'realizado':
            $s['rotulo'] = $ps === 'aprovado' ? 'Pagamento aprovado' : 'Pedido recebido';
            $s['classe'] = $ps === 'aprovado' ? 'st-producao' : 'st-analise';
            break;
        case 'producao':
            $s['rotulo'] = 'Em produção';
            $s['classe'] = 'st-producao';
            break;
        case 'pronto':
            $s['rotulo'] = $motoboy ? 'Pronto para entrega' : 'Pronto para retirar';
            $s['classe'] = 'st-producao';
            break;
        case 'finalizado':
            $s['rotulo'] = $motoboy ? 'Entregue' : 'Retirado';
            $s['classe'] = 'st-finalizado';
            break;
    }
    return $s;
}

/**
 * Linha do tempo: Pedido recebido → Pagamento → Em produção → Pronto → Entregue.
 * $quando: datas por status vindas de order_status_history (status => 'Y-m-d H:i:s').
 * Cada passo: [rotulo, texto, estado] com estado done | now | wait | todo | cancel.
 */
function pedido_andamento(array $p, array $quando): array
{
    $fmt = fn ($d) => $d ? date('d/m, H:i', strtotime($d)) : '';
    $status = (string) $p['status'];
    $motoboy = ($p['entrega'] ?? '') === 'motoboy';

    if ($status === 'cancelado') {
        return [
            ['Pedido recebido', $fmt($p['criado_em']), 'done'],
            ['Cancelado', 'Sem pagamento em ' . PAGAMENTO_PRAZO_HORAS . ' h', 'cancel'],
        ];
    }

    // Pagamento confirmado: aprovado, ou pedido já em produção (pago combinando com a loja).
    $pago = ($p['pagamento_status'] ?? '') === 'aprovado'
        || in_array($status, ['producao', 'pronto', 'finalizado'], true);
    $ordem = ['producao' => 2, 'pronto' => 3, 'finalizado' => 5];
    $atual = $pago ? ($ordem[$status] ?? 2) : 1;   // 1 = Pagamento, 2 = Em produção...

    $passos = [
        ['Pedido recebido', $fmt($p['criado_em'])],
        ['Pagamento', ''],
        ['Em produção', $fmt($quando['producao'] ?? null)],
        [$motoboy ? 'Pronto para entrega' : 'Pronto para retirar', $fmt($quando['pronto'] ?? null)],
        [$motoboy ? 'Entregue' : 'Retirado', $fmt($quando['finalizado'] ?? null)],
    ];
    $out = [];
    foreach ($passos as $i => [$rotulo, $texto]) {
        if ($i < $atual) {
            $estado = 'done';
            if ($i === 1) {
                $texto = !empty($p['pago_em']) ? 'Aprovado em ' . $fmt($p['pago_em']) : 'Confirmado';
            }
        } elseif ($i === $atual) {
            $estado = $i === 1 ? 'wait' : 'now';
            if ($i === 1) {
                $sit   = pedido_situacao($p);
                $texto = $status === 'realizado' ? 'A combinar com a loja' : $sit['rotulo'];
            } elseif ($texto === '') {
                $texto = $status === 'realizado' ? 'A seguir' : 'Agora';
            }
        } else {
            $estado = 'todo';
            $texto  = '';
        }
        $out[] = [$rotulo, $texto, $estado];
    }
    return $out;
}

/**
 * Itens e datas do histórico de vários pedidos, em 2 consultas.
 * Devolve ['itens' => [id => [...]], 'quando' => [id => [status => data]]].
 */
function pedidos_complementos(array $ids): array
{
    $out = ['itens' => [], 'quando' => []];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return $out;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT order_id, nome, preco_centavos, quantidade FROM order_items
                          WHERE order_id IN ($ph) ORDER BY id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $i) {
        $out['itens'][(int) $i['order_id']][] = $i;
    }
    // A data mais recente de cada status (se voltou a um status, vale a última vez).
    $st = db()->prepare("SELECT order_id, status, MAX(criado_em) AS em FROM order_status_history
                          WHERE order_id IN ($ph) GROUP BY order_id, status");
    $st->execute($ids);
    foreach ($st->fetchAll() as $h) {
        $out['quando'][(int) $h['order_id']][$h['status']] = $h['em'];
    }
    return $out;
}

/** Abas de "Meus pedidos": chave => [rótulo curto, rótulo completo, condição SQL]. */
function pedido_grupos(): array
{
    return [
        'todos'      => ['Todos', 'Todos', '1 = 1'],
        'andamento'  => ['Em andamento', 'Em andamento', "o.status IN ('aguardando_pagamento','realizado','producao','pronto')"],
        'apagar'     => ['A pagar', 'Aguardando pagamento', "o.status = 'aguardando_pagamento'"],
        'concluidos' => ['Concluídos', 'Concluídos', "o.status = 'finalizado'"],
        'cancelados' => ['Cancelados', 'Cancelados', "o.status = 'cancelado'"],
    ];
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
        'SELECT o.id, o.status, o.pagamento_status, o.frete_centavos, o.total_centavos, u.nome, u.email
           FROM orders o LEFT JOIN users u ON u.id = o.user_id
          WHERE o.id = ? LIMIT 1'
    );
    $st->execute([$pedido_id]);
    $p = $st->fetch();
    if (!$p || $p['status'] !== 'aguardando_pagamento' || !pagamento_pode_pagar($p)) {
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

    // "pending" (ex.: Pix gerado e ainda não pago) também conta como em análise:
    // já existe um pagamento em curso, então não se gera outro.
    $mapa = [
        'approved' => 'aprovado', 'authorized' => 'em_analise', 'in_process' => 'em_analise',
        'in_mediation' => 'em_analise', 'pending' => 'em_analise', 'rejected' => 'recusado',
        'cancelled' => 'cancelado', 'refunded' => 'estornado', 'charged_back' => 'estornado',
    ];
    $novo  = $mapa[$pg['status'] ?? ''] ?? 'pendente';
    $tipo  = (string) ($pg['payment_type_id'] ?? '');
    $forma = ($pg['payment_method_id'] ?? '') === 'pix' ? 'pix'
           : ($tipo === 'credit_card' ? 'credito' : ($tipo === 'debit_card' ? 'debito'
           : ($tipo === 'ticket' ? 'boleto' : $tipo)));
    // Link do QR/código do Pix ou do boleto (só interessa enquanto está pendente).
    $ticket = (string) ($pg['point_of_interaction']['transaction_data']['ticket_url']
            ?? $pg['transaction_details']['external_resource_url'] ?? '');
    $ticket = preg_match('#^https://#i', $ticket) ? mb_substr($ticket, 0, 500) : '';

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
            'UPDATE orders SET pagamento_status = ?, pagamento = ?, pagamento_tipo = ?,
                    pagamento_ticket_url = ?, mp_payment_id = ?,
                    pago_em = IF(? = "aprovado" AND pago_em IS NULL, NOW(), pago_em)
              WHERE id = ?'
        )->execute([
            $novo, $forma !== '' ? $forma : null, $tipo !== '' ? $tipo : null,
            ($novo === 'em_analise' && $ticket !== '') ? $ticket : null,
            $pay_id, $novo, $pedido_id,
        ]);

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
