<?php
/**
 * Pagamento dos pedidos (Fase 3) — regras da loja em cima do Mercado Pago.
 *
 * Ciclo do pedido com pagamento online (etapas em pedido_status.php):
 *   aguardando_pagamento --(pago)--> realizado ("Novo") --> producao --> ...
 *            \--(24h sem pagar)--> cancelado
 *   Pagamento aprovado DEPOIS do cancelamento: o pedido continua cancelado e fica
 *   com "estorno pendente" para a loja decidir (não reabre sozinho).
 *
 * orders.pagamento_status: pendente | em_analise | aprovado | recusado |
 *                          cancelado | estornado | expirado | divergente
 */

const PAGAMENTO_PRAZO_HORAS = 24;

/** Rótulos genéricos dos status (filtros e listas do admin). */
function pedido_status_rotulos(): array
{
    return [
        'aguardando_pagamento' => 'Aguardando pagamento',
        'realizado'  => 'Novo',
        'producao'   => 'Em produção',
        'embalagem'  => 'Embalagem',
        'pronto'     => 'Pronto',
        'em_rota'    => 'Em rota de entrega',
        'finalizado' => 'Finalizado',
        'cancelado'  => 'Cancelado',
    ];
}

/** Rótulos do status do pagamento. "cancelado" = a cliente desistiu no Mercado Pago. */
function pagamento_status_rotulo(?string $s): string
{
    $r = [
        'pendente'   => 'Pendente',
        'em_analise' => 'Em análise',
        'aprovado'   => 'Aprovado',
        'recusado'   => 'Recusado',
        'cancelado'  => 'Cancelado pela cliente',
        'estornado'  => 'Estornado',
        'expirado'   => 'Não pago',
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
        $aviso = trim(pedido_cancelamento_texto($p) . ' ' . pedido_reembolso_texto($p));
        return ['rotulo' => 'Cancelado', 'classe' => 'st-cancelado', 'aviso' => $aviso, 'acao' => null];
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
    // Etapas de produção: mesmos rótulos da linha do tempo da cliente.
    $s['rotulo'] = ($status === 'realizado' && $ps !== 'aprovado')
        ? 'Pedido recebido'
        : pedido_status_rotulo($status, $motoboy ? 'motoboy' : 'retirada', 'cliente');
    $s['classe'] = $status === 'finalizado' ? 'st-finalizado'
        : (($status === 'realizado' && $ps !== 'aprovado') ? 'st-analise' : 'st-producao');
    return $s;
}

/**
 * Linha do tempo da cliente:
 *   Pedido recebido → Pagamento → Em produção → Embalagem → Pronto para retirada → Retirado
 *   Pedido recebido → Pagamento → Em produção → Embalagem → Pronto para entrega → Saiu para entrega → Entregue
 * $quando: data da última vez em cada status (pedido_historico), status => 'Y-m-d H:i:s'.
 * Cada passo: [rotulo, texto, estado] com estado done | now | wait | todo | cancel.
 */
function pedido_andamento(array $p, array $quando): array
{
    $fmt = fn ($d) => $d ? date('d/m, H:i', strtotime($d)) : '';
    $status = (string) $p['status'];
    $entrega = ($p['entrega'] ?? '') === 'motoboy' ? 'motoboy' : 'retirada';

    if ($status === 'cancelado') {
        return [
            ['Pedido recebido', $fmt($p['criado_em']), 'done'],
            ['Cancelado', pedido_cancelamento_texto($p), 'cancel'],
        ];
    }

    // Etapas de produção depois de "Pagamento" (sem "realizado", que é o próprio pagamento).
    $etapas = array_values(array_filter(pedido_fluxo($entrega), fn ($e) => $e !== 'realizado'));
    // Pagamento confirmado: aprovado, ou pedido já em produção (pago combinando com a loja).
    $pago = ($p['pagamento_status'] ?? '') === 'aprovado' || in_array($status, $etapas, true);
    if (!$pago) {
        $atual = 1;                                            // Pagamento
    } elseif ($status === 'realizado') {
        $atual = 2;                                            // próxima: Em produção
    } else {
        $i = array_search($status, $etapas, true);
        $atual = $status === 'finalizado' ? 99 : 2 + (int) $i; // finalizado: tudo concluído
    }

    $passos = [['Pedido recebido', $fmt($p['criado_em'])], ['Pagamento', '']];
    foreach ($etapas as $e) {
        $passos[] = [pedido_status_rotulo($e, $entrega, 'cliente'), $fmt($quando[$e] ?? null)];
    }
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
    try {
        $st = db()->prepare("SELECT order_id, status_para AS status, MAX(criado_em) AS em FROM pedido_historico
                              WHERE order_id IN ($ph) GROUP BY order_id, status_para");
        $st->execute($ids);
        foreach ($st->fetchAll() as $h) {
            $out['quando'][(int) $h['order_id']][$h['status']] = $h['em'];
        }
    } catch (PDOException $e) {
        // pedido_historico ainda não criada: a linha do tempo sai sem as datas das etapas.
    }
    return $out;
}

/** Abas de "Meus pedidos": chave => [rótulo curto, rótulo completo, condição SQL]. */
function pedido_grupos(): array
{
    return [
        'todos'      => ['Todos', 'Todos', '1 = 1'],
        'andamento'  => ['Em andamento', 'Em andamento', "o.status IN ('aguardando_pagamento','realizado','producao','embalagem','pronto','em_rota')"],
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

        $valor = isset($pg['transaction_amount']) ? (int) round(((float) $pg['transaction_amount']) * 100) : null;

        // Já pago por OUTRO pagamento (ex.: tentativa recusada antes): não volta atrás.
        if ($pedido['pagamento_status'] === 'aprovado' && $pedido['mp_payment_id'] !== $pay_id && $novo !== 'aprovado') {
            _pagamento_registrar_evento($pedido_id, $pay_id, $novo, $forma, $valor);
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

        _pagamento_registrar_evento($pedido_id, $pay_id, $novo, $forma, $valor);

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

        if ($novo === 'aprovado' && $pedido['status'] === 'aguardando_pagamento') {
            // Pago: o pedido entra na fila de produção ("Novo").
            $pdo->prepare('UPDATE orders SET status = "realizado" WHERE id = ? AND status = "aguardando_pagamento"')
                ->execute([$pedido_id]);
            pedido_historico_gravar($pedido_id, 'aguardando_pagamento', 'realizado', 'sistema', null, 'Pagamento aprovado');
        } elseif ($novo === 'aprovado' && $pedido['status'] === 'cancelado') {
            // Pago DEPOIS de cancelado: não reabre. Fica "estorno pendente" para a loja decidir.
            $pdo->prepare('UPDATE orders SET estorno_status = COALESCE(estorno_status, "pendente") WHERE id = ?')
                ->execute([$pedido_id]);
            pedido_historico_gravar($pedido_id, 'cancelado', 'cancelado', 'sistema', null,
                'Pagamento aprovado depois do cancelamento: estorno pendente');
        } elseif ($novo === 'estornado') {
            // Estorno confirmado (pela loja ou pelo painel do Mercado Pago): só registra.
            $pdo->prepare(
                'UPDATE orders SET estorno_status = "aprovado", estorno_em = COALESCE(estorno_em, NOW()),
                        estorno_valor_centavos = COALESCE(estorno_valor_centavos, ?)
                  WHERE id = ?'
            )->execute([$valor, $pedido_id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $pedido_id;
}

/**
 * Guarda uma tentativa de pagamento no histórico (order_payment_events), só quando a
 * situação daquele pagamento muda — o webhook e o retorno repetem o mesmo aviso.
 * Sem a tabela (migração ainda não rodada), não faz nada.
 */
function _pagamento_registrar_evento(int $pedido_id, string $pay_id, string $status, string $forma, ?int $valor): void
{
    try {
        $st = db()->prepare(
            'SELECT status FROM order_payment_events
              WHERE order_id = ? AND mp_payment_id = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$pedido_id, $pay_id]);
        if ($st->fetchColumn() === $status) {
            return;
        }
        db()->prepare(
            'INSERT INTO order_payment_events (order_id, mp_payment_id, status, forma, valor_centavos)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$pedido_id, $pay_id, $status, $forma !== '' ? $forma : null, $valor]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') {   // 42S02 = tabela não existe
            throw $e;
        }
    }
}

/** Texto de uma tentativa de pagamento no histórico do admin. */
function pagamento_evento_texto(array $ev): string
{
    $pix = ($ev['forma'] ?? '') === 'pix';
    $t = [
        'aprovado'   => 'Pagamento aprovado',
        'recusado'   => 'Pagamento recusado',
        'cancelado'  => $pix ? 'Pix expirado ou cancelado' : 'Pagamento cancelado pela cliente',
        'em_analise' => $pix ? 'Pix gerado, aguardando pagamento' : 'Pagamento em análise',
        'estornado'  => 'Pagamento estornado',
        'divergente' => 'Pagamento com valor diferente do pedido',
        'pendente'   => 'Pagamento pendente',
    ][$ev['status']] ?? ('Pagamento: ' . $ev['status']);
    $extra = array_filter([
        !empty($ev['forma']) ? pagamento_forma_rotulo($ev['forma']) : '',
        isset($ev['valor_centavos']) ? money((int) $ev['valor_centavos']) : '',
        'nº ' . $ev['mp_payment_id'],
    ]);
    return $t . ' (' . implode(' · ', $extra) . ')';
}

/**
 * Situação do pagamento explicada para o admin (o que está acontecendo e o que fazer).
 * Devolve ['texto' => ..., 'alerta' => bool].
 */
function pagamento_resumo_admin(array $p): array
{
    $ps = (string) ($p['pagamento_status'] ?? '');
    $status = (string) $p['status'];
    $prazo = '';
    if ($status === 'aguardando_pagamento') {
        $d = pagamento_prazo((int) $p['id']);
        $prazo = $d->format('d/m') . ' às ' . $d->format('H:i');
    }
    if ($ps === 'aprovado' && $status === 'cancelado') {
        $falhou = ($p['estorno_status'] ?? '') === 'falhou';
        return ['texto' => 'Pedido cancelado com pagamento aprovado: a cliente ainda não recebeu o dinheiro de volta.'
            . ($falhou ? ' O estorno automático falhou: estorne pelo painel do Mercado Pago em Atividade → venda → Devolver dinheiro.' : ''),
            'alerta' => true];
    }
    if ($ps === 'aprovado') {
        $quando = !empty($p['pago_em']) ? ' em ' . date('d/m/Y H:i', strtotime($p['pago_em'])) : '';
        return ['texto' => 'Pago' . $quando . ' (' . pagamento_forma_rotulo($p['pagamento'] ?? null) . ').', 'alerta' => false];
    }
    if ($ps === 'estornado') {
        return ['texto' => pedido_reembolso_texto($p) ?: 'Pagamento estornado.', 'alerta' => false];
    }
    if ($status === 'cancelado') {
        return ['texto' => pedido_cancelamento_texto($p), 'alerta' => false];
    }
    if ($status !== 'aguardando_pagamento') {
        return ['texto' => 'Pagamento combinado fora do site (pedido sem pagamento online registrado).', 'alerta' => false];
    }
    if ($ps === 'divergente') {
        return ['texto' => 'O valor pago é diferente do total. Confira no Mercado Pago antes de produzir.', 'alerta' => true];
    }
    if (pagamento_aguardando_pix_boleto($p)) {
        return ['texto' => 'Pix/boleto gerado, aguardando o pagamento até ' . $prazo . '. Não produza ainda.', 'alerta' => false];
    }
    if ($ps === 'em_analise') {
        return ['texto' => 'Pagamento em análise pelo Mercado Pago. Não produza ainda.', 'alerta' => false];
    }
    if ($ps === 'recusado') {
        return ['texto' => 'A última tentativa foi recusada. A cliente pode tentar de novo até ' . $prazo
            . '; depois disso o pedido é cancelado automaticamente.', 'alerta' => false];
    }
    if ($ps === 'cancelado') {
        return ['texto' => 'A cliente cancelou a última tentativa no Mercado Pago. Ela pode tentar de novo até ' . $prazo
            . '; depois disso o pedido é cancelado automaticamente.', 'alerta' => false];
    }
    return ['texto' => 'A cliente ainda não pagou. Prazo: até ' . $prazo
        . '; depois disso o pedido é cancelado automaticamente.', 'alerta' => false];
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

    // Só pedidos aguardando pagamento E sem pagamento aprovado (dupla trava).
    $ids = db()->query(
        'SELECT id FROM orders
          WHERE status = "aguardando_pagamento"
            AND (pagamento_status IS NULL OR pagamento_status <> "aprovado")
            AND criado_em < NOW() - INTERVAL ' . PAGAMENTO_PRAZO_HORAS . ' HOUR'
    )->fetchAll(PDO::FETCH_COLUMN);

    $n = 0;
    foreach ($ids as $id) {
        $up = db()->prepare(
            'UPDATE orders SET status = "cancelado", cancelado_por = "sistema",
                    cancelamento_motivo = "Prazo de pagamento expirado", cancelado_em = NOW(),
                    pagamento_status = IF(pagamento_status = "em_analise", pagamento_status, "expirado")
              WHERE id = ? AND status = "aguardando_pagamento"
                AND (pagamento_status IS NULL OR pagamento_status <> "aprovado")'
        );
        $up->execute([(int) $id]);
        if ($up->rowCount() > 0) {
            pedido_historico_gravar((int) $id, 'aguardando_pagamento', 'cancelado', 'sistema', null,
                'Prazo de pagamento expirado (' . PAGAMENTO_PRAZO_HORAS . ' h)');
            $n++;
        }
    }
    return $n;
}
