<?php
/**
 * Regras e prazos aceitos no checkout.
 * O mesmo conteúdo aparece no modal de aceite e é gravado no pedido como texto
 * (orders.termos_texto + termos_aceitos_em), para saber depois o que a pessoa aceitou.
 * O hash garante que o texto gravado é o mesmo que estava na tela: se as regras,
 * os prazos ou o carrinho mudarem no meio, o checkout pede o aceite de novo.
 */

/**
 * Monta as regras e prazos de um carrinho (linhas de _checkout_carrinho):
 *   itens       [['nome', 'qtd', 'prazo']]   prazo de cada item (coleção ou produção)
 *   especificas [['nome', 'texto']]          regras de cada produto (regras_produto)
 *   gerais      string                       regras gerais da loja
 */
function termos_montar(array $linhas): array
{
    $ids = array_map(fn ($l) => (int) $l['produto']['id'], $linhas);
    $extra = [];
    if ($ids) {
        $st = db()->prepare('SELECT id, regras_produto, dias_producao FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $extra[(int) $r['id']] = $r;
        }
    }
    $itens = [];
    $especificas = [];
    foreach ($linhas as $l) {
        $pid = (int) $l['produto']['id'];
        $col = colecao_do_produto($pid);
        $dias = (int) ($extra[$pid]['dias_producao'] ?? 0);
        if ($col) {
            $prazo = 'Coleção ' . $col['nome'] . ': pedidos até ' . data_br($col['encomendas_ate'])
                   . '; entrega ou retirada a partir de ' . data_br($col['entrega_em']) . '.';
            if (trim((string) $col['observacao']) !== '') {
                $prazo .= ' ' . trim((string) $col['observacao']);
            }
        } elseif ($dias > 0) {
            $prazo = 'Tempo de produção: ' . $dias . ' ' . ($dias === 1 ? 'dia útil' : 'dias úteis') . '.';
        } else {
            $prazo = 'Prazo combinado com a loja.';
        }
        $itens[] = ['nome' => (string) $l['produto']['nome'], 'qtd' => (int) $l['qtd'], 'prazo' => $prazo];
        $regra = trim(str_replace("\r\n", "\n", (string) ($extra[$pid]['regras_produto'] ?? '')));
        if ($regra !== '') {
            $especificas[] = ['nome' => (string) $l['produto']['nome'], 'texto' => $regra];
        }
    }
    return [
        'itens'       => $itens,
        'especificas' => $especificas,
        'gerais'      => trim(str_replace("\r\n", "\n", (string) cfg('regras_texto', ''))),
    ];
}

/** Identifica exatamente o texto mostrado (vai num campo escondido do checkout). */
function termos_hash(array $t): string
{
    return hash('sha256', termos_texto($t));
}

/** HTML do modal (tudo escapado). */
function termos_html(array $t): string
{
    $h = '<h3>Prazos dos seus itens</h3><ul class="termos-itens">';
    foreach ($t['itens'] as $i) {
        $h .= '<li><b>' . (int) $i['qtd'] . '× ' . e($i['nome']) . '</b><span>' . e($i['prazo']) . '</span></li>';
    }
    $h .= '</ul>';
    if ($t['especificas']) {
        $h .= '<h3>Regras de cada produto</h3>';
        foreach ($t['especificas'] as $r) {
            $h .= '<div class="destaque-regras"><b>' . e($r['nome']) . '</b><br>' . nl2br(e($r['texto'])) . '</div>';
        }
    }
    if ($t['gerais'] !== '') {
        $h .= '<h3>Regras gerais</h3><div>' . nl2br(e($t['gerais'])) . '</div>';
    }
    return $h;
}

/** Texto puro gravado no pedido. */
function termos_texto(array $t): string
{
    $s = "PRAZOS DOS SEUS ITENS\n";
    foreach ($t['itens'] as $i) {
        $s .= '- ' . $i['qtd'] . '× ' . $i['nome'] . ' — ' . $i['prazo'] . "\n";
    }
    if ($t['especificas']) {
        $s .= "\nREGRAS DE CADA PRODUTO\n";
        foreach ($t['especificas'] as $r) {
            $s .= $r['nome'] . ":\n" . $r['texto'] . "\n";
        }
    }
    if ($t['gerais'] !== '') {
        $s .= "\nREGRAS GERAIS\n" . $t['gerais'] . "\n";
    }
    return rtrim($s);
}
