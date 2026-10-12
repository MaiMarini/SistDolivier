<?php
/**
 * Admin: Coleções — lista com edição na própria linha (mesmo padrão de Categorias).
 * Uma coleção é um grupo de produtos dentro de uma categoria (ex.: "Dia das Mães
 * 2027" em Coleções Sazonais) com a mesma data limite para encomendar e a mesma
 * data de entrega/retirada. O produto entra na coleção pelo formulário do produto.
 * POST (fetch, JSON, CSRF):
 *   op=ordem     ids[] na nova ordem (todas) -> regrava 1..N numa transação
 *   op=ativo     id, ativo=0|1 (Ligada / Desligada)
 *   op=salvar    id (0 = nova), nome, category_id, encomendas_ate, entrega_em, observacao, ativo,
 *                capa_tmp (enviada pelo op=capa), capa_remover=1, capa_alt
 *   op=capa      (multipart) arquivo -> sobe a capa para assets/uploads/tmp; só vira
 *                definitiva no "Salvar" (a antiga é apagada se nada mais a usa)
 *   op=excluir   id -> exclusão lógica; os produtos saem da coleção (voltam ao prazo
 *                de produção); guarda quais eram para o "Desfazer"
 *   op=desfazer  id -> restaura a coleção, a posição e os produtos
 */
exigir_admin();

$BASE = 'admin/colecoes';
const COL_TMP_HORAS = 24;

/** A migração rodou? (a página avisa em vez de quebrar) */
function _col_tem_tabela(): bool
{
    try {
        db()->query('SELECT 1 FROM colecoes LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** A migração da capa (migracao_colecoes_capa.sql) rodou? */
function _col_tem_capa(): bool
{
    static $tem = null;
    if ($tem === null) {
        try {
            db()->query('SELECT abre_em, capa, capa_alt, slug FROM colecoes LIMIT 1');
            $tem = true;
        } catch (PDOException $e) {
            $tem = false;
        }
    }
    return $tem;
}

/** Pasta dos uploads temporários (a mesma de Admin › Home). */
function _col_tmp_dir(): string
{
    $dir = imagem_dir_uploads() . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Linhas não excluídas na ordem, com a categoria e o total de produtos. */
function _col_lista(): array
{
    $capa = _col_tem_capa() ? 'o.abre_em, o.capa, o.capa_alt' : 'NULL AS abre_em, NULL AS capa, NULL AS capa_alt';
    return db()->query(
        "SELECT o.id, o.nome, o.category_id, o.encomendas_ate, o.entrega_em, o.observacao, o.ativo, $capa,
                c.nome AS categoria, c.excluida_em AS cat_excluida, COUNT(p.id) AS n
           FROM colecoes o
           LEFT JOIN categories c ON c.id = o.category_id
           LEFT JOIN products p ON p.colecao_id = o.id
          WHERE o.excluida_em IS NULL
          GROUP BY o.id, o.nome, o.category_id, o.encomendas_ate, o.entrega_em, o.observacao, o.ativo, o.ordem, c.nome, c.excluida_em"
          . (_col_tem_capa() ? ', o.abre_em, o.capa, o.capa_alt' : '') . '
          ORDER BY o.ordem ASC, o.id ASC'
    )->fetchAll();
}

/** Regrava a ordem 1..N das coleções não excluídas (na ordem atual). */
function _col_renumerar(): void
{
    $ids = db()->query('SELECT id FROM colecoes WHERE excluida_em IS NULL ORDER BY ordem ASC, id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $up = db()->prepare('UPDATE colecoes SET ordem = ? WHERE id = ?');
    foreach ($ids as $i => $id) {
        $up->execute([$i + 1, (int) $id]);
    }
}

/** "Hoje" da loja (Rondônia). */
function _col_hoje(): string
{
    return colecao_hoje();
}

/** Uma linha da lista. */
function _col_linha_html(array $o, int $pos, int $total, string $hoje): string
{
    $id = (int) $o['id'];
    $n = (int) $o['n'];
    $ativo = (int) $o['ativo'] === 1;
    $nome = (string) $o['nome'];
    $encerrada = (string) $o['encomendas_ate'] < $hoje;
    $categoria = $o['categoria'] === null || $o['cat_excluida'] !== null ? 'Categoria excluída' : (string) $o['categoria'];
    ob_start();
    ?>
    <div class="ct-row co-row<?= $ativo ? '' : ' is-oculta' ?>" data-ct-linha data-id="<?= $id ?>" data-nome="<?= e($nome) ?>"
         data-cat="<?= (int) $o['category_id'] ?>" data-ate="<?= e($o['encomendas_ate']) ?>" data-entrega="<?= e($o['entrega_em']) ?>"
         data-obs="<?= e((string) $o['observacao']) ?>" data-ativo="<?= $ativo ? 1 : 0 ?>" data-n="<?= $n ?>"
         data-capa="<?= (string) $o['capa'] !== '' ? e(url('assets/uploads/' . $o['capa'])) : '' ?>" data-capa-alt="<?= e((string) $o['capa_alt']) ?>" data-abre="<?= e((string) $o['abre_em']) ?>">
        <span class="ct-alca" data-ct-alca title="Arraste para reordenar" aria-hidden="true">⠿</span>
        <span class="ct-setas">
            <button type="button" data-ct-mover="-1" aria-label="<?= e('Subir ' . $nome) ?>" <?= $pos === 0 ? 'disabled' : '' ?>>↑</button>
            <button type="button" data-ct-mover="1" aria-label="<?= e('Descer ' . $nome) ?>" <?= $pos === $total - 1 ? 'disabled' : '' ?>>↓</button>
        </span>
        <span class="ct-nome"><span><span data-ct-nome><?= e($nome) ?></span>
            <?php if ($encerrada): ?><span class="ct-tag co-tag-encerrada">Encomendas encerradas</span><?php elseif ($ativo && !$n): ?><span class="ct-tag">Vazia</span><?php endif; ?>
            <small class="co-cat"><?= e($categoria) ?></small></span></span>
        <span class="co-datas ap-num"><?php if ((string) $o['abre_em'] > $hoje): ?><span>Começa em <b><?= e(data_br($o['abre_em'])) ?></b></span><?php endif; ?><span>Encomendas até <b><?= e(data_br($o['encomendas_ate'])) ?></b></span>
            <span>Entrega/retirada em <b><?= e(data_br($o['entrega_em'])) ?></b></span></span>
        <span class="ct-prod"><?php if ($n): ?><a href="<?= e(url('admin/produtos') . '?' . http_build_query(['st' => 'todos', 'cat' => (int) $o['category_id']])) ?>"><?= $n === 1 ? '1 produto' : $n . ' produtos' ?></a><?php else: ?>Nenhum produto<?php endif; ?></span>
        <span class="ct-chave">
            <button type="button" class="pr-sw" role="switch" aria-checked="<?= $ativo ? 'true' : 'false' ?>" data-ct-ativo
                    aria-label="<?= e('Coleção ligada: ' . $nome) ?>"><span class="pr-sw-trilho" aria-hidden="true"></span><span data-ct-ativo-txt><?= $ativo ? 'Ligada' : 'Desligada' ?></span></button>
        </span>
        <span class="ct-acao"><button type="button" class="ap-btn ap-btn-linha" data-ct-editar aria-expanded="false">Editar</button></span>
        <div class="ct-inline" data-ct-inline hidden></div>
    </div>
    <?php
    return ob_get_clean();
}

function _col_linhas_html(): string
{
    $lista = _col_lista();
    $hoje = _col_hoje();
    $html = '';
    foreach ($lista as $i => $o) {
        $html .= _col_linha_html($o, $i, count($lista), $hoje);
    }
    return $html;
}

// =============================================================================
// POST (fetch/JSON)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $responder = function (int $http, array $d): void {
        http_response_code($http);
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
    };
    if (!csrf_validar()) {
        $responder(403, ['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.']);
        return;
    }
    if (!_col_tem_tabela()) {
        $responder(500, ['ok' => false, 'mensagem' => 'Rode a migração database/migracao_colecoes.sql antes de usar Coleções.']);
        return;
    }
    $op = $_POST['op'] ?? '';
    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $id = $id === false ? -1 : (int) $id;
    $buscar = function (int $id): ?array {
        $st = db()->prepare('SELECT * FROM colecoes WHERE id = ? AND excluida_em IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    };

    // ------------------------------------------------------- CAPA (temporária)
    if ($op === 'capa') {
        if (!_col_tem_capa()) {
            $responder(500, ['ok' => false, 'mensagem' => 'Rode a migração database/migracao_colecoes_capa.sql antes de enviar a capa.']);
            return;
        }
        $dir = _col_tmp_dir();
        foreach (glob($dir . '/*') ?: [] as $f) {   // temporários abandonados
            if (is_file($f) && filemtime($f) < time() - COL_TMP_HORAS * 3600) {
                @unlink($f);
            }
        }
        $arq = $_FILES['arquivo'] ?? null;
        if (!is_array($arq) || is_array($arq['error'] ?? null)) {
            $responder(400, ['ok' => false, 'mensagem' => 'Envio de arquivo inválido.']);
            return;
        }
        $err = (int) ($arq['error'] ?? 0);
        $res = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
            ? ['ok' => false, 'erro' => 'A imagem excede o tamanho máximo de 5 MB.']
            : processar_upload_imagem($arq, ['destino' => $dir, 'gerar_miniatura' => true]);
        if (empty($res['ok'])) {
            $responder(422, ['ok' => false, 'mensagem' => $res['erro'] ?? 'Não foi possível enviar a capa.']);
            return;
        }
        $_SESSION['col_tmp'][$res['arquivo']] = time();
        $responder(200, ['ok' => true, 'tmp' => $res['arquivo'], 'url' => url('assets/uploads/tmp/' . $res['arquivo'])]);
        return;
    }

    // ------------------------------------------------------------------ ORDEM
    if ($op === 'ordem') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $atuais = array_map('intval', db()->query('SELECT id FROM colecoes WHERE excluida_em IS NULL')->fetchAll(PDO::FETCH_COLUMN));
        $a = $ids; $b = $atuais; sort($a); sort($b);
        if ($a !== $b) {
            $responder(422, ['ok' => false, 'mensagem' => 'A lista mudou em outra tela. Recarregue a página.']);
            return;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare('UPDATE colecoes SET ordem = ? WHERE id = ?');
            foreach ($ids as $i => $cid) {
                $up->execute([$i + 1, $cid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $responder(500, ['ok' => false]);
            return;
        }
        $responder(200, ['ok' => true]);
        return;
    }

    // ------------------------------------------------------------- LIGADA
    if ($op === 'ativo') {
        $ativo = $_POST['ativo'] ?? '';
        $col = $id > 0 ? $buscar($id) : null;
        if (!$col || !in_array($ativo, ['0', '1'], true)) {
            $responder($col ? 422 : 404, ['ok' => false]);
            return;
        }
        db()->prepare('UPDATE colecoes SET ativo = ? WHERE id = ?')->execute([(int) $ativo, $id]);
        $responder(200, ['ok' => true]);
        return;
    }

    // ----------------------------------------------------------------- SALVAR
    if ($op === 'salvar') {
        $col = $id > 0 ? $buscar($id) : null;
        if ($id !== 0 && !$col) {
            $responder(404, ['ok' => false, 'mensagem' => 'Coleção não encontrada.']);
            return;
        }
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $cat = (int) ($_POST['category_id'] ?? 0);
        $ate = (string) ($_POST['encomendas_ate'] ?? '');
        $entrega = (string) ($_POST['entrega_em'] ?? '');
        $abre = (string) ($_POST['abre_em'] ?? '');   // início da campanha (opcional)
        $obs = trim(str_replace("\r\n", "\n", (string) ($_POST['observacao'] ?? '')));
        $ativo = ($_POST['ativo'] ?? '1') === '0' ? 0 : 1;
        $data = function (string $v): ?string {
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            return $d && $d->format('Y-m-d') === $v ? $v : null;
        };
        $erros = [];
        if (mb_strlen($nome) < 2) {
            $erros['nome'] = 'Informe o nome da coleção.';
        } elseif (mb_strlen($nome) > 150) {
            $erros['nome'] = 'Use no máximo 150 caracteres.';
        } else {
            $st = db()->prepare('SELECT 1 FROM colecoes WHERE LOWER(TRIM(nome)) = LOWER(?) AND id <> ? AND excluida_em IS NULL');
            $st->execute([$nome, $id]);
            if ($st->fetchColumn()) {
                $erros['nome'] = 'Já existe uma coleção com esse nome.';
            }
        }
        // Com produtos, a categoria não muda (os produtos são dela).
        $n = 0;
        if ($col) {
            $st = db()->prepare('SELECT COUNT(*) FROM products WHERE colecao_id = ?');
            $st->execute([$id]);
            $n = (int) $st->fetchColumn();
        }
        if ($col && $n > 0) {
            $cat = (int) $col['category_id'];
        } elseif ($cat <= 0 || !categoria_valida($cat)) {
            $erros['category_id'] = 'Escolha a categoria.';
        }
        if ($data($ate) === null) {
            $erros['encomendas_ate'] = 'Informe a data limite para encomendar.';
        }
        if ($data($entrega) === null) {
            $erros['entrega_em'] = 'Informe a data de entrega/retirada.';
        } elseif ($data($ate) !== null && $entrega < $ate) {
            $erros['entrega_em'] = 'A entrega não pode ser antes da data limite para encomendar.';
        }
        if ($abre !== '' && $data($abre) === null) {
            $erros['abre_em'] = 'Data inválida.';
        } elseif ($abre !== '' && $data($ate) !== null && $abre > $ate) {
            $erros['abre_em'] = 'O início da campanha não pode ser depois da data limite para encomendar.';
        }
        if (mb_strlen($obs) > 500) {
            $erros['observacao'] = 'Use no máximo 500 caracteres.';
        }
        // Capa: temporária enviada nesta sessão pelo op=capa; ou remover; ou fica como está.
        $temCapa = _col_tem_capa();
        $capaTmp = basename((string) ($_POST['capa_tmp'] ?? ''));
        $capaRemover = ($_POST['capa_remover'] ?? '') === '1';
        $capaAlt = trim((string) ($_POST['capa_alt'] ?? ''));
        if ($capaTmp !== '' && (!$temCapa || !isset($_SESSION['col_tmp'][$capaTmp]) || !is_file(_col_tmp_dir() . '/' . $capaTmp))) {
            $erros['capa'] = 'A imagem enviada expirou. Escolha a capa de novo.';
        }
        if (mb_strlen($capaAlt) > 150) {
            $erros['capa_alt'] = 'Use no máximo 150 caracteres.';
        }
        if ($erros) {
            $responder(422, ['ok' => false, 'erros' => $erros, 'mensagem' => 'Confira os campos destacados.']);
            return;
        }
        $capaAntiga = $col && $temCapa ? (string) $col['capa'] : '';
        $capaNova = $capaAntiga;
        if ($capaTmp !== '') {
            // Temporária -> definitiva (com a miniatura) antes de gravar.
            $up = imagem_dir_uploads();
            $thumb = preg_replace('/\.jpg$/i', '-thumb.jpg', $capaTmp);
            if (!@rename(_col_tmp_dir() . '/' . $capaTmp, $up . '/' . $capaTmp)) {
                $responder(500, ['ok' => false, 'mensagem' => 'Não foi possível salvar a capa. Tente de novo.']);
                return;
            }
            if ($thumb !== $capaTmp && is_file(_col_tmp_dir() . '/' . $thumb)) {
                @rename(_col_tmp_dir() . '/' . $thumb, $up . '/' . $thumb);
            }
            unset($_SESSION['col_tmp'][$capaTmp]);
            $capaNova = $capaTmp;
        } elseif ($capaRemover) {
            $capaNova = '';
        }
        if ($col) {
            db()->prepare('UPDATE colecoes SET nome = ?, category_id = ?, encomendas_ate = ?, entrega_em = ?, observacao = ?, ativo = ? WHERE id = ?')
                ->execute([$nome, $cat, $ate, $entrega, $obs !== '' ? $obs : null, $ativo, $id]);
            $msg = 'Coleção salva.';
        } else {
            $ordem = (int) db()->query('SELECT COALESCE(MAX(ordem), 0) + 1 FROM colecoes WHERE excluida_em IS NULL')->fetchColumn();
            db()->prepare('INSERT INTO colecoes (category_id, nome, encomendas_ate, entrega_em, observacao, ordem, ativo) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cat, $nome, $ate, $entrega, $obs !== '' ? $obs : null, $ordem, $ativo]);
            $id = (int) db()->lastInsertId();
            $msg = 'Coleção criada. Agora ligue os produtos a ela no formulário de cada produto (campo “Coleção”).';
        }
        if ($temCapa) {
            // Endereço na loja: muda junto com o nome (ou na primeira vez).
            $slug = $col && (string) $col['slug'] !== '' && $col['nome'] === $nome && (int) $col['category_id'] === $cat
                ? (string) $col['slug'] : colecao_slug_unico($nome, $cat, $id);
            db()->prepare('UPDATE colecoes SET abre_em = ?, capa = ?, capa_alt = ?, slug = ? WHERE id = ?')
                ->execute([$abre !== '' ? $abre : null, $capaNova !== '' ? $capaNova : null, $capaAlt !== '' ? $capaAlt : null, $slug, $id]);
            if ($capaAntiga !== '' && $capaAntiga !== $capaNova) {
                colecao_capa_apagar($capaAntiga, $id);
            }
        }
        if ($ate < _col_hoje()) {
            $msg .= ' A data limite já passou: as encomendas desta coleção estão encerradas.';
        }
        $responder(200, ['ok' => true, 'id' => $id, 'novo' => !$col, 'mensagem' => $msg, 'linhas' => _col_linhas_html()]);
        return;
    }

    // ---------------------------------------------------------------- EXCLUIR
    if ($op === 'excluir') {
        $col = $id > 0 ? $buscar($id) : null;
        if (!$col) {
            $responder(404, ['ok' => false, 'mensagem' => 'Coleção não encontrada.']);
            return;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id FROM products WHERE colecao_id = ? FOR UPDATE');
            $st->execute([$id]);
            $produtos = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $pdo->prepare('UPDATE products SET colecao_id = NULL WHERE colecao_id = ?')->execute([$id]);
            $info = json_encode(['ativo' => (int) $col['ativo'], 'ordem' => (int) $col['ordem'], 'produtos' => $produtos]);
            $pdo->prepare('UPDATE colecoes SET excluida_em = NOW(), ativo = 0, exclusao_info = ? WHERE id = ?')->execute([$info, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $responder(500, ['ok' => false, 'mensagem' => 'Não foi possível excluir. Tente de novo.']);
            return;
        }
        $n = count($produtos);
        $msg = '“' . $col['nome'] . '” excluída.'
            . ($n ? ' ' . ($n === 1 ? '1 produto saiu' : $n . ' produtos saíram') . ' da coleção e ' . ($n === 1 ? 'voltou' : 'voltaram') . ' ao prazo de produção.' : '');
        $responder(200, ['ok' => true, 'id' => $id, 'mensagem' => $msg, 'linhas' => _col_linhas_html()]);
        return;
    }

    // --------------------------------------------------------------- DESFAZER
    if ($op === 'desfazer') {
        $st = db()->prepare('SELECT * FROM colecoes WHERE id = ? AND excluida_em IS NOT NULL');
        $st->execute([$id]);
        $col = $st->fetch();
        $info = $col ? json_decode((string) $col['exclusao_info'], true) : null;
        if (!$col || !is_array($info)) {
            $responder(404, ['ok' => false, 'mensagem' => 'Não foi possível desfazer.']);
            return;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE colecoes SET excluida_em = NULL, exclusao_info = NULL, ativo = ?, ordem = ? WHERE id = ?')
                ->execute([(int) $info['ativo'], (int) $info['ordem'], $id]);
            // Devolve só os produtos que continuam sem coleção e na mesma categoria.
            $volta = $pdo->prepare('UPDATE products SET colecao_id = ? WHERE id = ? AND colecao_id IS NULL AND category_id = ?');
            foreach ((array) $info['produtos'] as $pid) {
                $volta->execute([$id, (int) $pid, (int) $col['category_id']]);
            }
            _col_renumerar();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $responder(500, ['ok' => false, 'mensagem' => 'Não foi possível desfazer.']);
            return;
        }
        $responder(200, ['ok' => true, 'mensagem' => '“' . $col['nome'] . '” voltou.', 'linhas' => _col_linhas_html()]);
        return;
    }

    $responder(400, ['ok' => false, 'mensagem' => 'Ação desconhecida.']);
    return;
}

// =============================================================================
// GET
// =============================================================================
if (!_col_tem_tabela()) {
    ob_start();
    ?>
    <section class="pe-card"><h2>Falta atualizar o banco</h2>
        <p>Rode o arquivo <code>database/migracao_colecoes.sql</code> no phpMyAdmin e recarregue esta página.</p></section>
    <?php
    view('admin_layout', ['titulo' => 'Coleções', 'conteudo' => ob_get_clean(), 'layout_largo' => true]);
    return;
}

// Limpeza definitiva das excluídas há mais de 30 dias (e das capas que só elas usavam).
$velhas = _col_tem_capa()
    ? db()->query("SELECT id, capa FROM colecoes WHERE excluida_em < NOW() - INTERVAL 30 DAY AND capa IS NOT NULL AND capa <> ''")->fetchAll()
    : [];
db()->exec('DELETE FROM colecoes WHERE excluida_em < NOW() - INTERVAL 30 DAY');
foreach ($velhas as $v) {
    colecao_capa_apagar((string) $v['capa'], (int) $v['id']);
}

$categorias = categorias_admin();
// Categoria sugerida para coleção nova: a que tem "sazona" no nome; senão, a primeira.
$cat_padrao = 0;
foreach ($categorias as $c) {
    if (stripos(gerar_slug((string) $c['nome']), 'sazona') !== false) {
        $cat_padrao = (int) $c['id'];
        break;
    }
}
$cat_padrao = $cat_padrao ?: (int) ($categorias[0]['id'] ?? 0);

ob_start();
?>
<div class="ct co" data-ct data-url="<?= e(url($BASE)) ?>" data-csrf="<?= e(csrf_token()) ?>" data-hoje="<?= e(_col_hoje()) ?>" data-cat-padrao="<?= $cat_padrao ?>">
    <p class="pe-ajuda ct-ajuda">Cada coleção junta produtos de uma categoria com a mesma data limite para encomendar e a mesma data de entrega/retirada
        (ex.: “Dia das Mães 2027” em Coleções Sazonais). O produto entra na coleção pelo campo “Coleção” no formulário do produto; sem coleção, vale o prazo de produção dele.</p>

    <div class="ct-lista" data-ct-lista>
        <div class="ct-row co-row ct-head" aria-hidden="true"><span></span><span>Ordem</span><span>Nome</span><span>Datas</span><span>Produtos</span><span>Situação</span><span></span></div>
        <div class="ct-nova" data-ct-nova hidden><h2>Nova coleção</h2><div data-ct-nova-form></div></div>
        <div data-ct-linhas><?= _col_linhas_html() ?></div>
        <p class="ct-sem" data-ct-sem hidden>Nenhuma coleção ainda. Use “+ Nova coleção”.</p>
    </div>

    <template data-ct-modelo>
        <form class="ct-form co-form" novalidate>
            <p class="legenda-obrigatorio"><span aria-hidden="true">*</span> Campos obrigatórios</p>
            <div class="pe-campo">
                <label for="co-f-nome" class="rotulo-obrigatorio">Nome</label>
                <input id="co-f-nome" name="nome" type="text" maxlength="150" required autocomplete="off" placeholder="Ex.: Dia das Mães 2027"
                       aria-describedby="co-f-nome-dica co-f-nome-erro">
                <span class="pe-dica" id="co-f-nome-dica" data-ct-dup hidden>Já existe uma coleção com esse nome.</span>
                <span class="pe-erro" id="co-f-nome-erro" data-ct-erro="nome" role="alert" hidden></span>
            </div>
            <div class="pe-campo">
                <label for="co-f-cat" class="rotulo-obrigatorio">Categoria</label>
                <select id="co-f-cat" name="category_id" required aria-describedby="co-f-cat-ajuda co-f-cat-erro">
                    <?php foreach ($categorias as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="pe-ajuda" id="co-f-cat-ajuda" data-co-cat-ajuda>Os produtos da coleção são desta categoria.</span>
                <span class="pe-erro" id="co-f-cat-erro" data-ct-erro="category_id" role="alert" hidden></span>
            </div>
            <div class="pe-grid2 co-grid-datas">
                <?php if (_col_tem_capa()): ?>
                <div class="pe-campo">
                    <label for="co-f-abre">Início da campanha <span class="pe-ajuda">(opcional)</span></label>
                    <input id="co-f-abre" name="abre_em" type="date" aria-describedby="co-f-abre-ajuda co-f-abre-erro">
                    <span class="pe-ajuda" id="co-f-abre-ajuda">Até esse dia a loja mostra “Em breve” e não vende. Vazio: abre ao salvar.</span>
                    <span class="pe-erro" id="co-f-abre-erro" data-ct-erro="abre_em" role="alert" hidden></span>
                </div>
                <?php endif; ?>
                <div class="pe-campo">
                    <label for="co-f-ate" class="rotulo-obrigatorio">Encomendas até</label>
                    <input id="co-f-ate" name="encomendas_ate" type="date" required aria-describedby="co-f-ate-ajuda co-f-ate-erro">
                    <span class="pe-ajuda" id="co-f-ate-ajuda">Último dia para encomendar.</span>
                    <span class="pe-erro" id="co-f-ate-erro" data-ct-erro="encomendas_ate" role="alert" hidden></span>
                </div>
                <div class="pe-campo">
                    <label for="co-f-entrega" class="rotulo-obrigatorio">Entrega/retirada em</label>
                    <input id="co-f-entrega" name="entrega_em" type="date" required aria-describedby="co-f-entrega-erro">
                    <span class="pe-erro" id="co-f-entrega-erro" data-ct-erro="entrega_em" role="alert" hidden></span>
                </div>
            </div>
            <p class="pe-aviso" data-co-passou hidden>A data limite já passou: as encomendas desta coleção ficam encerradas.</p>
            <div class="pe-campo">
                <label for="co-f-obs">Observação de entrega <span class="pe-ajuda">(opcional)</span></label>
                <textarea id="co-f-obs" name="observacao" rows="2" maxlength="500" placeholder="Ex.: Retirada das 8h às 12h. Entregas por motoboy à tarde."
                          aria-describedby="co-f-obs-erro"></textarea>
                <span class="pe-erro" id="co-f-obs-erro" data-ct-erro="observacao" role="alert" hidden></span>
            </div>
            <?php if (_col_tem_capa()): ?>
            <div class="pe-campo co-capa" role="group" aria-labelledby="co-f-capa-rot">
                <span class="co-capa-rot" id="co-f-capa-rot">Capa <span class="pe-ajuda">(opcional)</span></span>
                <div class="co-capa-linha">
                    <div class="co-capa-previa">
                        <b class="co-capa-titulo">Prévia</b>
                        <div class="co-capa-img" data-co-capa-img>
                            <img alt="" data-co-capa-foto hidden>
                            <span class="co-capa-nome" data-co-capa-nome>Nome da coleção</span>
                            <span class="hm-enviando" data-co-capa-enviando hidden>Enviando…</span>
                        </div>
                    </div>
                    <div class="co-capa-lado">
                        <button type="button" class="ap-btn ap-btn-linha hm-btn-p" data-co-capa-escolher aria-describedby="co-f-capa-ajuda co-f-capa-erro">Escolher imagem</button>
                        <span class="pe-ajuda" id="co-f-capa-ajuda">JPG, PNG ou WebP até 5 MB. Proporção 12:5 (ex. 1200×500 px). Sem capa, a loja usa um degradê.</span>
                        <button type="button" class="co-capa-remover" data-co-capa-remover hidden>remover</button>
                        <div class="co-capa-conf" data-co-capa-conf role="group" aria-label="Confirmar remoção da capa" hidden>
                            <span>Remover a capa? Ela sai da loja ao salvar.</span>
                            <span class="co-capa-conf-bt">
                                <button type="button" class="ap-btn ap-btn-perigo-cheio hm-btn-p" data-co-capa-remover-sim>Remover</button>
                                <button type="button" class="ap-btn hm-btn-p" data-co-capa-remover-nao>Cancelar</button>
                            </span>
                        </div>
                        <span class="pe-erro" id="co-f-capa-erro" data-ct-erro="capa" role="alert" hidden></span>
                    </div>
                </div>
                <input type="file" accept="image/jpeg,image/png,image/webp" hidden data-co-capa-arq>
            </div>
            <div class="pe-campo">
                <label for="co-f-alt">Texto alternativo da capa <span class="pe-ajuda">(opcional)</span></label>
                <input id="co-f-alt" name="capa_alt" type="text" maxlength="150" autocomplete="off" aria-describedby="co-f-alt-ajuda co-f-alt-erro">
                <span class="pe-ajuda" id="co-f-alt-ajuda">Descreve a imagem para quem usa leitor de tela. Vazio, usa o nome da coleção.</span>
                <span class="pe-erro" id="co-f-alt-erro" data-ct-erro="capa_alt" role="alert" hidden></span>
            </div>
            <?php else: ?>
            <p class="pe-aviso">Para enviar uma capa, rode a migração <code>database/migracao_colecoes_capa.sql</code> no phpMyAdmin.</p>
            <?php endif; ?>
            <input type="hidden" name="ativo" value="1" data-ct-f-ativo>
            <button type="button" class="pe-tg ct-form-tg" role="switch" aria-checked="true" data-ct-f-sw aria-describedby="co-f-sw-ajuda">
                <span class="pe-tg-txt"><b>Coleção ligada</b><span id="co-f-sw-ajuda">Desligada, a coleção fica guardada para usar depois.</span></span>
                <span class="pe-tg-trilho" aria-hidden="true"></span>
            </button>
            <div class="ct-botoes">
                <button type="submit" class="ap-btn ap-btn-primario" data-ct-salvar disabled>Salvar</button>
                <button type="button" class="ap-btn" data-ct-cancelar>Cancelar</button>
            </div>
            <div class="ct-perigo" data-ct-perigo>
                <button type="button" class="ap-btn ct-excluir" data-ct-excluir>Excluir coleção</button>
                <div class="ct-confirmar" data-ct-confirmar hidden>
                    <b data-ct-conf-titulo></b>
                    <div class="ct-botoes">
                        <button type="button" class="ap-btn ap-btn-perigo-cheio" data-ct-excluir-sim>Sim, excluir</button>
                        <button type="button" class="ap-btn" data-ct-excluir-nao>Cancelar</button>
                    </div>
                </div>
            </div>
        </form>
    </template>

    <div class="pe-toast" data-ct-toast role="status" aria-live="polite" hidden></div>
</div>
<script src="<?= e(asset('assets/js/admin-colecoes.js')) ?>"></script>
<?php
view('admin_layout', [
    'titulo'       => 'Coleções',
    'titulo_acoes' => '<button type="button" class="ap-btn ap-btn-primario" data-ct-nova-btn>+ Nova coleção</button>',
    'conteudo'     => ob_get_clean(),
    'layout_largo' => true,
]);
