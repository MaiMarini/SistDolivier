<?php
/**
 * Admin: Categorias — lista com edição na própria linha.
 *   /admin/categorias                       -> lista (formulário abre abaixo da linha)
 *   /admin/categorias/novo | /editar/{id}   -> redirecionam para a lista (endereços antigos)
 * POST (fetch, JSON, CSRF):
 *   op=ordem       ids[] na nova ordem (todas as categorias) -> regrava 1..N numa transação
 *   op=ativo       id, ativo=0|1 (No menu / Oculta)
 *   op=salvar      id (0 = nova), nome, slug, ativo -> devolve o HTML da linha
 *   op=excluir     id, destino (obrigatório se houver produtos) -> exclusão lógica; produtos
 *                  vão para o fim do destino; guarda o que mudou para o "Desfazer"
 *   op=desfazer    id -> restaura a categoria, a posição no menu e os produtos
 * A ordem (categories.ordem) é a do menu da loja e a dos selects de categoria do admin.
 */
exigir_admin();

$BASE = 'admin/categorias';

/** Linhas ativas (não excluídas) na ordem, com o total de produtos (ocultos também). */
function _cat_lista(): array
{
    return db()->query(
        'SELECT c.id, c.nome, c.slug, c.ativo, COUNT(p.id) AS n
           FROM categories c
           LEFT JOIN products p ON p.category_id = c.id
          WHERE c.excluida_em IS NULL
          GROUP BY c.id, c.nome, c.slug, c.ativo, c.ordem
          ORDER BY c.ordem ASC, c.id ASC'
    )->fetchAll();
}

/** Regrava a ordem 1..N das categorias não excluídas (na ordem atual). */
function _cat_renumerar(): void
{
    $ids = db()->query('SELECT id FROM categories WHERE excluida_em IS NULL ORDER BY ordem ASC, id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $up = db()->prepare('UPDATE categories SET ordem = ? WHERE id = ?');
    foreach ($ids as $i => $id) {
        $up->execute([$i + 1, (int) $id]);
    }
}

/** Uma linha da lista (também devolvida pelos endpoints). */
function _cat_linha_html(array $c, int $pos, int $total): string
{
    $id = (int) $c['id'];
    $n = (int) $c['n'];
    $ativo = (int) $c['ativo'] === 1;
    $nome = (string) $c['nome'];
    ob_start();
    ?>
    <div class="ct-row<?= $ativo ? '' : ' is-oculta' ?>" data-ct-linha data-id="<?= $id ?>" data-nome="<?= e($nome) ?>"
         data-slug="<?= e($c['slug']) ?>" data-ativo="<?= $ativo ? 1 : 0 ?>" data-n="<?= $n ?>">
        <span class="ct-alca" data-ct-alca title="Arraste para reordenar" aria-hidden="true">⠿</span>
        <span class="ct-setas">
            <button type="button" data-ct-mover="-1" aria-label="<?= e('Subir ' . $nome) ?>" <?= $pos === 0 ? 'disabled' : '' ?>>↑</button>
            <button type="button" data-ct-mover="1" aria-label="<?= e('Descer ' . $nome) ?>" <?= $pos === $total - 1 ? 'disabled' : '' ?>>↓</button>
        </span>
        <span class="ct-nome"><span data-ct-nome><?= e($nome) ?></span><?php if ($ativo && !$n): ?><span class="ct-tag">Vazia</span><?php endif; ?></span>
        <span class="ct-prod"><?php if ($n): ?><a href="<?= e(url('admin/produtos') . '?' . http_build_query(['st' => 'todos', 'cat' => $id])) ?>"><?= $n === 1 ? '1 produto' : $n . ' produtos' ?></a><?php else: ?>Nenhum produto<?php endif; ?></span>
        <span class="ct-chave">
            <button type="button" class="pr-sw" role="switch" aria-checked="<?= $ativo ? 'true' : 'false' ?>" data-ct-ativo
                    aria-label="<?= e('No menu da loja: ' . $nome) ?>"><span class="pr-sw-trilho" aria-hidden="true"></span><span data-ct-ativo-txt><?= $ativo ? 'No menu' : 'Oculta' ?></span></button>
        </span>
        <span class="ct-acao"><button type="button" class="ap-btn ap-btn-linha" data-ct-editar aria-expanded="false">Editar</button></span>
        <div class="ct-inline" data-ct-inline hidden></div>
    </div>
    <?php
    return ob_get_clean();
}

/** Todas as linhas (para trocar a lista inteira depois de excluir/desfazer). */
function _cat_linhas_html(): string
{
    $lista = _cat_lista();
    $html = '';
    foreach ($lista as $i => $c) {
        $html .= _cat_linha_html($c, $i, count($lista));
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
    $op = $_POST['op'] ?? '';
    $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    $id = $id === false ? -1 : (int) $id;
    $buscar = function (int $id): ?array {
        $st = db()->prepare('SELECT * FROM categories WHERE id = ? AND excluida_em IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    };

    // ------------------------------------------------------------------ ORDEM
    if ($op === 'ordem') {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $atuais = array_map('intval', db()->query('SELECT id FROM categories WHERE excluida_em IS NULL')->fetchAll(PDO::FETCH_COLUMN));
        $a = $ids; $b = $atuais; sort($a); sort($b);
        if ($a !== $b) {   // precisa ser a lista completa, sem repetidos
            $responder(422, ['ok' => false, 'mensagem' => 'A lista mudou em outra tela. Recarregue a página.']);
            return;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare('UPDATE categories SET ordem = ? WHERE id = ?');
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

    // ---------------------------------------------------------------- NO MENU
    if ($op === 'ativo') {
        $ativo = $_POST['ativo'] ?? '';
        $cat = $id > 0 ? $buscar($id) : null;
        if (!$cat || !in_array($ativo, ['0', '1'], true)) {
            $responder($cat ? 422 : 404, ['ok' => false]);
            return;
        }
        db()->prepare('UPDATE categories SET ativo = ? WHERE id = ?')->execute([(int) $ativo, $id]);
        $responder(200, ['ok' => true]);
        return;
    }

    // ----------------------------------------------------------------- SALVAR
    if ($op === 'salvar') {
        $cat = $id > 0 ? $buscar($id) : null;
        if ($id !== 0 && !$cat) {
            $responder(404, ['ok' => false, 'mensagem' => 'Categoria não encontrada.']);
            return;
        }
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $slug_txt = trim((string) ($_POST['slug'] ?? ''));
        $ativo = ($_POST['ativo'] ?? '1') === '0' ? 0 : 1;
        $erros = [];
        if (mb_strlen($nome) < 2) {
            $erros['nome'] = 'Informe o nome da categoria.';
        } elseif (mb_strlen($nome) > 150) {
            $erros['nome'] = 'Use no máximo 150 caracteres.';
        } else {
            // Mesmo nome sem diferenciar maiúsculas/minúsculas (a collation já ignora), entre as não excluídas.
            $st = db()->prepare('SELECT 1 FROM categories WHERE LOWER(TRIM(nome)) = LOWER(?) AND id <> ? AND excluida_em IS NULL');
            $st->execute([$nome, $id]);
            if ($st->fetchColumn()) {
                $erros['nome'] = 'Já existe uma categoria com esse nome.';
            }
        }
        if ($erros) {
            $responder(422, ['ok' => false, 'erros' => $erros, 'mensagem' => 'Confira os campos destacados.']);
            return;
        }
        $base = mb_substr(gerar_slug($slug_txt !== '' ? $slug_txt : $nome), 0, 140) ?: 'categoria';
        $slug = slug_unico('categories', rtrim($base, '-'), $id > 0 ? $id : null);
        if ($cat) {
            db()->prepare('UPDATE categories SET nome = ?, slug = ?, ativo = ? WHERE id = ?')->execute([$nome, $slug, $ativo, $id]);
            $msg = 'Categoria salva.';
        } else {
            $ordem = (int) db()->query('SELECT COALESCE(MAX(ordem), 0) + 1 FROM categories WHERE excluida_em IS NULL')->fetchColumn();
            db()->prepare('INSERT INTO categories (slug, nome, ordem, ativo) VALUES (?, ?, ?, ?)')->execute([$slug, $nome, $ordem, $ativo]);
            $id = (int) db()->lastInsertId();
            $msg = 'Categoria criada. Ela entrou no fim da lista.';
        }
        if ($slug !== $base) {
            $msg .= ' O endereço ficou “' . $slug . '”.';
        }
        $responder(200, ['ok' => true, 'id' => $id, 'novo' => !$cat, 'mensagem' => $msg, 'linhas' => _cat_linhas_html()]);
        return;
    }

    // ---------------------------------------------------------------- EXCLUIR
    if ($op === 'excluir') {
        $cat = $id > 0 ? $buscar($id) : null;
        if (!$cat) {
            $responder(404, ['ok' => false, 'mensagem' => 'Categoria não encontrada.']);
            return;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, ordem FROM products WHERE category_id = ? ORDER BY ordem ASC, id ASC FOR UPDATE');
            $st->execute([$id]);
            $produtos = $st->fetchAll();
            $destino = null;
            if ($produtos) {
                // Nenhum produto pode ficar sem categoria: o destino é obrigatório e válido.
                $dest_id = (int) ($_POST['destino'] ?? 0);
                $destino = $dest_id > 0 && $dest_id !== $id ? $buscar($dest_id) : null;
                if (!$destino) {
                    $pdo->rollBack();
                    $responder(422, ['ok' => false, 'mensagem' => 'Escolha para qual categoria mover os produtos.']);
                    return;
                }
                $st = $pdo->prepare('SELECT COALESCE(MAX(ordem), 0) FROM products WHERE category_id = ? FOR UPDATE');
                $st->execute([$dest_id]);
                $prox = (int) $st->fetchColumn();
                $mover = $pdo->prepare('UPDATE products SET category_id = ?, ordem = ? WHERE id = ?');
                foreach ($produtos as $p) {
                    $mover->execute([$dest_id, ++$prox, (int) $p['id']]);   // fim da ordem do destino
                }
            }
            $info = json_encode([
                'ativo' => (int) $cat['ativo'], 'ordem' => (int) $cat['ordem'], 'destino' => $destino ? (int) $destino['id'] : null,
                'produtos' => array_map(fn ($p) => [(int) $p['id'], (int) $p['ordem']], $produtos),
            ]);
            // Some de tudo: excluida_em + ativo = 0 (o menu e a página da categoria já filtram por ativo).
            $pdo->prepare('UPDATE categories SET excluida_em = NOW(), ativo = 0, exclusao_info = ? WHERE id = ?')->execute([$info, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $responder(500, ['ok' => false, 'mensagem' => 'Não foi possível excluir. Tente de novo.']);
            return;
        }
        $n = count($produtos);
        $msg = '“' . $cat['nome'] . '” excluída.'
            . ($n ? ' ' . ($n === 1 ? '1 produto foi' : $n . ' produtos foram') . ' para “' . $destino['nome'] . '”.' : '');
        $responder(200, ['ok' => true, 'id' => $id, 'mensagem' => $msg, 'linhas' => _cat_linhas_html()]);
        return;
    }

    // --------------------------------------------------------------- DESFAZER
    if ($op === 'desfazer') {
        $st = db()->prepare('SELECT * FROM categories WHERE id = ? AND excluida_em IS NOT NULL');
        $st->execute([$id]);
        $cat = $st->fetch();
        $info = $cat ? json_decode((string) $cat['exclusao_info'], true) : null;
        if (!$cat || !is_array($info)) {
            $responder(404, ['ok' => false, 'mensagem' => 'Não foi possível desfazer.']);
            return;
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE categories SET excluida_em = NULL, exclusao_info = NULL, ativo = ?, ordem = ? WHERE id = ?')
                ->execute([(int) $info['ativo'], (int) $info['ordem'], $id]);
            // Devolve os produtos que ainda estão no destino, na posição de antes.
            $volta = $pdo->prepare('UPDATE products SET category_id = ?, ordem = ? WHERE id = ? AND category_id = ?');
            foreach ((array) $info['produtos'] as [$pid, $ordem]) {
                $volta->execute([$id, (int) $ordem, (int) $pid, (int) $info['destino']]);
            }
            _cat_renumerar();   // a categoria volta para a posição dela no menu
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $responder(500, ['ok' => false, 'mensagem' => 'Não foi possível desfazer.']);
            return;
        }
        $responder(200, ['ok' => true, 'mensagem' => '“' . $cat['nome'] . '” voltou.', 'linhas' => _cat_linhas_html()]);
        return;
    }

    $responder(400, ['ok' => false, 'mensagem' => 'Ação desconhecida.']);
    return;
}

// =============================================================================
// GET
// =============================================================================
if (in_array($params[0] ?? '', ['novo', 'editar'], true)) {   // endereços antigos
    redirect($BASE);
}

// Limpeza definitiva das excluídas há mais de 30 dias (já não têm produtos).
db()->exec('DELETE c FROM categories c
             WHERE c.excluida_em < NOW() - INTERVAL 30 DAY
               AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id)');

$host = parse_url(url(), PHP_URL_HOST) ?: 'dolivier.com.br';
$prefixo = preg_replace('/^www\./', '', $host) . '/categoria/';

ob_start();
?>
<div class="ct" data-ct data-url="<?= e(url($BASE)) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <p class="pe-aviso ct-vazias" data-ct-vazias hidden></p>
    <p class="pe-ajuda ct-ajuda">A ordem aqui é a ordem do menu da loja. Arraste pela alça ⠿ ou use as setas; salva sozinho.</p>

    <div class="ct-lista" data-ct-lista>
        <div class="ct-row ct-head" aria-hidden="true"><span></span><span>Ordem</span><span>Nome</span><span>Produtos</span><span>Na loja</span><span></span></div>
        <div class="ct-nova" data-ct-nova hidden><h2>Nova categoria</h2><div data-ct-nova-form></div></div>
        <div data-ct-linhas><?= _cat_linhas_html() ?></div>
        <p class="ct-sem" data-ct-sem hidden>Nenhuma categoria ainda. Use “+ Nova categoria”.</p>
    </div>

    <template data-ct-modelo>
        <form class="ct-form" novalidate>
            <p class="legenda-obrigatorio"><span aria-hidden="true">*</span> Campos obrigatórios</p>
            <div class="pe-campo">
                <label for="ct-f-nome" class="rotulo-obrigatorio">Nome</label>
                <input id="ct-f-nome" name="nome" type="text" maxlength="150" required autocomplete="off" placeholder="Ex.: Kit Presente"
                       aria-describedby="ct-f-nome-dica ct-f-nome-erro">
                <span class="pe-dica" id="ct-f-nome-dica" data-ct-dup hidden>Já existe uma categoria com esse nome.</span>
                <span class="pe-erro" id="ct-f-nome-erro" data-ct-erro="nome" role="alert" hidden></span>
            </div>
            <input type="hidden" name="ativo" value="1" data-ct-f-ativo>
            <button type="button" class="pe-tg ct-form-tg" role="switch" aria-checked="true" data-ct-f-sw aria-describedby="ct-f-sw-ajuda">
                <span class="pe-tg-txt"><b>Mostrar no menu da loja</b><span id="ct-f-sw-ajuda">Desligada, a categoria some do menu, mas os produtos continuam à venda.</span></span>
                <span class="pe-tg-trilho" aria-hidden="true"></span>
            </button>
            <details class="pe-avancado">
                <summary>Avançado</summary>
                <div class="pe-campo">
                    <label for="ct-f-slug">Endereço da página</label>
                    <span class="pe-affix pe-affix-longo"><span aria-hidden="true"><?= e($prefixo) ?></span>
                        <input id="ct-f-slug" name="slug" type="text" maxlength="140" autocomplete="off" spellcheck="false"></span>
                    <span class="pe-ajuda" data-ct-slug-ajuda></span>
                    <p class="pe-aviso" data-ct-slug-aviso hidden>Mudar o endereço quebra links já compartilhados desta categoria.</p>
                    <button type="button" class="pe-link" data-ct-gerar>Gerar a partir do nome</button>
                </div>
            </details>
            <div class="ct-botoes">
                <button type="submit" class="ap-btn ap-btn-primario" data-ct-salvar disabled>Salvar</button>
                <button type="button" class="ap-btn" data-ct-cancelar>Cancelar</button>
            </div>
            <div class="ct-perigo" data-ct-perigo>
                <button type="button" class="ap-btn ct-excluir" data-ct-excluir>Excluir categoria</button>
                <div class="ct-confirmar" data-ct-confirmar hidden>
                    <b data-ct-conf-titulo></b>
                    <div class="pe-campo" data-ct-conf-destino hidden>
                        <label for="ct-f-destino">Mover os produtos para</label>
                        <select id="ct-f-destino" data-ct-destino><option value="">Escolha uma categoria</option></select>
                    </div>
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
<script src="<?= e(asset('assets/js/admin-categorias.js')) ?>"></script>
<?php
view('admin_layout', [
    'titulo'       => 'Categorias',
    'titulo_acoes' => '<button type="button" class="ap-btn ap-btn-primario" data-ct-nova-btn>+ Nova categoria</button>',
    'conteudo'     => ob_get_clean(),
    'layout_largo' => true,
]);
