<?php
/**
 * Painel administrativo: /admin — "Operação do dia".
 *   1. Saudação pelo horário de Brasília e a data por extenso.
 *   2. Alerta de estorno pendente (o mesmo de Pedidos).
 *   3. Atalhos: para produzir, em produção, entregas e retiradas, a pagar.
 *   4. Vendas (Hoje | 7 dias | 14 dias) com comparação e gráfico dos últimos 14 dias.
 *   5. Mais vendidos (30 dias).  6. Pedidos recentes.
 *
 * "Vendido" = pedidos com pagamento aprovado, pela data de pago_em, descontando estornos.
 * Os dias seguem o relógio do MySQL (o mesmo que grava pago_em).
 */
exigir_admin();

pagamento_cancelar_expirados();   // pedidos não pagos em 24h viram "cancelado"

$pdo = db();

// --- 1. Saudação ----------------------------------------------------------------
$agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$hora = (int) $agora->format('G');
$saudacao = $hora >= 5 && $hora < 12 ? 'Bom dia' : ($hora >= 12 && $hora < 18 ? 'Boa tarde' : 'Boa noite');
$primeiro_nome = strtok(trim((string) (usuario_atual()['nome'] ?? '')), ' ') ?: '';
$DIAS_SEMANA = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
$MESES = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$data_extenso = $DIAS_SEMANA[(int) $agora->format('w')] . ', ' . $agora->format('j') . ' de ' . $MESES[(int) $agora->format('n')];

// Links para Pedidos já filtrados (todo o período, para bater com os números daqui).
$link_pedidos = fn (array $q) => url('admin/pedidos') . '?' . http_build_query($q + ['per' => 'tudo', 'modo' => 'lista']);

// --- 2. Alerta de estorno -------------------------------------------------------
$alerta = pedidos_estorno_pendente_ids();

// --- 3. Atalhos -----------------------------------------------------------------
$c = $pdo->query(
    "SELECT SUM(status = 'realizado')                       AS produzir,
            SUM(status IN ('producao','embalagem'))         AS producao,
            SUM(status = 'em_rota')                         AS rota,
            SUM(status = 'pronto' AND entrega = 'retirada') AS retirar,
            SUM(status = 'pronto' AND entrega = 'motoboy')  AS sair,
            SUM(status = 'aguardando_pagamento')            AS pagar
       FROM orders
      WHERE status IN ('realizado','producao','embalagem','pronto','em_rota','aguardando_pagamento')"
)->fetch();
$c = array_map('intval', $c ?: []);
$entregas_txt = ($c['rota'] ?? 0) . ' em rota · ' . ($c['retirar'] ?? 0) . ' aguardando retirada'
    . (!empty($c['sair']) ? ' · ' . $c['sair'] . ' pronto' . ($c['sair'] > 1 ? 's' : '') . ' para sair' : '');
$ATALHOS = [
    ['n' => $c['produzir'] ?? 0, 't' => 'Para produzir', 's' => 'Pagos, aguardando produção',
     'href' => url('admin/pedidos') . '?' . http_build_query(['per' => 'tudo', 'modo' => 'quadro']), 'quente' => !empty($c['produzir'])],
    ['n' => $c['producao'] ?? 0, 't' => 'Em produção', 's' => 'Inclui embalagem',
     'href' => $link_pedidos(['aba' => 'em_producao']), 'quente' => false],
    ['n' => ($c['rota'] ?? 0) + ($c['retirar'] ?? 0) + ($c['sair'] ?? 0), 't' => 'Entregas e retiradas', 's' => $entregas_txt,
     'href' => $link_pedidos(['aba' => 'entregas']), 'quente' => false],
    ['n' => $c['pagar'] ?? 0, 't' => 'A pagar', 's' => 'Aguardando o cliente pagar',
     'href' => $link_pedidos(['aba' => 'pagar']), 'quente' => false],
];

// --- 4. Vendas: 28 dias (14 no gráfico + 14 anteriores para comparar) -------------
$hoje = new DateTimeImmutable((string) $pdo->query('SELECT CURDATE()')->fetchColumn());
$inicio = $hoje->modify('-27 days');
// Valor líquido do pedido: total menos o estorno (estorno total = 0).
$liquido = "GREATEST(0, total_centavos - CASE WHEN pagamento_status = 'estornado' OR estorno_status IN ('aprovado','approved')
                                             THEN COALESCE(estorno_valor_centavos, total_centavos) ELSE 0 END)";
$st = $pdo->prepare(
    "SELECT DATE(pago_em) AS dia, SUM($liquido) AS vendido, SUM($liquido > 0) AS pedidos
       FROM orders
      WHERE pago_em >= ? AND pago_em < ? AND pagamento_status IN ('aprovado','estornado')
      GROUP BY DATE(pago_em)"
);
$st->execute([$inicio->format('Y-m-d'), $hoje->modify('+1 day')->format('Y-m-d')]);
$por_dia = [];
foreach ($st->fetchAll() as $l) {
    $por_dia[$l['dia']] = ['v' => (int) $l['vendido'], 'n' => (int) $l['pedidos']];
}
$SEMANA_CURTA = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
$serie = [];   // 28 dias, do mais antigo para hoje
for ($d = $inicio; $d <= $hoje; $d = $d->modify('+1 day')) {
    $k = $d->format('Y-m-d');
    $serie[] = ['rot' => $d->format('d/m'), 'sem' => $SEMANA_CURTA[(int) $d->format('w')],
                'v' => $por_dia[$k]['v'] ?? 0, 'n' => $por_dia[$k]['n'] ?? 0];
}
$grafico = array_slice($serie, -14);

$PERIODOS = [
    'hoje' => ['dias' => 1,  'botao' => 'Hoje',    'vendido' => 'Vendido hoje',              'antes' => 'que ontem'],
    '7'    => ['dias' => 7,  'botao' => '7 dias',  'vendido' => 'Vendido nos últimos 7 dias',  'antes' => 'que os 7 dias anteriores'],
    '14'   => ['dias' => 14, 'botao' => '14 dias', 'vendido' => 'Vendido nos últimos 14 dias', 'antes' => 'que os 14 dias anteriores'],
];
$per = isset($PERIODOS[$_GET['per'] ?? '']) ? $_GET['per'] : '7';
foreach ($PERIODOS as $k => $cfg) {
    $atual = array_slice($serie, -$cfg['dias']);
    $antes = array_slice($serie, -2 * $cfg['dias'], $cfg['dias']);
    $v = array_sum(array_column($atual, 'v'));
    $n = array_sum(array_column($atual, 'n'));
    $v_antes = array_sum(array_column($antes, 'v'));
    $PERIODOS[$k] += [
        'v' => $v, 'n' => $n, 'medio' => $n ? (int) round($v / $n) : 0,
        'delta' => $v_antes > 0 ? (int) round(($v - $v_antes) / $v_antes * 100) : null,   // sem base: esconde
    ];
}

// --- 5. Mais vendidos (30 dias, pagos e não cancelados) ---------------------------
$st = $pdo->prepare(
    "SELECT COALESCE(MAX(p.nome), MAX(oi.nome)) AS nome, SUM(oi.quantidade) AS qtd
       FROM orders o
       JOIN order_items oi ON oi.order_id = o.id
       LEFT JOIN products p ON p.id = oi.product_id
      WHERE o.pago_em >= ? AND o.pagamento_status = 'aprovado' AND o.status <> 'cancelado'
      GROUP BY COALESCE(CONCAT('p', oi.product_id), CONCAT('n', oi.nome))
      ORDER BY qtd DESC, nome ASC
      LIMIT 5"
);
$st->execute([$hoje->modify('-29 days')->format('Y-m-d')]);
$top = $st->fetchAll();
$top_max = $top ? max(1, (int) $top[0]['qtd']) : 1;

// --- 6. Pedidos recentes --------------------------------------------------------
$recentes = $pdo->query(
    'SELECT o.id, o.status, o.entrega, o.total_centavos, o.criado_em, COALESCE(u.nome, o.contato_nome) AS cliente
       FROM orders o LEFT JOIN users u ON u.id = o.user_id
      ORDER BY o.id DESC LIMIT 5'
)->fetchAll();

ob_start();
?>
<div class="pn">
    <?php if ($alerta): ?>
        <div class="ap-alerta" role="status">
            <span><?= e(pedidos_estorno_alerta_texto($alerta)) ?></span>
            <a class="ap-btn ap-btn-perigo" href="<?= e($link_pedidos(['aba' => 'cancelado', 'abrir' => $alerta[0]])) ?>">Revisar estorno</a>
        </div>
    <?php endif; ?>

    <nav class="pn-atalhos" aria-label="Pedidos por etapa">
        <?php foreach ($ATALHOS as $a): ?>
            <a class="pn-atalho<?= $a['quente'] ? ' is-quente' : '' ?>" href="<?= e($a['href']) ?>">
                <b class="ap-num"><?= (int) $a['n'] ?></b>
                <span><?= e($a['t']) ?></span>
                <small><?= e($a['s']) ?></small>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="pn-grade">
        <section class="pn-card" aria-labelledby="pn-vendas" data-pn-vendas data-per="<?= e($per) ?>">
            <div class="pn-card-topo">
                <h2 id="pn-vendas">Vendas</h2>
                <div class="pn-seg" role="group" aria-label="Período">
                    <?php foreach ($PERIODOS as $k => $p): ?>
                        <a href="<?= e(url('admin') . '?per=' . $k) ?>" data-pn-per="<?= e($k) ?>" aria-pressed="<?= (string) $k === $per ? 'true' : 'false' ?>"><?= e($p['botao']) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php foreach ($PERIODOS as $k => $p): ?>
                <div class="pn-kpis" data-pn-kpis="<?= e($k) ?>" <?= (string) $k === $per ? '' : 'hidden' ?>>
                    <div class="pn-kpi">
                        <span class="pn-v ap-num"><?= e(money($p['v'])) ?></span>
                        <span class="pn-l"><?= e($p['vendido']) ?></span>
                        <?php if ($p['delta'] !== null): ?>
                            <span class="pn-delta <?= $p['delta'] >= 0 ? 'is-sobe' : 'is-cai' ?>"><?= $p['delta'] >= 0 ? '▲' : '▼' ?> <?= abs($p['delta']) ?>% <?= e($p['antes']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="pn-kpi"><span class="pn-v ap-num"><?= (int) $p['n'] ?></span><span class="pn-l">Pedidos pagos</span></div>
                    <div class="pn-kpi"><span class="pn-v ap-num"><?= e(money($p['medio'])) ?></span><span class="pn-l">Valor médio por pedido</span></div>
                </div>
            <?php endforeach; ?>

            <div class="pn-grafico" data-pn-grafico aria-hidden="true"></div>
            <script type="application/json" data-pn-dados><?= json_encode($grafico, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
            <details class="pn-tabela">
                <summary>Ver em tabela</summary>
                <div class="pn-tabela-rolagem">
                    <table>
                        <caption class="ap-sr">Vendas pagas por dia nos últimos 14 dias</caption>
                        <thead><tr><th>Dia</th><th class="ap-dir">Pedidos pagos</th><th class="ap-dir">Vendido</th></tr></thead>
                        <tbody>
                            <?php foreach ($grafico as $d): ?>
                                <tr><td><?= e($d['sem'] . ' ' . $d['rot']) ?></td><td class="ap-dir ap-num"><?= (int) $d['n'] ?></td><td class="ap-dir ap-num"><?= e(money($d['v'])) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </section>

        <section class="pn-card" aria-labelledby="pn-top">
            <div class="pn-card-topo"><h2 id="pn-top">Mais vendidos</h2><span class="ap-sub">últimos 30 dias</span></div>
            <?php if (!$top): ?>
                <p class="ap-sub">Nenhuma venda paga nos últimos 30 dias.</p>
            <?php else: ?>
                <ol class="pn-rank">
                    <?php foreach ($top as $t): ?>
                        <li>
                            <span><?= e($t['nome']) ?></span>
                            <span class="ap-sub ap-num"><b><?= (int) $t['qtd'] ?></b> un.</span>
                            <span class="pn-medidor" aria-hidden="true"><i style="width: <?= round((int) $t['qtd'] / $top_max * 100, 1) ?>%"></i></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>

    <section class="pn-card" aria-labelledby="pn-recentes">
        <div class="pn-card-topo"><h2 id="pn-recentes">Pedidos recentes</h2><a class="pn-link" href="<?= e($link_pedidos(['aba' => 'todos'])) ?>">Ver todos</a></div>
        <?php if (!$recentes): ?>
            <p class="ap-sub">Nenhum pedido ainda.</p>
        <?php else: ?>
            <div class="pn-linhas">
                <?php foreach ($recentes as $r):
                    $st_r = $r['status'] === 'embalagem' ? 'producao' : $r['status'];   // embalagem conta como "Em produção"
                    $ent = $r['entrega'] === 'motoboy' ? 'motoboy' : 'retirada'; ?>
                    <a class="pn-linha" href="<?= e($link_pedidos(['aba' => 'todos', 'abrir' => (int) $r['id']])) ?>">
                        <b class="pn-id">#<?= (int) $r['id'] ?></b>
                        <span class="pn-quem"><?= e($r['cliente'] ?: '—') ?><span class="ap-sub"><?= e(date('d/m · H:i', strtotime($r['criado_em']))) ?></span></span>
                        <span class="pn-st"><span class="ap-pill <?= e(pedido_status_classe($st_r)) ?>"><?= e(pedido_status_rotulo($st_r, $ent)) ?></span></span>
                        <b class="pn-tot ap-num"><?= e(money((int) $r['total_centavos'])) ?></b>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<script src="<?= e(asset('assets/js/admin-painel.js')) ?>"></script>
<?php
view('admin_layout', [
    'titulo'    => 'Painel',
    'titulo_h1' => $saudacao . ($primeiro_nome !== '' ? ', ' . $primeiro_nome : ''),
    'subtitulo' => $data_extenso,
    'conteudo'  => ob_get_clean(),
    'layout_largo' => true,   // mesma largura de Pedidos
]);
