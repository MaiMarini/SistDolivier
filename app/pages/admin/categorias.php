<?php
/**
 * Admin: gerenciamento de categorias. Rotas:
 *   /admin/categorias              -> listar
 *   /admin/categorias/novo         -> form de criação
 *   /admin/categorias/editar/{id}  -> form de edição
 *   POST op=salvar                 -> cria/atualiza (slug único)
 *   POST op=excluir                -> exclui (produtos ficam sem categoria)
 */
exigir_admin();

// --- POST: salvar ou excluir -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/categorias');
    }

    $op = $_POST['op'] ?? '';

    if ($op === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            // FK ON DELETE SET NULL: os produtos da categoria ficam sem categoria.
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
        $ordem = (int) ($_POST['ordem'] ?? 0);
        $ativo = isset($_POST['ativo']) ? 1 : 0;

        $destino_erro = $id > 0 ? 'admin/categorias/editar/' . $id : 'admin/categorias/novo';

        if (mb_strlen($nome) < 2) {
            flash('erro', 'Informe o nome da categoria.');
            redirect($destino_erro);
        }

        // Slug: usa o informado ou gera do nome; garante unicidade.
        $base = gerar_slug($slug !== '' ? $slug : $nome);
        $slug = slug_unico('categories', $base, $id > 0 ? $id : null);

        if ($id > 0) {
            $stmt = db()->prepare(
                'UPDATE categories SET nome = ?, slug = ?, ordem = ?, ativo = ? WHERE id = ?'
            );
            $stmt->execute([$nome, $slug, $ordem, $ativo, $id]);
            flash('sucesso', 'Categoria atualizada.');
        } else {
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
    $categoria = ['id' => 0, 'nome' => '', 'slug' => '', 'ordem' => 0, 'ativo' => 1];

    if ($acao === 'editar') {
        $id = (int) ($params[1] ?? 0);
        $stmt = db()->prepare(
            'SELECT id, nome, slug, ordem, ativo FROM categories WHERE id = ? LIMIT 1'
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
        <div class="campo">
            <label for="ordem">Ordem</label>
            <input type="number" id="ordem" name="ordem"
                   value="<?= (int) $categoria['ordem'] ?>">
        </div>
        <div class="campo campo-inline">
            <input type="checkbox" id="ativo" name="ativo" value="1"
                   <?= $categoria['ativo'] ? 'checked' : '' ?>>
            <label for="ativo">Ativa (aparece no menu da loja)</label>
        </div>

        <button class="btn" type="submit">Salvar</button>
    </form>
    <?php
    view('admin_layout', ['titulo' => $titulo, 'conteudo' => ob_get_clean()]);
    return;
}

// Listagem
$categorias = db()->query(
    'SELECT id, nome, slug, ordem, ativo FROM categories ORDER BY ordem ASC, nome ASC'
)->fetchAll();

ob_start();
?>
<p><a class="btn" href="<?= e(url('admin/categorias/novo')) ?>">Nova categoria</a></p>

<?php if (empty($categorias)): ?>
    <p>Nenhuma categoria cadastrada.</p>
<?php else: ?>
    <table class="tabela">
        <thead>
            <tr><th>Nome</th><th>Slug</th><th>Ordem</th><th>Ativa</th><th>Ações</th></tr>
        </thead>
        <tbody>
            <?php foreach ($categorias as $c): ?>
                <tr>
                    <td><?= e($c['nome']) ?></td>
                    <td><?= e($c['slug']) ?></td>
                    <td><?= (int) $c['ordem'] ?></td>
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
<?php endif; ?>
<?php
view('admin_layout', ['titulo' => 'Categorias', 'conteudo' => ob_get_clean()]);
