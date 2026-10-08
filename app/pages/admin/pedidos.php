<?php
/**
 * Admin › Pedidos
 *   GET  /admin/pedidos                  -> Lista (abas + filtros) e Quadro de produção
 *        ?aba=ativos&q=&pag=&ent=&per=30d&de=&ate=&abrir={id}
 *   GET  /admin/pedidos/{id}             -> pedido em página própria (sem JS)
 *   GET  /admin/pedidos/{id}?parcial=1   -> conteúdo do painel lateral (fetch)
 *   POST op=etapa    (id, de, para)      -> avança/volta UMA etapa (também o "Desfazer")
 *   POST op=cancelar (id, de, motivo, obs, estornar)
 *   POST op=estornar (id)
 * Com X-Requested-With, o POST responde JSON; sem JS, redireciona com aviso.
 */
exigir_admin();
$admin_id = (int) usuario_atual()['id'];

pagamento_cancelar_expirados();   // pedidos não pagos em 24h viram "cancelado"

// -----------------------------------------------------------------------------
// POST: ações
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
    $id   = (int) ($_POST['id'] ?? 0);
    // Volta para a lista com os mesmos filtros (só querystring desta página).
    $voltar = (string) ($_POST['voltar'] ?? '');
    $voltar = preg_match('/^[A-Za-z0-9_=&%.\-]*$/', $voltar) ? $voltar : '';

    $responder = function (array $r) use ($ajax, $id, $voltar): void {
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash($r['ok'] ? 'sucesso' : 'erro', $r['mensagem']);
        redirect('admin/pedidos?' . ltrim($voltar . '&abrir=' . $id, '&'));
    };

    if (!csrf_validar()) {
        $responder(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.']);
    }
    $op = $_POST['op'] ?? '';

    if ($op === 'etapa') {
        $de   = (string) ($_POST['de'] ?? '');
        $para = (string) ($_POST['para'] ?? '');
        $res  = pedido_mudar_etapa($id, $de, $para, $admin_id);
        if ($res === 'conflito') {
            $responder(['ok' => false, 'conflito' => true, 'mensagem' => 'Este pedido foi alterado em outra tela.']);
        }
        if ($res !== 'ok') {
            $responder(['ok' => false, 'mensagem' => 'Esta mudança não é permitida.']);
        }
        $st = db()->prepare('SELECT entrega FROM orders WHERE id = ?');
        $st->execute([$id]);
        $ent = (string) $st->fetchColumn();
        $desfazendo = !empty($_POST['desfazer']);
        $responder([
            'ok'       => true,
            'mensagem' => $desfazendo ? 'Alteração desfeita.'
                        : 'Pedido #' . $id . ': ' . mb_strtolower(pedido_status_rotulo($para, $ent)) . '.',
            // O "Desfazer" é a mudança contrária (vale por 5 s na tela).
            'desfazer' => $desfazendo ? null : ['id' => $id, 'de' => $para, 'para' => $de],
        ]);
    }

    if ($op === 'cancelar') {
        $r = pedido_cancelar_loja($id, (string) ($_POST['de'] ?? ''), (string) ($_POST['motivo'] ?? ''),
            (string) ($_POST['obs'] ?? ''), !empty($_POST['estornar']), $admin_id);
        $responder(['ok' => $r['ok'] && ($r['estorno_ok'] ?? true), 'conflito' => $r['conflito'], 'mensagem' => $r['mensagem']]);
    }

    if ($op === 'estornar') {
        $responder(pedido_estornar($id, $admin_id));
    }

    $responder(['ok' => false, 'mensagem' => 'Ação desconhecida.']);
}

/** Pedido completo para o painel: dados, itens e histórico (status + pagamentos). */
$carregar = function (int $id): ?array {
    $st = db()->prepare(
        'SELECT o.*, u.nome AS cliente_nome, u.email AS cliente_email, u.telefone AS cliente_tel
           FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    $comp = pedidos_complementos([$id]);
    $entrega = $p['entrega'] === 'motoboy' ? 'motoboy' : 'retirada';
    $tempo = [];
    try {
        $st = db()->prepare(
            'SELECT h.*, u.nome AS usuario_nome FROM pedido_historico h
               LEFT JOIN users u ON u.id = h.usuario_id WHERE h.order_id = ? ORDER BY h.id'
        );
        $st->execute([$id]);
        foreach ($st->fetchAll() as $h) {
            $quem = $h['origem'] === 'admin' ? ($h['usuario_nome'] ?: 'Admin') : ($h['origem'] === 'cliente' ? 'Cliente' : 'Sistema');
            $texto = $h['status_de'] === $h['status_para']
                ? (string) $h['observacao']
                : pedido_status_rotulo($h['status_para'], $entrega)
                  . ($h['observacao'] ? ' — ' . $h['observacao'] : '');
            $tempo[] = ['em' => $h['criado_em'], 'texto' => $texto . ' (' . $quem . ')', 'pag' => false];
        }
    } catch (PDOException $e) {
        // pedido_historico ainda não criada (migração pendente).
    }
    try {
        $st = db()->prepare('SELECT * FROM order_payment_events WHERE order_id = ? ORDER BY id');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $ev) {
            $tempo[] = ['em' => $ev['criado_em'], 'texto' => pagamento_evento_texto($ev), 'pag' => true];
        }
    } catch (PDOException $e) {
    }
    // Por data; no mesmo instante, o pagamento vem antes (é ele que muda o status).
    usort($tempo, fn ($a, $b) => strcmp($a['em'], $b['em']) ?: ((int) $b['pag'] <=> (int) $a['pag']));
    return ['p' => $p, 'itens' => $comp['itens'][$id] ?? [], 'linha_tempo' => $tempo];
};

$acao = $params[0] ?? '';

// -----------------------------------------------------------------------------
// DETALHE: /admin/pedidos/{id} (página) e ?parcial=1 (painel lateral)
// -----------------------------------------------------------------------------
if ($acao !== '' && ctype_digit((string) $acao)) {
    $dados = $carregar((int) $acao);
    if (!empty($_GET['parcial'])) {
        if (!$dados) {
            http_response_code(404);
            echo '<p class="ap-vazio">Pedido não encontrado.</p>';
            return;
        }
        view('admin-pedido-painel', $dados + ['em_painel' => true, 'voltar_qs' => (string) ($_GET['voltar'] ?? '')]);
        return;
    }
    if (!$dados) {
        flash('erro', 'Pedido não encontrado.');
        redirect('admin/pedidos');
    }
    ob_start();
    ?>
    <p><a href="<?= e(url('admin/pedidos')) ?>">&larr; Voltar para pedidos</a></p>
    <div class="ap-pagina"><?php view('admin-pedido-painel', $dados + ['em_painel' => false, 'voltar_qs' => '']); ?></div>
    <?php
    view('admin_layout', ['titulo' => 'Pedido #' . (int) $acao, 'conteudo' => ob_get_clean()]);
    return;
}

// -----------------------------------------------------------------------------
// LISTA + QUADRO
// -----------------------------------------------------------------------------
$ABAS = [
    'ativos'     => ['Ativos', "o.status NOT IN ('finalizado','cancelado')"],
    'pagar'      => ['A pagar', "o.status = 'aguardando_pagamento'"],
    'novo'       => ['Novos', "o.status = 'realizado'"],
    'producao'   => ['Em produção', "o.status = 'producao'"],
    'embalagem'  => ['Embalagem', "o.status = 'embalagem'"],
    'pronto'     => ['Prontos', "o.status = 'pronto'"],
    'em_rota'    => ['Em rota', "o.status = 'em_rota'"],
    'finalizado' => ['Finalizados', "o.status = 'finalizado'"],
    'cancelado'  => ['Cancelados', "o.status = 'cancelado'"],
    'todos'      => ['Todos', '1 = 1'],
];
$PAG = [
    'aprovado'  => ['Aprovado', "o.pagamento_status = 'aprovado'"],
    'pendente'  => ['Pendente', "(o.pagamento_status IS NULL OR o.pagamento_status IN ('pendente','em_analise','expirado'))"],
    'recusado'  => ['Recusado', "o.pagamento_status IN ('recusado','cancelado')"],
    'estornado' => ['Estornado', "o.pagamento_status = 'estornado'"],
];
$PERIODOS = ['hoje' => 'Hoje', '7d' => 'Últimos 7 dias', '30d' => 'Últimos 30 dias', 'custom' => 'Personalizado…'];

$f = [
    'aba' => isset($ABAS[$_GET['aba'] ?? '']) ? $_GET['aba'] : 'ativos',
    'q'   => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
    'pag' => isset($PAG[$_GET['pag'] ?? '']) ? $_GET['pag'] : '',
    'ent' => in_array($_GET['ent'] ?? '', ['motoboy', 'retirada'], true) ? $_GET['ent'] : '',
    'per' => isset($PERIODOS[$_GET['per'] ?? '']) ? $_GET['per'] : '30d',
    'de'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de'] ?? '') ? $_GET['de'] : '',
    'ate' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ate'] ?? '') ? $_GET['ate'] : '',
];
$abrir = (int) ($_GET['abrir'] ?? 0);

// Condições comuns (busca, entrega, período) — valem para lista, abas e quadro.
$base = [];
$args = [];
if ($f['q'] !== '') {
    $digitos = preg_replace('/\D+/', '', $f['q']);
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    $cond = ['u.nome LIKE ?', 'u.email LIKE ?', 'o.contato_nome LIKE ?'];
    array_push($args, $like, $like, $like);
    if ($digitos !== '') {
        $cond[] = 'o.id = ?';
        $args[] = (int) $digitos;
        $cond[] = 'u.telefone LIKE ?';
        $args[] = '%' . $digitos . '%';
        $cond[] = "REPLACE(REPLACE(REPLACE(REPLACE(o.contato_telefone,'(',''),')',''),'-',''),' ','') LIKE ?";
        $args[] = '%' . $digitos . '%';
    }
    $base[] = '(' . implode(' OR ', $cond) . ')';
}
if ($f['ent'] !== '') {
    $base[] = 'o.entrega = ?';
    $args[] = $f['ent'];
}
if ($f['per'] === 'hoje') {
    $base[] = 'o.criado_em >= CURDATE()';
} elseif ($f['per'] === '7d') {
    $base[] = 'o.criado_em >= NOW() - INTERVAL 7 DAY';
} elseif ($f['per'] === '30d') {
    $base[] = 'o.criado_em >= NOW() - INTERVAL 30 DAY';
} else {
    if ($f['de'] !== '') { $base[] = 'o.criado_em >= ?'; $args[] = $f['de'] . ' 00:00:00'; }
    if ($f['ate'] !== '') { $base[] = 'o.criado_em <= ?'; $args[] = $f['ate'] . ' 23:59:59'; }
}
$base_sql = $base ? implode(' AND ', $base) : '1 = 1';
$from = 'FROM orders o LEFT JOIN users u ON u.id = o.user_id';

// Lista: + pagamento + aba.
$lista_sql = $base_sql . ($f['pag'] !== '' ? ' AND ' . $PAG[$f['pag']][1] : '');
$somas = [];
foreach ($ABAS as $k => [, $cond]) {
    $somas[] = "SUM(CASE WHEN $cond THEN 1 ELSE 0 END) AS `$k`";
}
$st = db()->prepare('SELECT ' . implode(', ', $somas) . " $from WHERE $lista_sql");
$st->execute($args);
$contagem = array_map('intval', $st->fetch() ?: []);

$st = db()->prepare("SELECT o.*, u.nome AS cliente_nome $from WHERE $lista_sql AND " . $ABAS[$f['aba']][1]
                  . ' ORDER BY o.id DESC LIMIT 300');
$st->execute($args);
$lista = $st->fetchAll();

// Quadro: só pagos, nas etapas de produção, do mais antigo para o mais novo.
$st = db()->prepare("SELECT o.*, u.nome AS cliente_nome $from WHERE $base_sql
                       AND o.pagamento_status = 'aprovado'
                       AND o.status IN ('realizado','producao','embalagem','pronto','em_rota')
                     ORDER BY COALESCE(o.pago_em, o.criado_em) ASC, o.id ASC LIMIT 300");
$st->execute($args);
$quadro = $st->fetchAll();

$comp = pedidos_complementos(array_merge(array_column($lista, 'id'), array_column($quadro, 'id')));

// Alerta: cancelados com pagamento aprovado e sem estorno (independe dos filtros).
$alerta = db()->query(
    "SELECT id FROM orders WHERE status = 'cancelado' AND pagamento_status = 'aprovado'
        AND (estorno_status IS NULL OR estorno_status IN ('pendente','falhou')) ORDER BY id DESC"
)->fetchAll(PDO::FETCH_COLUMN);

/** Querystring da lista com os filtros atuais (+ mudanças). */
$qs = function (array $mudar = []) use ($f): string {
    $v = array_filter(array_merge($f, $mudar), fn ($x) => $x !== '' && $x !== null);
    if (($v['per'] ?? '') !== 'custom') { unset($v['de'], $v['ate']); }
    if (($v['aba'] ?? '') === 'ativos') { unset($v['aba']); }
    if (($v['per'] ?? '') === '30d') { unset($v['per']); }
    return http_build_query($v);
};
$voltar_qs = $qs();
$resumo = fn (int $id) => implode(', ', array_map(fn ($i) => (int) $i['quantidade'] . '× ' . $i['nome'], $comp['itens'][$id] ?? []));

/** Botão "próximo passo" (form) ou o texto de espera. */
$proximo_html = function (array $p) use ($voltar_qs): string {
    if (pedido_estorno_pendente($p)) {
        return '<a class="ap-btn ap-btn-perigo" href="' . e(url('admin/pedidos/' . (int) $p['id'])) . '" data-ap-abrir="' . (int) $p['id'] . '">Revisar estorno</a>';
    }
    $prox = pedido_proximo($p);
    if ($prox) {
        return '<form method="post" action="' . e(url('admin/pedidos')) . '" data-ap-acao>' . csrf_input()
            . '<input type="hidden" name="op" value="etapa"><input type="hidden" name="id" value="' . (int) $p['id'] . '">'
            . '<input type="hidden" name="de" value="' . e($p['status']) . '"><input type="hidden" name="para" value="' . e($prox[0]) . '">'
            . '<input type="hidden" name="voltar" value="' . e($voltar_qs) . '">'
            . '<button type="submit" class="ap-btn ap-btn-primario">' . e($prox[1]) . '</button></form>';
    }
    if ($p['status'] === 'aguardando_pagamento') {
        $t = in_array($p['pagamento_status'], ['recusado', 'cancelado'], true)
            ? 'Pagamento não aprovado, aguardando nova tentativa' : 'Aguardando o cliente pagar';
        return '<span class="ap-espera">' . e($t) . '</span>';
    }
    return '<span class="ap-espera">—</span>';
};

$entrega_txt = fn (array $p) => $p['entrega'] === 'motoboy'
    ? 'Motoboy' . (!empty($p['entrega_distancia_km']) ? ' · ' . number_format((float) $p['entrega_distancia_km'], 1, ',', '') . ' km' : '')
    : 'Retirada';

$COLUNAS = [
    'realizado' => 'Novos · pagos', 'producao' => 'Em produção', 'embalagem' => 'Embalagem',
    'pronto' => 'Prontos', 'em_rota' => 'Em rota de entrega',
];

// Seletor Lista | Quadro (ao lado do título).
$titulo_acoes = '<div class="ap-modo" role="group" aria-label="Modo de visualização">'
    . '<button type="button" data-ap-modo="lista" aria-pressed="true">Lista</button>'
    . '<button type="button" data-ap-modo="quadro" aria-pressed="false">Quadro de produção</button></div>';

ob_start();
?>
<div class="ap" data-ap data-modo="lista" data-abrir="<?= $abrir ?>"
     data-url-painel="<?= e(url('admin/pedidos')) ?>" data-voltar="<?= e($voltar_qs) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <script>
        // Aplica o modo salvo antes de desenhar (evita piscar a lista).
        try { if (localStorage.getItem('ap-modo') === 'quadro') { document.currentScript.parentNode.setAttribute('data-modo', 'quadro'); } } catch (e) {}
    </script>

    <?php if ($alerta): ?>
        <div class="ap-alerta" role="status">
            <span><?= count($alerta) === 1
                ? 'O pedido #' . (int) $alerta[0] . ' foi cancelado, mas o pagamento está aprovado. A cliente ainda não recebeu o dinheiro de volta.'
                : count($alerta) . ' pedidos cancelados estão com pagamento aprovado e sem estorno (#' . implode(', #', array_map('intval', $alerta)) . ').' ?></span>
            <a class="ap-btn ap-btn-perigo" href="<?= e(url('admin/pedidos/' . (int) $alerta[0])) ?>" data-ap-abrir="<?= (int) $alerta[0] ?>">Revisar</a>
        </div>
    <?php endif; ?>

    <!-- Abas (só na lista) -->
    <nav class="ap-abas" aria-label="Filtrar por status">
        <?php foreach ($ABAS as $k => [$rot]): ?>
            <a class="ap-aba" href="<?= e(url('admin/pedidos') . '?' . $qs(['aba' => $k])) ?>" <?= $f['aba'] === $k ? 'aria-current="page"' : '' ?>>
                <?= e($rot) ?> <span class="ap-count"><?= (int) ($contagem[$k] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </nav>

    <form class="ap-filtros" method="get" action="<?= e(url('admin/pedidos')) ?>" data-ap-filtros>
        <label class="ap-campo ap-so-lista ap-aba-select" for="ap-aba">Status
            <select id="ap-aba" name="aba" data-ap-auto>
                <?php foreach ($ABAS as $k => [$rot]): ?>
                    <option value="<?= e($k) ?>" <?= $f['aba'] === $k ? 'selected' : '' ?>><?= e($rot) ?> (<?= (int) ($contagem[$k] ?? 0) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="ap-campo ap-busca" for="ap-q">Buscar
            <input id="ap-q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nº do pedido, cliente ou telefone" data-ap-busca>
        </label>
        <label class="ap-campo ap-so-lista" for="ap-pag">Pagamento
            <select id="ap-pag" name="pag" data-ap-auto>
                <option value="">Todos</option>
                <?php foreach ($PAG as $k => [$rot]): ?>
                    <option value="<?= e($k) ?>" <?= $f['pag'] === $k ? 'selected' : '' ?>><?= e($rot) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="ap-campo" for="ap-ent">Entrega
            <select id="ap-ent" name="ent" data-ap-auto>
                <option value="">Todas</option>
                <option value="motoboy" <?= $f['ent'] === 'motoboy' ? 'selected' : '' ?>>Motoboy</option>
                <option value="retirada" <?= $f['ent'] === 'retirada' ? 'selected' : '' ?>>Retirada</option>
            </select>
        </label>
        <label class="ap-campo" for="ap-per">Período
            <select id="ap-per" name="per" data-ap-auto>
                <?php foreach ($PERIODOS as $k => $rot): ?>
                    <option value="<?= e($k) ?>" <?= $f['per'] === $k ? 'selected' : '' ?>><?= e($rot) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($f['per'] === 'custom'): ?>
            <label class="ap-campo" for="ap-de">De <input id="ap-de" type="date" name="de" value="<?= e($f['de']) ?>" data-ap-auto></label>
            <label class="ap-campo" for="ap-ate">Até <input id="ap-ate" type="date" name="ate" value="<?= e($f['ate']) ?>" data-ap-auto></label>
        <?php endif; ?>
        <a class="ap-limpar" href="<?= e(url('admin/pedidos')) ?>">Limpar filtros</a>
        <noscript><button class="ap-btn ap-btn-linha" type="submit">Filtrar</button></noscript>
    </form>

    <!-- LISTA -->
    <section class="ap-lista" aria-label="Lista de pedidos">
        <?php if (!$lista): ?>
            <div class="ap-tabela"><p class="ap-vazio">Nenhum pedido com esses filtros.</p></div>
        <?php else: ?>
            <div class="ap-tabela">
                <table>
                    <thead>
                        <tr><th>#</th><th>Cliente</th><th>Feito em</th><th>Entrega</th><th>Pagamento</th>
                            <th>Status</th><th class="ap-dir">Total</th><th>Próximo passo</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lista as $p): $pid = (int) $p['id']; [$prot, $pcls] = pagamento_etiqueta_admin($p);
                            $pend = pedido_estorno_pendente($p); $ent = $p['entrega'] === 'motoboy' ? 'motoboy' : 'retirada'; ?>
                            <tr class="<?= $pend ? 'is-flag' : '' ?>" data-ap-linha="<?= $pid ?>">
                                <td class="c-id"><a href="<?= e(url('admin/pedidos/' . $pid)) ?>" data-ap-abrir="<?= $pid ?>">#<?= $pid ?></a></td>
                                <td class="c-who"><b><?= e($p['cliente_nome'] ?: ($p['contato_nome'] ?: '—')) ?></b>
                                    <span><?= e($resumo($pid)) ?></span></td>
                                <td class="c-date ap-num"><?= e(date('d/m · H:i', strtotime($p['criado_em']))) ?><span class="m-only"> · <?= e($ent === 'motoboy' ? 'Motoboy' : 'Retirada') ?></span></td>
                                <td class="c-del"><?= e($entrega_txt($p)) ?></td>
                                <td class="c-pag"><span class="ap-pill <?= e($pcls) ?>"><?= e($prot) ?></span></td>
                                <td class="c-st"><span class="ap-pill <?= e(pedido_status_classe($p['status'])) ?>"><?= e(pedido_status_rotulo($p['status'], $ent)) ?></span>
                                    <?php if ($pend): ?><span class="ap-flagtxt">Estorno pendente</span><?php endif; ?></td>
                                <td class="c-tot ap-dir ap-num"><b><?= e(money((int) $p['total_centavos'])) ?></b></td>
                                <td class="c-act"><?= $proximo_html($p) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- QUADRO DE PRODUÇÃO -->
    <section class="ap-quadro-wrap" aria-label="Quadro de produção">
        <p class="ap-sub">Em cada coluna, o pedido mais antigo aparece primeiro. Só entram pedidos pagos.</p>
        <div class="ap-quadro">
            <?php foreach ($COLUNAS as $st_col => $rot): $itens_col = array_filter($quadro, fn ($p) => $p['status'] === $st_col); ?>
                <section class="ap-col" aria-label="<?= e($rot) ?>">
                    <h2><?= e($rot) ?> <span class="ap-count"><?= count($itens_col) ?></span></h2>
                    <?php foreach ($itens_col as $p): $pid = (int) $p['id']; $prox = pedido_proximo($p); ?>
                        <article class="ap-card">
                            <div class="ap-card-top">
                                <a href="<?= e(url('admin/pedidos/' . $pid)) ?>" data-ap-abrir="<?= $pid ?>">#<?= $pid ?> · <?= e(strtok((string) ($p['cliente_nome'] ?: $p['contato_nome'] ?: '—'), ' ')) ?></a>
                                <span class="ap-num"><?= e(money((int) $p['total_centavos'])) ?></span>
                            </div>
                            <span class="ap-sub"><?= e($resumo($pid)) ?></span>
                            <span class="ap-sub"><?= e($p['entrega'] === 'motoboy' ? 'Motoboy' : 'Retirada') ?> · <?= e(date('d/m H:i', strtotime($p['criado_em']))) ?></span>
                            <?php if ($p['status'] === 'pronto' && $p['entrega'] === 'retirada'): ?>
                                <span class="ap-pill ap-p-rota">Aguardando retirada</span>
                            <?php endif; ?>
                            <?php if (!empty($p['presente'])): ?><span class="ap-pill ap-p-emb">Presente</span><?php endif; ?>
                            <?php if ($prox): ?><?= $proximo_html($p) ?><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    <?php if (!$itens_col): ?><p class="ap-sub ap-col-vazia">Nenhum pedido.</p><?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Painel lateral (conteúdo via fetch) -->
    <div class="ap-scrim" data-ap-scrim hidden></div>
    <aside class="ap-drawer" data-ap-drawer role="dialog" aria-modal="true" aria-labelledby="ap-painel-titulo" hidden>
        <div data-ap-drawer-corpo></div>
    </aside>
    <div class="ap-toast" data-ap-toast role="status" hidden></div>
</div>
<script src="<?= e(asset('assets/js/admin-pedidos.js')) ?>"></script>
<?php
view('admin_layout', ['titulo' => 'Pedidos', 'conteudo' => ob_get_clean(), 'titulo_acoes' => $titulo_acoes, 'layout_largo' => true]);
