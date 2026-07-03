<?php
/**
 * Admin: gerenciamento de categorias. Rotas:
 *   /admin/categorias              -> listar (reordenável)
 *   /admin/categorias/novo         -> form de criação
 *   /admin/categorias/editar/{id}  -> form de edição
 *   POST op=salvar                 -> cria/atualiza (slug único)
 *   POST op=excluir                -> exclui (produtos ficam sem categoria)
 *   POST op=reordenar (AJAX)       -> reescreve a coluna `ordem` de TODAS as
 *                                     categorias em sequência (1..N), numa transação.
 * A ordem vem só da posição na lista (arrastar no desktop, setas no mobile).
 */
exigir_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';

    // --- Reordenação via AJAX (responde JSON, sem redirect) ------------------
    if ($op === 'reordenar') {
        header('Content-Type: application/json; charset=utf-8');
        if (!csrf_validar()) {
            echo json_encode(['ok' => false, 'erro' => 'csrf']);
            return;
        }
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        }));

        if (!empty($ids)) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $up = $pdo->prepare('UPDATE categories SET ordem = ? WHERE id = ?');
                $posicao = 1;
                foreach ($ids as $id) {
                    $up->execute([$posicao, $id]);
                    $posicao++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'erro' => 'db']);
                return;
            }
        }
        echo json_encode(['ok' => true]);
        return;
    }

    // --- Demais operações (formulário normal) --------------------------------
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/categorias');
    }

    if ($op === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = db()->prepare('DELETE FROM categories WHERE id = ?');
            $stmt->execute([$id]);
            flash('sucesso', 'Categoria excluída. Os produtos dela ficaram sem categoria.');
        }
        redirect('admin/categorias');
    }

    if ($op === 'salvar') {
        $id    = (int) ($_POST['id'] ?? 0);
        $nome  = trim($_POST['nome'] ?? '');
        $slug  = trim($_POST['slug'] ?? '');
        $ativo = isset($_POST['ativo']) ? 1 : 0;

        $destino_erro = $id > 0 ? 'admin/categorias/editar/' . $id : 'admin/categorias/novo';

        if (mb_strlen($nome) < 2) {
            flash('erro', 'Informe o nome da categoria.');
            redirect($destino_erro);
        }

        $base = gerar_slug($slug !== '' ? $slug : $nome);
        $slug = slug_unico('categories', $base, $id > 0 ? $id : null);

        if ($id > 0) {
            // Edição não mexe na ordem (ela é definida por arrastar/setas).
            $stmt = db()->prepare(
                'UPDATE categories SET nome = ?, slug = ?, ativo = ? WHERE id = ?'
            );
            $stmt->execute([$nome, $slug, $ativo, $id]);
            flash('sucesso', 'Categoria atualizada.');
        } else {
            // Nova categoria entra no fim da lista.
            $ordem = (int) db()->query('SELECT COALESCE(MAX(ordem), 0) FROM categories')->fetchColumn() + 1;
            $stmt = db()->prepare(
                'INSERT INTO categories (slug, nome, ordem, ativo) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$slug, $nome, $ordem, $ativo]);
            flash('sucesso', 'Categoria criada.');
        }
        redirect('admin/categorias');
    }

    redirect('admin/categorias');
}

// --- GET: novo / editar / listar ---------------------------------------------
$acao = $params[0] ?? 'listar';

if ($acao === 'novo' || $acao === 'editar') {
    $categoria = ['id' => 0, 'nome' => '', 'slug' => '', 'ativo' => 1];

    if ($acao === 'editar') {
        $id = (int) ($params[1] ?? 0);
        $stmt = db()->prepare(
            'SELECT id, nome, slug, ativo FROM categories WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $cat = $stmt->fetch();
        if (!$cat) {
            flash('erro', 'Categoria não encontrada.');
            redirect('admin/categorias');
        }
        $categoria = $cat;
    }

    $titulo = $categoria['id'] > 0 ? 'Editar categoria' : 'Nova categoria';

    ob_start();
    ?>
    <p><a href="<?= e(url('admin/categorias')) ?>">&larr; Voltar para categorias</a></p>

    <form class="formulario" method="post" action="<?= e(url('admin/categorias')) ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="op" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $categoria['id'] ?>">

        <div class="campo">
            <label for="nome">Nome</label>
            <input type="text" id="nome" name="nome"
                   value="<?= e($categoria['nome']) ?>" required>
        </div>
        <div class="campo">
            <label for="slug">Slug (endereço)</label>
            <input type="text" id="slug" name="slug" value="<?= e($categoria['slug']) ?>"
                   placeholder="Deixe vazio para gerar a partir do nome">
        </div>
        <div class="campo campo-inline">
            <input type="checkbox" id="ativo" name="ativo" value="1"
                   <?= $categoria['ativo'] ? 'checked' : '' ?>>
            <label for="ativo">Ativa (aparece no menu da loja)</label>
        </div>

        <button class="btn" type="submit">Salvar</button>
    </form>
    <p class="mt-1"><small>A ordem das categorias é definida arrastando (ou pelas setas)
       na listagem.</small></p>
    <?php
    view('admin_layout', ['titulo' => $titulo, 'conteudo' => ob_get_clean()]);
    return;
}

// Listagem (ordena por ordem e, como desempate, por id — estável).
$categorias = db()->query(
    'SELECT id, nome, slug, ativo FROM categories ORDER BY ordem ASC, id ASC'
)->fetchAll();

ob_start();
?>
<p><a class="btn" href="<?= e(url('admin/categorias/novo')) ?>">Nova categoria</a></p>

<?php if (empty($categorias)): ?>
    <p>Nenhuma categoria cadastrada.</p>
<?php else: ?>
    <p><small>Arraste pela alça (☰) para reordenar; no celular, use as setas ↑↓.</small></p>
    <table class="tabela">
        <thead>
            <tr><th></th><th>Nome</th><th>Slug</th><th>Ativa</th><th>Ações</th></tr>
        </thead>
        <tbody id="cats-tbody">
            <?php foreach ($categorias as $c): ?>
                <tr data-id="<?= (int) $c['id'] ?>">
                    <td class="col-mover">
                        <span class="arrastar-handle" title="Arraste para reordenar" aria-hidden="true">&#9776;</span>
                        <span class="ordenar-setas">
                            <button type="button" class="btn-seta" data-subir aria-label="Mover para cima">&uarr;</button>
                            <button type="button" class="btn-seta" data-descer aria-label="Mover para baixo">&darr;</button>
                        </span>
                    </td>
                    <td><?= e($c['nome']) ?></td>
                    <td><?= e($c['slug']) ?></td>
                    <td><?= $c['ativo'] ? 'Sim' : 'Não' ?></td>
                    <td>
                        <a class="btn sec" href="<?= e(url('admin/categorias/editar/' . $c['id'])) ?>">Editar</a>
                        <form method="post" action="<?= e(url('admin/categorias')) ?>"
                              style="display:inline"
                              onsubmit="return confirm('Excluir esta categoria? Os produtos dela ficarão sem categoria.');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="op" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button class="btn" type="submit">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div id="cats-feedback" class="reorder-feedback" hidden></div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script>
    (function () {
        var tbody = document.getElementById('cats-tbody');
        if (!tbody) { return; }
        var endpoint = <?= json_encode(url('admin/categorias')) ?>;
        var csrf = <?= json_encode(csrf_token()) ?>;
        var feedback = document.getElementById('cats-feedback');

        function ids() {
            return Array.prototype.map.call(
                tbody.querySelectorAll('tr[data-id]'),
                function (tr) { return tr.getAttribute('data-id'); }
            );
        }
        function feedbackMostrar(ok) {
            if (!feedback) { return; }
            feedback.textContent = ok ? 'Ordem salva ✓' : 'Erro ao salvar';
            feedback.classList.toggle('erro', !ok);
            feedback.hidden = false;
            clearTimeout(feedback._t);
            feedback._t = setTimeout(function () { feedback.hidden = true; }, 1800);
        }
        function atualizarSetas() {
            var linhas = tbody.querySelectorAll('tr[data-id]');
            linhas.forEach(function (tr, i) {
                var up = tr.querySelector('[data-subir]');
                var down = tr.querySelector('[data-descer]');
                if (up) { up.disabled = (i === 0); }
                if (down) { down.disabled = (i === linhas.length - 1); }
            });
        }
        function salvar() {
            var body = new URLSearchParams();
            body.append('op', 'reordenar');
            body.append('_csrf', csrf);
            ids().forEach(function (id) { body.append('ids[]', id); });
            fetch(endpoint, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin',
                body: body
            })
                .then(function (r) { return r.json(); })
                .then(function (d) { feedbackMostrar(!!(d && d.ok)); })
                .catch(function () { feedbackMostrar(false); });
            atualizarSetas();
        }

        // Desktop: arrastar pela alça.
        if (window.Sortable) {
            Sortable.create(tbody, { handle: '.arrastar-handle', animation: 150, onEnd: salvar });
        }
        // Mobile: setas ↑↓.
        tbody.addEventListener('click', function (ev) {
            var btn = ev.target.closest('[data-subir], [data-descer]');
            if (!btn) { return; }
            var tr = btn.closest('tr[data-id]');
            if (!tr) { return; }
            if (btn.hasAttribute('data-subir') && tr.previousElementSibling) {
                tr.parentNode.insertBefore(tr, tr.previousElementSibling);
                salvar();
            } else if (btn.hasAttribute('data-descer') && tr.nextElementSibling) {
                tr.parentNode.insertBefore(tr.nextElementSibling, tr);
                salvar();
            }
        });

        atualizarSetas();
    })();
    </script>
<?php endif; ?>
<?php
view('admin_layout', ['titulo' => 'Categorias', 'conteudo' => ob_get_clean()]);
