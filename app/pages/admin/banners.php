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

/** Remove (hard delete) um arquivo da pasta de uploads, com segurança. */
function _apagar_upload(string $nome): void
{
    $nome = basename($nome);            // evita path traversal
    if ($nome === '' || $nome === '.') {
        return;
    }
    $caminho = ROOT_PATH . '/assets/uploads/' . $nome;
    if (is_file($caminho)) {
        @unlink($caminho);
    }
}

/** Upload de vídeo do bloco editorial: MP4/WebM, tipo real validado, máx. 15 MB. */
function _bloco_upload_video(array $arquivo): array
{
    $max = 15 * 1024 * 1024; // 15 MB

    if (!isset($arquivo['error']) || is_array($arquivo['error'])) {
        return ['ok' => false, 'erro' => 'Envio de arquivo inválido.'];
    }
    switch ($arquivo['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'erro' => 'O vídeo deve ter no máximo 15 MB.'];
        default:
            return ['ok' => false, 'erro' => 'Falha no envio do vídeo. Tente novamente.'];
    }
    if (($arquivo['size'] ?? 0) > $max) {
        return ['ok' => false, 'erro' => 'O vídeo deve ter no máximo 15 MB.'];
    }
    $tmp = $arquivo['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'erro' => 'Arquivo de upload inválido.'];
    }

    // 1) Extensão (MP4 ou WebM).
    $ext = strtolower(pathinfo($arquivo['name'] ?? '', PATHINFO_EXTENSION));
    $exts_ok = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
    if (!isset($exts_ok[$ext])) {
        return ['ok' => false, 'erro' => 'Formato não suportado. Envie um vídeo MP4 (ou WebM).'];
    }

    // 2) Tipo MIME real do arquivo.
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($fi, $tmp);
        finfo_close($fi);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string) mime_content_type($tmp);
    }
    // Aceita os MIME de vídeo esperados. application/octet-stream é tolerado
    // porque alguns servidores não identificam o container MP4/WebM — mas só
    // quando a extensão já foi validada acima.
    $mimes_video = ['video/mp4', 'video/webm', 'video/x-m4v'];
    $mime_ok = in_array($mime, $mimes_video, true) || $mime === 'application/octet-stream';
    if (!$mime_ok) {
        return ['ok' => false, 'erro' => 'Formato não suportado. Envie um vídeo MP4 (ou WebM).'];
    }

    $dir = ROOT_PATH . '/assets/uploads';
    if (!is_dir($dir) || !is_writable($dir)) {
        return ['ok' => false, 'erro' => 'A pasta de uploads não tem permissão de escrita.'];
    }
    $nome = uniqid('vid_', false) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $nome)) {
        return ['ok' => false, 'erro' => 'Não foi possível salvar o vídeo.'];
    }
    return ['ok' => true, 'arquivo' => $nome];
}

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

    // Bloco editorial (registro único em settings, NÃO é um banner).
    if ($op === 'bloco_salvar') {
        $stmt = db()->prepare(
            'INSERT INTO settings (chave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = ?'
        );
        foreach (['bloco_editorial_titulo', 'bloco_editorial_subtitulo',
                  'bloco_editorial_botao_texto', 'bloco_editorial_botao_link'] as $k) {
            $v = trim($_POST[$k] ?? '');
            $stmt->execute([$k, $v, $v]);
        }

        // Tipo de mídia: "foto" ou "video".
        $tipo = ($_POST['bloco_editorial_tipo_midia'] ?? 'foto') === 'video' ? 'video' : 'foto';
        $stmt->execute(['bloco_editorial_tipo_midia', $tipo, $tipo]);

        if ($tipo === 'foto') {
            // Novo upload de imagem (opcional; senão mantém a atual).
            if (!empty($_FILES['bloco_editorial_imagem']['name'])) {
                $res = processar_upload_imagem($_FILES['bloco_editorial_imagem'], ['gerar_miniatura' => false]);
                if (empty($res['ok'])) {
                    flash('erro', $res['erro'] ?? 'Falha ao enviar a imagem do bloco.');
                    redirect('admin/banners');
                }
                $antiga = cfg('bloco_editorial_imagem', '');
                if ($antiga !== '') {
                    imagem_apagar($antiga);
                }
                $stmt->execute(['bloco_editorial_imagem', $res['arquivo'], $res['arquivo']]);
            }
            // Trocou para foto -> apaga o vídeo antigo do disco e limpa a chave.
            $video_antigo = cfg('bloco_editorial_video', '');
            if ($video_antigo !== '') {
                _apagar_upload($video_antigo);
                $stmt->execute(['bloco_editorial_video', '', '']);
            }
        } else {
            // Vídeo: novo upload (opcional; senão mantém o atual).
            if (!empty($_FILES['bloco_editorial_video']['name'])) {
                $res = _bloco_upload_video($_FILES['bloco_editorial_video']);
                if (empty($res['ok'])) {
                    flash('erro', $res['erro'] ?? 'Falha ao enviar o vídeo.');
                    redirect('admin/banners');
                }
                $antigo = cfg('bloco_editorial_video', '');
                if ($antigo !== '') {
                    _apagar_upload($antigo);
                }
                $stmt->execute(['bloco_editorial_video', $res['arquivo'], $res['arquivo']]);
            }
            // Trocou para vídeo -> apaga a foto antiga do disco e limpa a chave.
            $foto_antiga = cfg('bloco_editorial_imagem', '');
            if ($foto_antiga !== '') {
                imagem_apagar($foto_antiga);
                $stmt->execute(['bloco_editorial_imagem', '', '']);
            }
        }

        flash('sucesso', 'Bloco editorial salvo.');
        redirect('admin/banners');
    }

    // ----- Frases do marquee (máx. 5) -----
    if ($op === 'frase_adicionar') {
        $total = (int) db()->query('SELECT COUNT(*) FROM marquee_frases')->fetchColumn();
        if ($total >= 5) {
            flash('erro', 'Limite de 5 frases atingido.');
        } else {
            $ordem = (int) db()->query('SELECT COALESCE(MAX(ordem), 0) FROM marquee_frases')->fetchColumn() + 1;
            $stmt = db()->prepare('INSERT INTO marquee_frases (texto, ordem) VALUES (?, ?)');
            $stmt->execute(['', $ordem]);
            flash('sucesso', 'Frase adicionada. Edite o texto e salve.');
        }
        redirect('admin/banners');
    }

    if ($op === 'frase_salvar') {
        $id = (int) ($_POST['id'] ?? 0);
        $texto = trim($_POST['texto'] ?? '');
        if (mb_strlen($texto) > 120) {
            $texto = mb_substr($texto, 0, 120);
        }
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE marquee_frases SET texto = ? WHERE id = ?');
            $stmt->execute([$texto, $id]);
            flash('sucesso', 'Frase atualizada.');
        }
        redirect('admin/banners');
    }

    if ($op === 'frase_excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = db()->prepare('DELETE FROM marquee_frases WHERE id = ?');
            $stmt->execute([$id]);
            flash('sucesso', 'Frase excluída.');
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

// Frases do marquee (ordenadas).
$frases = [];
try {
    $frases = db()->query(
        'SELECT id, texto, ordem FROM marquee_frases ORDER BY ordem ASC, id ASC'
    )->fetchAll();
} catch (PDOException $e) {
    $frases = [];
}
$total_frases = count($frases);

ob_start();
?>
<h2>Banners</h2>
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

<hr class="mt-1">

<h2 class="mt-1">Frases do marquee</h2>
<p>Frases curtas que passam na faixa da home. Máximo de 5.</p>

<?php if (empty($frases)): ?>
    <p>Nenhuma frase cadastrada.</p>
<?php else: ?>
    <?php foreach ($frases as $f): ?>
        <form method="post" action="<?= e(url('admin/banners')) ?>"
              class="campo-inline" style="margin-bottom:.5rem; max-width:640px;">
            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
            <input type="text" name="texto" value="<?= e($f['texto']) ?>"
                   maxlength="120" style="flex:1;" placeholder="Texto da frase">
            <button class="btn sec" type="submit" name="op" value="frase_salvar">Salvar</button>
            <button class="btn" type="submit" name="op" value="frase_excluir"
                    onclick="return confirm('Excluir esta frase?');">Excluir</button>
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($total_frases < 5): ?>
    <form method="post" action="<?= e(url('admin/banners')) ?>" style="margin-top:.5rem;">
        <?= csrf_input() ?>
        <input type="hidden" name="op" value="frase_adicionar">
        <button class="btn" type="submit">Adicionar frase</button>
    </form>
<?php else: ?>
    <p><em>Limite de 5 frases atingido.</em></p>
<?php endif; ?>

<hr class="mt-1">

<h2 class="mt-1">Bloco editorial</h2>
<p>Faixa de destaque exibida na home (foto + texto). É um conteúdo único — não faz
   parte da lista de banners acima.</p>

<?php
$bloco_tipo  = cfg('bloco_editorial_tipo_midia', 'foto') === 'video' ? 'video' : 'foto';
$bloco_img   = cfg('bloco_editorial_imagem', '');
$bloco_video = cfg('bloco_editorial_video', '');
?>
<form class="formulario" method="post" action="<?= e(url('admin/banners')) ?>"
      enctype="multipart/form-data" style="max-width:640px;" data-bloco-form>
    <?= csrf_input() ?>
    <input type="hidden" name="op" value="bloco_salvar">

    <div class="campo">
        <label>Tipo de mídia</label>
        <div class="campo-inline" style="gap:1.2rem;">
            <label class="campo-inline" style="gap:.4rem; font-weight:400;">
                <input type="radio" name="bloco_editorial_tipo_midia" value="foto"
                       <?= $bloco_tipo === 'foto' ? 'checked' : '' ?> data-bloco-tipo> Foto
            </label>
            <label class="campo-inline" style="gap:.4rem; font-weight:400;">
                <input type="radio" name="bloco_editorial_tipo_midia" value="video"
                       <?= $bloco_tipo === 'video' ? 'checked' : '' ?> data-bloco-tipo> Vídeo
            </label>
        </div>
    </div>

    <!-- FOTO -->
    <div data-bloco-midia="foto"<?= $bloco_tipo === 'foto' ? '' : ' hidden' ?>>
        <?php if ($bloco_img !== '' && is_file(ROOT_PATH . '/assets/uploads/' . $bloco_img)): ?>
            <div class="campo">
                <label>Imagem atual</label>
                <img src="<?= e(asset('assets/uploads/' . $bloco_img)) ?>" alt=""
                     style="max-width:100%;border-radius:var(--raio);">
            </div>
        <?php endif; ?>
        <div class="campo">
            <label for="bloco_editorial_imagem">Imagem (deixe vazio para manter a atual)</label>
            <input type="file" id="bloco_editorial_imagem" name="bloco_editorial_imagem"
                   accept="image/jpeg,image/png,image/webp">
            <small>A imagem é otimizada automaticamente (máx. 1200px, JPEG).</small>
        </div>
    </div>

    <!-- VÍDEO -->
    <div data-bloco-midia="video"<?= $bloco_tipo === 'video' ? '' : ' hidden' ?>>
        <?php if ($bloco_video !== '' && is_file(ROOT_PATH . '/assets/uploads/' . $bloco_video)): ?>
            <div class="campo">
                <label>Vídeo atual</label>
                <video src="<?= e(asset('assets/uploads/' . $bloco_video)) ?>" muted loop autoplay playsinline
                       style="max-width:100%;border-radius:var(--raio);"></video>
            </div>
        <?php endif; ?>
        <div class="campo">
            <label for="bloco_editorial_video">Vídeo (deixe vazio para manter o atual)</label>
            <input type="file" id="bloco_editorial_video" name="bloco_editorial_video"
                   accept="video/mp4,video/webm">
            <small>Use um vídeo curto (5–10 segundos), sem som. Ele toca sozinho em loop,
                   como um fundo animado. Máx. 15 MB.</small>
        </div>
    </div>
    <div class="campo">
        <label for="bloco_editorial_titulo">Título</label>
        <input type="text" id="bloco_editorial_titulo" name="bloco_editorial_titulo"
               value="<?= e(cfg('bloco_editorial_titulo', '')) ?>">
    </div>
    <div class="campo">
        <label for="bloco_editorial_subtitulo">Subtítulo</label>
        <textarea id="bloco_editorial_subtitulo" name="bloco_editorial_subtitulo" rows="3"><?= e(cfg('bloco_editorial_subtitulo', '')) ?></textarea>
    </div>
    <div class="campo">
        <label for="bloco_editorial_botao_texto">Texto do botão</label>
        <input type="text" id="bloco_editorial_botao_texto" name="bloco_editorial_botao_texto"
               value="<?= e(cfg('bloco_editorial_botao_texto', '')) ?>">
    </div>
    <div class="campo">
        <label for="bloco_editorial_botao_link">Link do botão</label>
        <input type="text" id="bloco_editorial_botao_link" name="bloco_editorial_botao_link"
               value="<?= e(cfg('bloco_editorial_botao_link', '')) ?>"
               placeholder="Ex.: /colecoes ou https://wa.me/55...">
    </div>

    <button class="btn" type="submit">Salvar bloco editorial</button>
</form>

<script>
(function () {
    var form = document.querySelector('[data-bloco-form]');
    if (!form) { return; }
    var grupos = form.querySelectorAll('[data-bloco-midia]');
    function aplicar() {
        var sel = form.querySelector('[data-bloco-tipo]:checked');
        var tipo = sel ? sel.value : 'foto';
        grupos.forEach(function (g) {
            g.hidden = (g.getAttribute('data-bloco-midia') !== tipo);
        });
    }
    form.querySelectorAll('[data-bloco-tipo]').forEach(function (r) {
        r.addEventListener('change', aplicar);
    });
    aplicar();
})();
</script>
<?php
view('admin_layout', ['titulo' => 'Home', 'conteudo' => ob_get_clean()]);
