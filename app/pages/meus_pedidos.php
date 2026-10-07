<?php
/**
 * "Meus pedidos": /meus-pedidos?status=apagar&periodo=30d&q=&pedido=12
 *   - Computador (>= 760px): lista à esquerda, detalhe do pedido à direita
 *     (trocado pelo app.js sem recarregar; os detalhes já vêm no HTML).
 *   - Celular: a lista ocupa a tela e cada linha abre /pedido/{id}.
 * Filtros na querystring (o Voltar do navegador funciona). Tudo restrito aos
 * pedidos da cliente logada.
 */
exigir_login();
$usuario = usuario_atual();
$uid     = (int) $usuario['id'];

pagamento_cancelar_expirados();   // pedidos não pagos em 24h viram "cancelado"

$GRUPOS   = pedido_grupos();
$PERIODOS = ['30d' => 'Últimos 30 dias', '6m' => 'Últimos 6 meses', 'todos' => 'Todos'];

$f_status  = isset($GRUPOS[$_GET['status'] ?? '']) ? $_GET['status'] : 'todos';
$f_periodo = isset($PERIODOS[$_GET['periodo'] ?? '']) ? $_GET['periodo'] : '30d';
$f_q       = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$f_pedido  = (int) ($_GET['pedido'] ?? 0);

// Condições comuns (cliente + período + busca) -> valem para a lista e para as contagens.
$where = ['o.user_id = ?'];
$args  = [$uid];
if ($f_periodo === '30d') {
    $where[] = 'o.criado_em >= NOW() - INTERVAL 30 DAY';
} elseif ($f_periodo === '6m') {
    $where[] = 'o.criado_em >= NOW() - INTERVAL 6 MONTH';
}
if ($f_q !== '') {
    $num  = ltrim($f_q, '#');
    $like = '%' . addcslashes($f_q, '%_\\') . '%';
    if (ctype_digit($num)) {
        $where[] = '(o.id = ? OR EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id AND i.nome LIKE ?))';
        $args[]  = (int) $num;
    } else {
        $where[] = 'EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id AND i.nome LIKE ?)';
    }
    $args[] = $like;
}
$where_sql = implode(' AND ', $where);

// Contagem por aba (uma consulta).
$somas = [];
foreach ($GRUPOS as $chave => [, , $cond]) {
    $somas[] = "SUM(CASE WHEN $cond THEN 1 ELSE 0 END) AS `$chave`";
}
$st = db()->prepare('SELECT ' . implode(', ', $somas) . " FROM orders o WHERE $where_sql");
$st->execute($args);
$contagem = array_map('intval', $st->fetch() ?: []);

// Lista da aba atual.
$st = db()->prepare("SELECT o.* FROM orders o WHERE $where_sql AND " . $GRUPOS[$f_status][2]
                  . ' ORDER BY o.id DESC LIMIT 60');
$st->execute($args);
$pedidos = $st->fetchAll();

$comp = pedidos_complementos(array_column($pedidos, 'id'));
$ids  = array_map('intval', array_column($pedidos, 'id'));
$sel  = in_array($f_pedido, $ids, true) ? $f_pedido : ($ids[0] ?? 0);

// A cliente tem algum pedido? (para a mensagem de lista vazia)
$st = db()->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
$st->execute([$uid]);
$tem_pedidos = (int) $st->fetchColumn() > 0;

/** Link de "Meus pedidos" mantendo os filtros atuais, com as mudanças pedidas. */
$link = function (array $mudar = []) use ($f_status, $f_periodo, $f_q): string {
    $qs = array_merge(['status' => $f_status, 'periodo' => $f_periodo, 'q' => $f_q], $mudar);
    $qs = array_filter($qs, fn ($v) => $v !== '' && $v !== null);
    return url('meus-pedidos') . ($qs ? '?' . http_build_query($qs) : '');
};

/** "3× Dia dos avós, 1× Sabonete de Mel" */
$resumo = function (array $itens): string {
    return implode(', ', array_map(fn ($i) => (int) $i['quantidade'] . '× ' . $i['nome'], $itens));
};

$chev = '<svg class="mp-chev" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';

ob_start();
?>
<div class="mp" data-meus-pedidos>
    <div class="mp-head">
        <h1>Meus pedidos</h1>
        <!-- Computador: busca + período -->
        <form class="mp-busca" method="get" action="<?= e(url('meus-pedidos')) ?>" role="search">
            <input type="hidden" name="status" value="<?= e($f_status) ?>">
            <label class="mp-campo mp-campo-busca" for="mp-q">Buscar
                <input id="mp-q" type="search" name="q" value="<?= e($f_q) ?>" placeholder="Nº do pedido ou produto">
            </label>
            <label class="mp-campo" for="mp-periodo">Período
                <select id="mp-periodo" name="periodo" data-mp-auto>
                    <?php foreach ($PERIODOS as $k => $r): ?>
                        <option value="<?= e($k) ?>" <?= $f_periodo === $k ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="mp-sr" type="submit">Buscar</button>
        </form>
    </div>

    <!-- Computador: abas de status -->
    <nav class="mp-abas" aria-label="Filtrar por status">
        <?php foreach ($GRUPOS as $k => [$curto]): ?>
            <a class="mp-aba" href="<?= e($link(['status' => $k])) ?>" <?= $f_status === $k ? 'aria-current="page"' : '' ?>>
                <?= e($curto) ?> <span class="mp-count"><?= (int) ($contagem[$k] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </nav>

    <!-- Celular: status + período em selects -->
    <form class="mp-filtros-m" method="get" action="<?= e(url('meus-pedidos')) ?>">
        <input type="hidden" name="q" value="<?= e($f_q) ?>">
        <label class="mp-campo" for="mp-status-m">Status
            <select id="mp-status-m" name="status" data-mp-auto>
                <?php foreach ($GRUPOS as $k => [, $completo]): ?>
                    <option value="<?= e($k) ?>" <?= $f_status === $k ? 'selected' : '' ?>><?= e($completo) ?> (<?= (int) ($contagem[$k] ?? 0) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="mp-campo" for="mp-periodo-m">Período
            <select id="mp-periodo-m" name="periodo" data-mp-auto>
                <?php foreach ($PERIODOS as $k => $r): ?>
                    <option value="<?= e($k) ?>" <?= $f_periodo === $k ? 'selected' : '' ?>><?= e($r) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <noscript><button class="btn sec" type="submit">Filtrar</button></noscript>
    </form>

    <div class="mp-layout">
        <div class="mp-lista">
            <?php if (empty($pedidos)): ?>
                <?php if ($tem_pedidos): ?>
                    <p class="mp-vazio">Nenhum pedido neste filtro.
                        <?php if ($f_periodo !== 'todos'): ?>
                            <a href="<?= e($link(['periodo' => 'todos'])) ?>">Ver todos os períodos</a>
                        <?php endif; ?></p>
                <?php else: ?>
                    <p class="mp-vazio">Você ainda não fez nenhum pedido.<br>
                        <a class="btn mt-1" href="<?= e(url()) ?>">Ver produtos</a></p>
                <?php endif; ?>
            <?php else: ?>
                <?php foreach ($pedidos as $p): $pid = (int) $p['id']; $sit = pedido_situacao($p); ?>
                    <a class="mp-row" href="<?= e(url('pedido/' . $pid)) ?>" data-mp-row="<?= $pid ?>"
                       aria-current="<?= $pid === $sel ? 'true' : 'false' ?>">
                        <span class="mp-row-main">
                            <span class="mp-row-top"><span>Pedido #<?= $pid ?></span>
                                <span class="mp-num"><?= e(money((int) $p['total_centavos'])) ?></span></span>
                            <span class="mp-row-sub"><?= e(date('d/m · H:i', strtotime($p['criado_em']))) ?> ·
                                <?= e($resumo($comp['itens'][$pid] ?? [])) ?></span>
                            <span class="mp-pill <?= e($sit['classe']) ?>"><?= e($sit['rotulo']) ?></span>
                        </span>
                        <?= $chev ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!empty($pedidos)): ?>
            <!-- Computador: detalhe do pedido selecionado (os demais ficam prontos, ocultos) -->
            <div class="mp-detalhe-col">
                <?php foreach ($pedidos as $p): $pid = (int) $p['id']; ?>
                    <div data-mp-detalhe="<?= $pid ?>" <?= $pid === $sel ? '' : 'hidden' ?>>
                        <?php view('pedido-detalhe', [
                            'pedido' => $p,
                            'itens'  => $comp['itens'][$pid] ?? [],
                            'quando' => $comp['quando'][$pid] ?? [],
                            'voltar' => false,
                        ]); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
view('layout', ['titulo' => 'Meus pedidos', 'conteudo' => ob_get_clean()]);
