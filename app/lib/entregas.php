<?php
/**
 * Datas de entrega do carrinho.
 * Produto de coleção: entrega a partir da data da coleção. Produto sem coleção:
 * hoje + prazo de produção em dias úteis (segunda a sexta; feriados não contam).
 * Carrinho com mais de uma data: o cliente escolhe no checkout entre receber
 * tudo junto (na data mais distante) ou separar em um pedido por data, pagos juntos.
 */

/** A migração migracao_entrega_separada.sql rodou? Sem ela, não separa nem guarda a data. */
function entrega_tem_colunas(): bool
{
    static $tem = null;
    if ($tem === null) {
        try {
            db()->query('SELECT entrega_a_partir, grupo_pagamento FROM orders LIMIT 0');
            $tem = true;
        } catch (PDOException $e) {
            $tem = false;
        }
    }
    return $tem;
}

/** "#12", "#12 e #13", "#12, #13 e #14". */
function pedidos_lista_texto(array $ids): string
{
    $ids = array_map(fn ($i) => '#' . (int) $i, array_values($ids));
    if (count($ids) <= 1) {
        return $ids[0] ?? '';
    }
    $ultimo = array_pop($ids);
    return implode(', ', $ids) . ' e ' . $ultimo;
}

/** Ids dos pedidos pagos junto com este (o próprio incluído), em ordem; [id] se for sozinho. */
function pedido_grupo_ids(int $pedido_id): array
{
    if (!entrega_tem_colunas()) {
        return [$pedido_id];
    }
    $st = db()->prepare('SELECT g.id FROM orders o JOIN orders g ON g.grupo_pagamento = o.grupo_pagamento
                          WHERE o.id = ? AND o.grupo_pagamento IS NOT NULL ORDER BY g.id');
    $st->execute([$pedido_id]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return $ids ?: [$pedido_id];
}

/** Data (Y-m-d) depois de $dias dias úteis a partir de $de (sábado e domingo não contam). */
function dias_uteis_depois(string $de, int $dias): string
{
    $d = new DateTimeImmutable($de);
    while ($dias > 0) {
        $d = $d->modify('+1 day');
        if ((int) $d->format('N') <= 5) {
            $dias--;
        }
    }
    return $d->format('Y-m-d');
}

/**
 * Separa as linhas do carrinho por data de entrega, em ordem de data:
 * [['data' => 'Y-m-d', 'rotulo' => 'Coleção Natal' | 'Outros produtos', 'linhas' => [...], 'subtotal' => int]].
 * Cada coleção (pela data) é um grupo; os produtos sem coleção formam um grupo só,
 * com a data do maior prazo de produção entre eles.
 */
function entrega_grupos(array $linhas): array
{
    $hoje = colecao_hoje();
    $ids = array_map(fn ($l) => (int) $l['produto']['id'], $linhas);
    $dias = [];
    if ($ids) {
        $st = db()->prepare('SELECT id, dias_producao FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $dias[(int) $r['id']] = (int) $r['dias_producao'];
        }
    }
    $grupos = [];
    foreach ($linhas as $l) {
        $pid = (int) $l['produto']['id'];
        $col = colecao_do_produto($pid);
        if ($col) {
            $chave = 'c' . $col['entrega_em'];
            $data = (string) $col['entrega_em'];
            $rotulo = 'Coleção ' . $col['nome'];
        } else {
            $chave = 'p';
            $data = dias_uteis_depois($hoje, max(0, $dias[$pid] ?? 0));
            $rotulo = 'Outros produtos';
        }
        if (!isset($grupos[$chave])) {
            $grupos[$chave] = ['data' => $data, 'rotulo' => $rotulo, 'linhas' => [], 'subtotal' => 0];
        } elseif ($chave === 'p') {
            $grupos[$chave]['data'] = max($grupos[$chave]['data'], $data);
        } elseif (!str_contains($grupos[$chave]['rotulo'], $col['nome'])) {
            $grupos[$chave]['rotulo'] .= ' e ' . $col['nome'];   // duas coleções com a mesma data
        }
        $grupos[$chave]['linhas'][] = $l;
        $grupos[$chave]['subtotal'] += (int) $l['subtotal'];
    }
    $grupos = array_values($grupos);
    usort($grupos, fn ($a, $b) => strcmp($a['data'], $b['data']));
    return $grupos;
}

/** Data em que tudo fica pronto (a mais distante dos grupos). */
function entrega_data_junto(array $grupos): string
{
    return $grupos ? max(array_column($grupos, 'data')) : colecao_hoje();
}

/** Texto da forma de entrega para a cópia das regras aceitas no pedido. */
function entrega_texto_aceite(array $grupos, bool $separado, array $pedidos = []): string
{
    if (count($grupos) <= 1) {
        return "ENTREGA\nA partir de " . data_br(entrega_data_junto($grupos)) . '.';
    }
    if (!$separado) {
        return "ENTREGA\nTudo junto, a partir de " . data_br(entrega_data_junto($grupos)) . ' (data do último item).';
    }
    $s = "ENTREGA\nSeparada por data, um pedido para cada entrega, pagos juntos:";
    foreach ($grupos as $i => $g) {
        $s .= "\n- " . (isset($pedidos[$i]) ? 'Pedido #' . $pedidos[$i] . ' — ' : '') . $g['rotulo'] . ', a partir de ' . data_br($g['data']) . '.';
    }
    return $s;
}
