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

/** Upload da mídia animada do bloco editorial: MP4/WebM/GIF, tipo real validado, máx. 15 MB. */
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

    // 1) Extensão (MP4, WebM ou GIF).
    $ext = strtolower(pathinfo($arquivo['name'] ?? '', PATHINFO_EXTENSION));
    $exts_ok = ['mp4', 'webm', 'gif'];
    if (!in_array($ext, $exts_ok, true)) {
        return ['ok' => false, 'erro' => 'Formato não suportado. Envie um vídeo MP4/WebM ou um GIF.'];
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
    // Aceita os MIME esperados. application/octet-stream é tolerado apenas para
    // vídeo (mp4/webm), porque alguns servidores não identificam o container;
    // o GIF é sempre detectado corretamente como image/gif.
    $mimes_ok = ['video/mp4', 'video/webm', 'video/x-m4v', 'image/gif'];
    $mime_ok = in_array($mime, $mimes_ok, true)
        || ($mime === 'application/octet-stream' && $ext !== 'gif');
    if (!$mime_ok) {
        return ['ok' => false, 'erro' => 'Formato não suportado. Envie um vídeo MP4/WebM ou um GIF.'];
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
    $op = $_POST['op'] ?? '';

    // --- Reordenação via AJAX (responde JSON, sem redirect) ------------------
    // Reescreve a coluna `ordem` de TODOS os banners em sequência (1..N).
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
                $up = $pdo->prepare('UPDATE banners SET ordem = ? WHERE id = ?');
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
        redirect('admin/banners');
    }

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

    // Imagem lateral (sticky) da seção "Coleções" (registro único em settings).
    if ($op === 'colecoes_salvar') {
        // Só troca se uma nova imagem for enviada; senão, mantém a atual.
        if (!empty($_FILES['colecoes_imagem_lateral']['name'])) {
            $res = processar_upload_imagem($_FILES['colecoes_imagem_lateral'], ['gerar_miniatura' => false]);
            if (empty($res['ok'])) {
                flash('erro', $res['erro'] ?? 'Falha ao enviar a imagem da seção Coleções.');
                redirect('admin/banners');
            }
            $antiga = cfg('colecoes_imagem_lateral', '');
            if ($antiga !== '') {
                imagem_apagar($antiga);
            }
            $stmt = db()->prepare(
                'INSERT INTO settings (chave, valor) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE valor = ?'
            );
            $stmt->execute(['colecoes_imagem_lateral', $res['arquivo'], $res['arquivo']]);
            flash('sucesso', 'Imagem da seção Coleções salva.');
        } else {
            flash('erro', 'Escolha uma imagem para enviar.');
        }
        redirect('admin/banners');
    }

    // ----- Frases do marquee (edita tudo e salva de uma vez; máx. 5) -----
    if ($op === 'frases_salvar') {
        $textos = $_POST['frase'] ?? [];
        if (!is_array($textos)) {
            $textos = [];
        }

        // Limpa: apara, ignora linhas vazias, corta a 120 chars, limita a 5.
        $limpos = [];
        foreach ($textos as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > 120) {
                $t = mb_substr($t, 0, 120);
            }
            $limpos[] = $t;
            if (count($limpos) >= 5) {
                break;
            }
        }

        // Sincroniza a tabela com a lista final, numa transação (sem FKs: o
        // caminho seguro é limpar e reinserir na ordem enviada = ordem).
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM marquee_frases');
            $ins = $pdo->prepare('INSERT INTO marquee_frases (texto, ordem) VALUES (?, ?)');
            foreach ($limpos as $i => $t) {
                $ins->execute([$t, $i + 1]);
            }
            $pdo->commit();
            flash('sucesso', 'Frases do marquee salvas.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            flash('erro', 'Não foi possível salvar as frases. Tente novamente.');
        }
        redirect('admin/banners');
    }

    if ($op === 'salvar') {
        $id     = (int) ($_POST['id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        $link   = trim($_POST['link'] ?? '');
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
            // Edição não mexe na ordem (definida por arrastar/setas na listagem).
            if ($novo_arquivo !== null) {
                $stmt = db()->prepare('SELECT imagem FROM banners WHERE id = ?');
                $stmt->execute([$id]);
                $antigo = $stmt->fetchColumn();
                if ($antigo) {
                    imagem_apagar($antigo);
                }
                $stmt = db()->prepare(
                    'UPDATE banners SET imagem = ?, titulo = ?, link = ?, ativo = ? WHERE id = ?'
                );
                $stmt->execute([$novo_arquivo, $titulo, $link, $ativo, $id]);
            } else {
                $stmt = db()->prepare(
                    'UPDATE banners SET titulo = ?, link = ?, ativo = ? WHERE id = ?'
                );
                $stmt->execute([$titulo, $link, $ativo, $id]);
            }
            flash('sucesso', 'Banner atualizado.');
            redirect('admin/banners');
        }

        // Criação: imagem é obrigatória. Entra no fim da lista.
        if ($novo_arquivo === null) {
            flash('erro', 'Envie uma imagem para o banner.');
            redirect('admin/banners/novo');
        }
        $ordem = (int) db()->query('SELECT COALESCE(MAX(ordem), 0) FROM banners')->fetchColumn() + 1;
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
            <input class="input-arquivo" type="file" id="imagem" name="imagem"
                   accept="image/jpeg,image/png,image/webp" data-arquivo-nome>
            <div class="arquivo-linha">
                <label for="imagem" class="btn btn-arquivo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 15V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14l4-4h6" />
                        <line x1="18" y1="14" x2="18" y2="20" />
                        <line x1="15" y1="17" x2="21" y2="17" />
                    </svg>
                    Escolher foto
                </label>
                <span class="arquivo-info" data-arquivo-nome-alvo>Nenhum arquivo escolhido</span>
            </div>
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
    <p><small>Arraste pela alça (☰) para reordenar; no celular, use as setas ↑↓.</small></p>
    <table class="tabela">
        <thead>
            <tr>
                <th class="t-centro"></th>
                <th class="t-centro"></th>
                <th>Título</th>
                <th class="t-centro">Ativo</th>
                <th class="col-acoes">Ações</th>
            </tr>
        </thead>
        <tbody id="banners-tbody">
            <?php foreach ($banners as $b): ?>
                <tr data-id="<?= (int) $b['id'] ?>">
                    <td class="col-mover t-centro">
                        <span class="arrastar-handle" title="Arraste para reordenar" aria-hidden="true">&#9776;</span>
                        <span class="ordenar-setas">
                            <button type="button" class="btn-seta" data-subir aria-label="Mover para cima">&uarr;</button>
                            <button type="button" class="btn-seta" data-descer aria-label="Mover para baixo">&darr;</button>
                        </span>
                    </td>
                    <td class="t-centro" style="width:90px;">
                        <?php if (!empty($b['imagem'])): ?>
                            <img src="<?= e(url('assets/uploads/' . imagem_miniatura($b['imagem']))) ?>"
                                 alt="" style="width:80px;height:45px;object-fit:cover;border-radius:6px;">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= e($b['titulo'] ?: '—') ?></td>
                    <td class="t-centro"><?= $b['ativo'] ? 'Sim' : 'Não' ?></td>
                    <td class="col-acoes">
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

    <div id="banners-feedback" class="reorder-feedback" hidden></div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script>
    (function () {
        var tbody = document.getElementById('banners-tbody');
        if (!tbody) { return; }
        var endpoint = <?= json_encode(url('admin/banners')) ?>;
        var csrf = <?= json_encode(csrf_token()) ?>;
        var feedback = document.getElementById('banners-feedback');

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

<hr class="mt-1">

<h2 class="mt-1">Frases do marquee</h2>
<p>Frases curtas que passam na faixa da home. Máximo de 5. Edite tudo e salve de uma vez.</p>

<form class="formulario" method="post" action="<?= e(url('admin/banners')) ?>"
      style="max-width:640px;" data-marquee-form>
    <?= csrf_input() ?>
    <input type="hidden" name="op" value="frases_salvar">

    <div data-marquee-lista>
        <?php foreach ($frases as $f): ?>
            <div class="campo campo-inline marquee-linha" data-marquee-linha>
                <input type="text" name="frase[]" value="<?= e($f['texto']) ?>"
                       maxlength="120" style="flex:1;" placeholder="Texto da frase">
                <button type="button" class="btn" data-marquee-remover>Remover</button>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Modelo de linha nova (inerte: não é enviado no submit). -->
    <template data-marquee-modelo>
        <div class="campo campo-inline marquee-linha" data-marquee-linha>
            <input type="text" name="frase[]" maxlength="120" style="flex:1;" placeholder="Texto da frase">
            <button type="button" class="btn" data-marquee-remover>Remover</button>
        </div>
    </template>

    <div class="campo-inline" style="gap:.5rem;">
        <button type="button" class="btn" data-marquee-adicionar>Adicionar frase</button>
        <button type="submit" class="btn">Salvar</button>
    </div>
    <p data-marquee-limite hidden><em>Limite de 5 frases atingido.</em></p>
</form>

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
                <label>Mídia atual</label>
                <?php if (strtolower(pathinfo($bloco_video, PATHINFO_EXTENSION)) === 'gif'): ?>
                    <img src="<?= e(asset('assets/uploads/' . $bloco_video)) ?>" alt=""
                         style="max-width:100%;border-radius:var(--raio);">
                <?php else: ?>
                    <video src="<?= e(asset('assets/uploads/' . $bloco_video)) ?>" muted loop autoplay playsinline
                           style="max-width:100%;border-radius:var(--raio);"></video>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="campo">
            <label for="bloco_editorial_video">Vídeo ou GIF (deixe vazio para manter o atual)</label>
            <input class="input-arquivo" type="file" id="bloco_editorial_video" name="bloco_editorial_video"
                   accept="video/mp4,video/webm,image/gif" data-arquivo-nome>
            <div class="arquivo-linha">
                <label for="bloco_editorial_video" class="btn btn-arquivo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 15V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14l4-4h6" />
                        <line x1="18" y1="14" x2="18" y2="20" />
                        <line x1="15" y1="17" x2="21" y2="17" />
                    </svg>
                    Escolher arquivo
                </label>
                <span class="arquivo-info" data-arquivo-nome-alvo>Nenhum arquivo escolhido</span>
            </div>
            <small>Use um vídeo curto (5–10 segundos) ou GIF, sem som. Toca sozinho em loop,
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

<hr class="mt-1">

<h2 class="mt-1">Seção "Coleções"</h2>
<p>Imagem lateral fixa (sticky) exibida ao lado da grade de coleções na home.</p>

<?php $colecoes_img = cfg('colecoes_imagem_lateral', ''); ?>
<form class="formulario" method="post" action="<?= e(url('admin/banners')) ?>"
      enctype="multipart/form-data" style="max-width:640px;">
    <?= csrf_input() ?>
    <input type="hidden" name="op" value="colecoes_salvar">

    <?php if ($colecoes_img !== '' && is_file(ROOT_PATH . '/assets/uploads/' . $colecoes_img)): ?>
        <div class="campo">
            <label>Imagem atual</label>
            <img src="<?= e(asset('assets/uploads/' . $colecoes_img)) ?>" alt=""
                 style="max-width:100%;border-radius:var(--raio);">
        </div>
    <?php endif; ?>
    <div class="campo">
        <label for="colecoes_imagem_lateral">Imagem lateral (deixe vazio para manter a atual)</label>
        <input class="input-arquivo" type="file" id="colecoes_imagem_lateral" name="colecoes_imagem_lateral"
               accept="image/jpeg,image/png,image/webp" data-arquivo-nome>
        <div class="arquivo-linha">
            <label for="colecoes_imagem_lateral" class="btn btn-arquivo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 15V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14l4-4h6" />
                    <line x1="18" y1="14" x2="18" y2="20" />
                    <line x1="15" y1="17" x2="21" y2="17" />
                </svg>
                Escolher arquivo
            </label>
            <span class="arquivo-info" data-arquivo-nome-alvo>Nenhum arquivo escolhido</span>
        </div>
        <small>A imagem é otimizada automaticamente (máx. 1200px, JPEG).</small>
    </div>

    <button class="btn" type="submit">Salvar imagem das Coleções</button>
</form>
<?php
view('admin_layout', ['titulo' => 'Home', 'conteudo' => ob_get_clean()]);
