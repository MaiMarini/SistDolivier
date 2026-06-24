<?php
/**
 * Admin: banners da home. Rotas:
 *   /admin/banners              -> listar
 *   /admin/banners/novo         -> form de criação
 *   /admin/banners/editar/{id}  -> form de edição
 *   POST op=salvar              -> cria/atualiza (imagem otimizada pelo helper)
 *   POST op=excluir             -> exclui (remove o arquivo de imagem)
 */
exigir_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/banners');
    }

    $op = $_POST['op'] ?? '';

    if ($op === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = db()->prepare('SELECT imagem FROM banners WHERE id = ?');
            $stmt->execute([$id]);
            $arquivo = $stmt->fetchColumn();
            if ($arquivo) {
                imagem_apagar($arquivo);
            }
            $stmt = db()->prepare('DELETE FROM banners WHERE id = ?');
            $stmt->execute([$id]);
            flash('sucesso', 'Banner excluído.');
        }
        redirect('admin/banners');
    }

    if ($op === 'salvar') {
        $id     = (int) ($_POST['id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $link   = trim($_POST['link'] ?? '');
        $ordem  = (int) ($_POST['ordem'] ?? 0);
        $ativo  = isset($_POST['ativo']) ? 1 : 0;

        $destino_erro = $id > 0 ? 'admin/banners/editar/' . $id : 'admin/banners/novo';

        // Processa a imagem enviada (se houver).
        $novo_arquivo = null;
        if (!empty($_FILES['imagem']['name'])) {
            $res = processar_upload_imagem($_FILES['imagem']);
            if (empty($res['ok'])) {
                flash('erro', $res['erro'] ?? 'Falha ao enviar a imagem.');
                redirect($destino_erro);
            }
            $novo_arquivo = $res['arquivo'];
        }

        if ($id > 0) {
            // Edição: troca a imagem só se uma nova foi enviada.
            if ($novo_arquivo !== null) {
                $stmt = db()->prepare('SELECT imagem FROM banners WHERE id = ?');
                $stmt->execute([$id]);
                $antigo = $stmt->fetchColumn();
                if ($antigo) {
                    imagem_apagar($antigo);
                }
                $stmt = db()->prepare(
                    'UPDATE banners SET imagem = ?, titulo = ?, link = ?, ordem = ?, ativo = ? WHERE id = ?'
                );
                $stmt->execute([$novo_arquivo, $titulo, $link, $ordem, $ativo, $id]);
            } else {
                $stmt = db()->prepare(
                    'UPDATE banners SET titulo = ?, link = ?, ordem = ?, ativo = ? WHERE id = ?'
                );
                $stmt->execute([$titulo, $link, $ordem, $ativo, $id]);
            }
            flash('sucesso', 'Banner atualizado.');
            redirect('admin/banners');
        }

        // Criação: imagem é obrigatória.
        if ($novo_arquivo === null) {
            flash('erro', 'Envie uma imagem para o banner.');
            redirect('admin/banners/novo');
        }
        $stmt = db()->prepare(
            'INSERT INTO banners (imagem, titulo, link, ordem, ativo) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$novo_arquivo, $titulo, $link, $ordem, $ativo]);
        flash('sucesso', 'Banner criado.');
        redirect('admin/banners');
    }

    redirect('admin/banners');
}

// --- GET ---------------------------------------------------------------------
$acao = $params[0] ?? 'listar';

if ($acao === 'novo' || $acao === 'editar') {
    $banner = ['id' => 0, 'imagem' => '', 'titulo' => '', 'link' => '', 'ordem' => 0, 'ativo' => 1];

    if ($acao === 'editar') {
        $id = (int) ($params[1] ?? 0);
        $stmt = db()->prepare(
            'SELECT id, imagem, titulo, link, ordem, ativo FROM banners WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) {
            flash('erro', 'Banner não encontrado.');
            redirect('admin/banners');
        }
        $banner = $b;
    }

    $titulo = $banner['id'] > 0 ? 'Editar banner' : 'Novo banner';

    ob_start();
    ?>
    <p><a href="<?= e(url('admin/banners')) ?>">&larr; Voltar para banners</a></p>

    <form class="formulario" method="post" action="<?= e(url('admin/banners')) ?>"
          enctype="multipart/form-data" style="max-width:640px;">
        <?= csrf_input() ?>
        <input type="hidden" name="op" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $banner['id'] ?>">

        <?php if (!empty($banner['imagem'])): ?>
            <div class="campo">
                <label>Imagem atual</label>
                <img src="<?= e(url('assets/uploads/' . imagem_miniatura($banner['imagem']))) ?>"
                     alt="" style="max-width:100%;border-radius:var(--raio);">
            </div>
        <?php endif; ?>

        <div class="campo">
            <label for="imagem">Imagem <?= $banner['id'] > 0 ? '(deixe vazio para manter)' : '' ?></label>
            <input type="file" id="imagem" name="imagem" accept="image/jpeg,image/png,image/webp">
        </div>
        <div class="campo">
            <label for="titulo">Título (opcional)</label>
            <input type="text" id="titulo" name="titulo" value="<?= e($banner['titulo']) ?>">
        </div>
        <div class="campo">
            <label for="link">Link (opcional)</label>
            <input type="text" id="link" name="link" value="<?= e($banner['link']) ?>"
                   placeholder="Ex.: /categoria/velas ou https://...">
        </div>
        <div class="campo">
            <label for="ordem">Ordem</label>
            <input type="number" id="ordem" name="ordem" value="<?= (int) $banner['ordem'] ?>">
        </div>
        <div class="campo campo-inline">
            <input type="checkbox" id="ativo" name="ativo" value="1"
                   <?= $banner['ativo'] ? 'checked' : '' ?>>
            <label for="ativo">Ativo (aparece na home)</label>
        </div>

        <button class="btn" type="submit">Salvar</button>
    </form>
    <?php
    view('admin_layout', ['titulo' => $titulo, 'conteudo' => ob_get_clean()]);
    return;
}

// Listagem
$banners = db()->query(
    'SELECT id, imagem, titulo, ordem, ativo FROM banners ORDER BY ordem ASC, id ASC'
)->fetchAll();

ob_start();
?>
<p><a class="btn" href="<?= e(url('admin/banners/novo')) ?>">Novo banner</a></p>

<?php if (empty($banners)): ?>
    <p>Nenhum banner cadastrado.</p>
<?php else: ?>
    <table class="tabela">
        <thead>
            <tr><th></th><th>Título</th><th>Ordem</th><th>Ativo</th><th>Ações</th></tr>
        </thead>
        <tbody>
            <?php foreach ($banners as $b): ?>
                <tr>
                    <td style="width:90px;">
                        <?php if (!empty($b['imagem'])): ?>
                            <img src="<?= e(url('assets/uploads/' . imagem_miniatura($b['imagem']))) ?>"
                                 alt="" style="width:80px;height:45px;object-fit:cover;border-radius:6px;">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= e($b['titulo'] ?: '—') ?></td>
                    <td><?= (int) $b['ordem'] ?></td>
                    <td><?= $b['ativo'] ? 'Sim' : 'Não' ?></td>
                    <td>
                        <a class="btn sec" href="<?= e(url('admin/banners/editar/' . $b['id'])) ?>">Editar</a>
                        <form method="post" action="<?= e(url('admin/banners')) ?>" style="display:inline"
                              onsubmit="return confirm('Excluir este banner?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="op" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                            <button class="btn" type="submit">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php
view('admin_layout', ['titulo' => 'Banners', 'conteudo' => ob_get_clean()]);
