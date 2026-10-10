<?php
/**
 * Admin › Home: mapa da home (Banners, Faixa de frases, Coleções, Bloco editorial)
 * num formulário só. Rotas:
 *   GET  /admin/banners[?parte=banners|frases|col|ed]  -> tela
 *   GET  /admin/banners/novo | /editar/{id}            -> redireciona para ?parte=banners
 *   POST op=upload  (multipart)  -> sobe o arquivo para assets/uploads/tmp (temporário)
 *   POST op=salvar  (payload JSON) -> grava tudo numa transação; só então os
 *        temporários viram definitivos e os arquivos antigos são apagados.
 * Temporários abandonados há mais de 24 h são apagados.
 */
exigir_admin();

const HOME_TMP_HORAS = 24;

/** Pasta dos uploads temporários (criada na primeira vez). */
function _home_tmp_dir(): string
{
    $dir = imagem_dir_uploads() . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/** Apaga temporários com mais de 24 h. */
function _home_limpar_tmp(): void
{
    $limite = time() - HOME_TMP_HORAS * 3600;
    foreach (glob(_home_tmp_dir() . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < $limite) {
            @unlink($f);
        }
    }
}

/** Remove um arquivo comum de uploads (vídeo/GIF), sem sair da pasta. */
function _home_apagar_upload(string $nome): void
{
    $nome = basename($nome);
    if ($nome !== '' && $nome !== '.' && is_file(imagem_dir_uploads() . '/' . $nome)) {
        @unlink(imagem_dir_uploads() . '/' . $nome);
    }
}

/**
 * Vídeo do bloco editorial: MP4/WebM/GIF até 15 MB. O tipo vem da assinatura do
 * arquivo (não da extensão) e o nome é gerado aqui.
 */
function _home_upload_video(array $arquivo, string $destino): array
{
    $max = 15 * 1024 * 1024;
    $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE || ($arquivo['size'] ?? 0) > $max) {
        return ['ok' => false, 'erro' => 'O vídeo deve ter no máximo 15 MB.'];
    }
    $tmp = $arquivo['tmp_name'] ?? '';
    if ($erro !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'erro' => 'Falha no envio do vídeo. Tente novamente.'];
    }
    $cab = (string) file_get_contents($tmp, false, null, 0, 16);
    if (strncmp($cab, 'GIF87a', 6) === 0 || strncmp($cab, 'GIF89a', 6) === 0) {
        $ext = 'gif';
    } elseif (substr($cab, 4, 4) === 'ftyp') {
        $ext = 'mp4';
    } elseif (strncmp($cab, "\x1A\x45\xDF\xA3", 4) === 0) {
        $ext = 'webm';
    } else {
        return ['ok' => false, 'erro' => 'Formato não suportado. Envie um vídeo MP4/WebM ou um GIF.'];
    }
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($fi, $tmp);
        finfo_close($fi);
        $ok = $ext === 'gif' ? $mime === 'image/gif'
            : in_array($mime, ['video/mp4', 'video/webm', 'video/x-m4v', 'video/quicktime', 'application/octet-stream'], true);
        if (!$ok) {
            return ['ok' => false, 'erro' => 'Formato não suportado. Envie um vídeo MP4/WebM ou um GIF.'];
        }
    }
    $nome = uniqid('vid_', false) . '.' . $ext;
    if (!is_dir($destino) || !is_writable($destino) || !move_uploaded_file($tmp, $destino . '/' . $nome)) {
        return ['ok' => false, 'erro' => 'Não foi possível salvar o vídeo.'];
    }
    return ['ok' => true, 'arquivo' => $nome];
}

/** Categorias para o select (sem as excluídas), com ativo. */
function _home_categorias(): array
{
    foreach (['WHERE excluida_em IS NULL', ''] as $filtro) {
        try {
            return db()->query("SELECT id, nome, slug, ativo FROM categories $filtro ORDER BY ordem ASC, id ASC")->fetchAll();
        } catch (PDOException $e) {
            if ($filtro === '') {
                return [];
            }
        }
    }
    return [];
}

/** Banners com as colunas novas; null se a migração ainda não rodou. */
function _home_banners(): ?array
{
    try {
        return db()->query(
            'SELECT id, imagem, titulo, link, link_categoria_id, ativo, inicio, fim
               FROM banners ORDER BY ordem ASC, id ASC'
        )->fetchAll();
    } catch (PDOException $e) {
        return null;
    }
}

function _home_frases(): array
{
    try {
        return array_map(fn ($r) => (string) $r['texto'],
            db()->query('SELECT texto FROM marquee_frases ORDER BY ordem ASC, id ASC')->fetchAll());
    } catch (PDOException $e) {
        return [];
    }
}

/** Link gravado -> forma da tela: nenhum | categoria (id) | outro endereço. */
function _home_link(?int $cat, string $url): array
{
    if ($cat) {
        return ['tipo' => 'cat', 'cat' => $cat, 'url' => ''];
    }
    return $url !== '' ? ['tipo' => 'url', 'cat' => 0, 'url' => $url] : ['tipo' => 'nenhum', 'cat' => 0, 'url' => ''];
}

/** Limites de texto: o padrão, ou o tamanho do que já está gravado (nunca corta). */
function _home_limites(array $frases): array
{
    $len = fn (string $k) => mb_strlen((string) cfg($k, ''));
    return [
        'frase'  => max(30, ...array_map('mb_strlen', $frases ?: [''])),
        'titulo' => max(60, $len('bloco_editorial_titulo')),
        'sub'    => max(600, $len('bloco_editorial_subtitulo')),
        'btn'    => max(24, $len('bloco_editorial_botao_texto')),
        'banner' => 150,
        'url'    => 255,
    ];
}

/** Estado da tela (o mesmo formato vai e volta do navegador). */
function _home_estado(array $banners): array
{
    $up = fn (string $f) => url('assets/uploads/' . $f);
    $tem = fn (string $f) => $f !== '' && is_file(imagem_dir_uploads() . '/' . $f);

    $lista = [];
    foreach ($banners as $b) {
        $img = (string) $b['imagem'];
        $lista[] = [
            'cid'    => 'b' . $b['id'],
            'id'     => (int) $b['id'],
            'titulo' => (string) ($b['titulo'] ?? ''),
            'link'   => _home_link($b['link_categoria_id'] !== null ? (int) $b['link_categoria_id'] : null, (string) ($b['link'] ?? '')),
            'ativo'  => (bool) $b['ativo'],
            'inicio' => (string) ($b['inicio'] ?? ''),
            'fim'    => (string) ($b['fim'] ?? ''),
            'img'    => $tem($img) ? $up($img) : '',
            'thumb'  => $tem($img) ? $up(imagem_miniatura($img)) : '',
            'tmp'    => '',
        ];
    }

    $foto = (string) cfg('bloco_editorial_imagem', '');
    $video = (string) cfg('bloco_editorial_video', '');
    $col = (string) cfg('colecoes_imagem_lateral', '');
    $cat = (int) cfg('bloco_editorial_botao_categoria_id', 0);
    return [
        'banners' => $lista,
        'frases'  => _home_frases(),
        'ed'      => [
            'tipo'   => cfg('bloco_editorial_tipo_midia', 'foto') === 'video' ? 'video' : 'foto',
            'foto'   => ['url' => $tem($foto) ? $up($foto) : '', 'tmp' => ''],
            'video'  => ['url' => $tem($video) ? $up($video) : '', 'tmp' => '', 'gif' => strtolower(pathinfo($video, PATHINFO_EXTENSION)) === 'gif'],
            'titulo' => (string) cfg('bloco_editorial_titulo', ''),
            'sub'    => (string) cfg('bloco_editorial_subtitulo', ''),
            'btn'    => (string) cfg('bloco_editorial_botao_texto', ''),
            'link'   => _home_link($cat > 0 ? $cat : null, (string) cfg('bloco_editorial_botao_link', '')),
        ],
        'col'     => ['url' => $tem($col) ? $up($col) : '', 'tmp' => ''],
    ];
}

/** "Hoje" da loja (Rondônia), para o período dos banners. */
function _home_hoje(): string
{
    return (new DateTime('now', new DateTimeZone('America/Porto_Velho')))->format('Y-m-d');
}

// =============================================================================
// POST
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $responder = function (int $http, array $corpo): void {
        http_response_code($http);
        echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    };
    if (!csrf_validar()) {
        $responder(403, ['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página e tente de novo.']);
        return;
    }
    $op = $_POST['op'] ?? '';

    // --- Upload temporário ------------------------------------------------------------------------
    if ($op === 'upload') {
        _home_limpar_tmp();
        $tipo = (string) ($_POST['tipo'] ?? '');
        $arq = $_FILES['arquivo'] ?? null;
        if (!in_array($tipo, ['banner', 'col', 'ed_foto', 'ed_video'], true) || !is_array($arq) || is_array($arq['error'] ?? null)) {
            $responder(400, ['ok' => false, 'mensagem' => 'Envio de arquivo inválido.']);
            return;
        }
        $dir = _home_tmp_dir();
        if ($tipo === 'ed_video') {
            $res = _home_upload_video($arq, $dir);
        } else {
            $err = (int) ($arq['error'] ?? 0);
            $res = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
                ? ['ok' => false, 'erro' => 'A imagem excede o tamanho máximo de 5 MB.']
                : processar_upload_imagem($arq, ['destino' => $dir, 'gerar_miniatura' => $tipo === 'banner']);
        }
        if (empty($res['ok'])) {
            $responder(422, ['ok' => false, 'mensagem' => $res['erro'] ?? 'Não foi possível enviar o arquivo.']);
            return;
        }
        $nome = $res['arquivo'];
        $_SESSION['home_tmp'][$nome] = $tipo;
        $responder(200, ['ok' => true, 'tmp' => $nome, 'url' => url('assets/uploads/tmp/' . $nome),
                         'gif' => str_ends_with($nome, '.gif')]);
        return;
    }

    if ($op !== 'salvar') {
        $responder(400, ['ok' => false, 'mensagem' => 'Operação inválida.']);
        return;
    }

    // --- Salvar tudo ----------------------------------------------------------------------------
    $atuais = _home_banners();
    if ($atuais === null) {
        $responder(500, ['ok' => false, 'mensagem' => 'Rode a migração database/migracao_home_editor.sql antes de salvar.']);
        return;
    }
    $p = json_decode((string) ($_POST['payload'] ?? ''), true);
    if (!is_array($p)) {
        $responder(400, ['ok' => false, 'mensagem' => 'Dados inválidos. Recarregue a página.']);
        return;
    }
    $porId = [];
    foreach ($atuais as $b) {
        $porId[(int) $b['id']] = $b;
    }
    $lim = _home_limites(_home_frases());
    $erros = [];
    $str = fn ($v) => is_string($v) ? trim(str_replace("\r\n", "\n", $v)) : '';
    // Temporário enviado nesta sessão e do tipo certo -> nome; senão ''.
    $tmpOk = function ($nome, string $tipo): string {
        $nome = is_string($nome) ? basename($nome) : '';
        return $nome !== '' && ($_SESSION['home_tmp'][$nome] ?? '') === $tipo && is_file(_home_tmp_dir() . '/' . $nome) ? $nome : '';
    };
    // '' -> null; data válida -> 'Y-m-d'; inválida -> false.
    $data = function ($v) {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : false;
    };
    // Link: [categoria_id|null, url|''] ou mensagem de erro.
    $link = function ($l, string $chave) use (&$erros, $str): array {
        $tipo = is_array($l) ? ($l['tipo'] ?? 'nenhum') : 'nenhum';
        if ($tipo === 'cat') {
            $id = (int) ($l['cat'] ?? 0);
            if ($id <= 0 || !categoria_valida($id)) {
                $erros[$chave] = 'Escolha uma categoria da lista.';
            }
            return [$id, ''];
        }
        if ($tipo === 'url') {
            $u = $str($l['url'] ?? '');
            $relativo = preg_match('#^/(?!/)\S*$#', $u) === 1;
            if ($u === '') {
                $erros[$chave] = 'Digite o endereço ou escolha "Não leva a lugar nenhum".';
            } elseif (mb_strlen($u) > 255) {
                $erros[$chave] = 'Endereço longo demais (máx. 255).';
            } elseif (!$relativo && (stripos($u, 'https://') !== 0 || !filter_var($u, FILTER_VALIDATE_URL))) {
                $erros[$chave] = 'Use um endereço que comece com https:// (ou /, para uma página da loja).';
            }
            return [null, $u];
        }
        return [null, ''];
    };

    // Banners (na ordem da tela) e exclusões.
    $excluir = [];
    foreach ((array) ($p['excluidos'] ?? []) as $id) {
        if (isset($porId[(int) $id])) {
            $excluir[(int) $id] = true;
        }
    }
    $banners = [];
    foreach ((array) ($p['banners'] ?? []) as $b) {
        if (!is_array($b)) {
            continue;
        }
        $cid = preg_replace('/[^a-z0-9]/i', '', (string) ($b['cid'] ?? ''));
        $id = (int) ($b['id'] ?? 0);
        if ($id > 0 && (!isset($porId[$id]) || isset($excluir[$id]))) {
            continue;   // sumiu (outra aba) ou foi excluído
        }
        $k = 'b:' . $cid . ':';
        $titulo = $str($b['titulo'] ?? '');
        if (mb_strlen($titulo) > $lim['banner']) {
            $erros[$k . 'titulo'] = 'Máximo de ' . $lim['banner'] . ' caracteres.';
        }
        [$cat, $u] = $link($b['link'] ?? null, $k . 'url');
        $ini = $data($b['inicio'] ?? '');
        $fim = $data($b['fim'] ?? '');
        if ($ini === false) {
            $erros[$k . 'inicio'] = 'Data inválida.';
        }
        if ($fim === false) {
            $erros[$k . 'fim'] = 'Data inválida.';
        }
        if ($ini && $fim && $fim < $ini) {
            $erros[$k . 'fim'] = 'Tirar do ar não pode ser antes de “Mostrar a partir de”.';
        }
        $tmp = $tmpOk($b['tmp'] ?? '', 'banner');
        $imagem = $tmp !== '' ? $tmp : ($id > 0 ? (string) $porId[$id]['imagem'] : '');
        $ativo = !empty($b['ativo']);
        if ($ativo && $imagem === '') {
            $erros[$k . 'img'] = 'Escolha uma imagem antes de ligar o banner.';
        }
        $banners[] = compact('id', 'titulo', 'cat', 'u', 'ini', 'fim', 'tmp', 'imagem', 'ativo');
    }

    // Frases: vazias são ignoradas; ordem da tela.
    $frases = [];
    foreach (array_values((array) ($p['frases'] ?? [])) as $i => $f) {
        $f = $str($f);
        if ($f === '') {
            continue;
        }
        if (mb_strlen($f) > $lim['frase']) {
            $erros['fr:' . $i] = 'Máximo de ' . $lim['frase'] . ' letras.';
        }
        $frases[] = $f;
    }
    if (count($frases) > 5) {
        $erros['fr:geral'] = 'No máximo 5 frases.';
    }

    // Bloco editorial.
    $ed = is_array($p['ed'] ?? null) ? $p['ed'] : [];
    $edTipo = ($ed['tipo'] ?? '') === 'video' ? 'video' : 'foto';
    $edAntes = cfg('bloco_editorial_tipo_midia', 'foto') === 'video' ? 'video' : 'foto';
    foreach (['titulo' => 'titulo', 'sub' => 'sub', 'btn' => 'btn'] as $campo => $l) {
        if (mb_strlen($str($ed[$campo] ?? '')) > $lim[$l]) {
            $erros['ed:' . $campo] = 'Máximo de ' . $lim[$l] . ' caracteres.';
        }
    }
    [$edCat, $edUrl] = $link($ed['link'] ?? null, 'ed:url');
    $edTmp = $tmpOk($edTipo === 'foto' ? ($ed['foto']['tmp'] ?? '') : ($ed['video']['tmp'] ?? ''), $edTipo === 'foto' ? 'ed_foto' : 'ed_video');
    $edAtual = (string) cfg($edTipo === 'foto' ? 'bloco_editorial_imagem' : 'bloco_editorial_video', '');
    $edTemAtual = $edAtual !== '' && is_file(imagem_dir_uploads() . '/' . $edAtual);
    if ($edTipo !== $edAntes && $edTmp === '' && !$edTemAtual) {
        $erros['ed:midia'] = $edTipo === 'foto' ? 'Envie uma foto para o tipo escolhido.' : 'Envie um vídeo para o tipo escolhido.';
    }

    // Coleções.
    $colTmp = $tmpOk($p['col']['tmp'] ?? '', 'col');

    if ($erros) {
        $responder(422, ['ok' => false, 'erros' => $erros, 'mensagem' => 'Confira os campos marcados.']);
        return;
    }

    // --- Gravação (transação). Arquivos: temporário -> definitivo antes do commit;
    //     se der erro, voltam. Os antigos só são apagados depois do commit.
    $tmpDir = _home_tmp_dir();
    $upDir = imagem_dir_uploads();
    $movidos = [];      // [de, para]
    $apagarImg = [];    // imagens (com miniatura) antigas
    $apagarArq = [];    // vídeos/GIFs antigos
    $mover = function (string $nome, bool $comThumb) use (&$movidos, $tmpDir, $upDir): void {
        foreach ($comThumb ? [$nome, preg_replace('/\.jpg$/i', '-thumb.jpg', $nome)] : [$nome] as $f) {
            if (is_file("$tmpDir/$f")) {
                if (!@rename("$tmpDir/$f", "$upDir/$f")) {
                    throw new RuntimeException('Não foi possível mover ' . $f);
                }
                $movidos[] = ["$tmpDir/$f", "$upDir/$f"];
            }
        }
    };

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (array_keys($excluir) as $id) {
            $pdo->prepare('DELETE FROM banners WHERE id = ?')->execute([$id]);
            $apagarImg[] = (string) $porId[$id]['imagem'];
        }
        $upd = $pdo->prepare('UPDATE banners SET imagem = ?, titulo = ?, link = ?, link_categoria_id = ?, ordem = ?, ativo = ?, inicio = ?, fim = ? WHERE id = ?');
        $ins = $pdo->prepare('INSERT INTO banners (imagem, titulo, link, link_categoria_id, ordem, ativo, inicio, fim) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ordem = 0;
        foreach ($banners as $b) {
            $ordem++;
            if ($b['tmp'] !== '') {
                $mover($b['tmp'], true);
                if ($b['id'] > 0 && (string) $porId[$b['id']]['imagem'] !== '') {
                    $apagarImg[] = (string) $porId[$b['id']]['imagem'];
                }
            }
            $vals = [$b['imagem'], $b['titulo'] !== '' ? $b['titulo'] : null, $b['u'] !== '' ? $b['u'] : null,
                     $b['cat'] ?: null, $ordem, $b['ativo'] ? 1 : 0, $b['ini'] ?: null, $b['fim'] ?: null];
            if ($b['id'] > 0) {
                $upd->execute(array_merge($vals, [$b['id']]));
            } else {
                $ins->execute($vals);
            }
        }
        // Banners que não vieram na lista (ex.: criados em outra aba) vão para o fim.
        $vistos = array_filter(array_column($banners, 'id'));
        foreach ($porId as $id => $b) {
            if (!isset($excluir[$id]) && !in_array($id, $vistos, true)) {
                $pdo->prepare('UPDATE banners SET ordem = ? WHERE id = ?')->execute([++$ordem, $id]);
            }
        }

        // Frases: só reescreve se mudaram.
        if ($frases !== _home_frases()) {
            $pdo->exec('DELETE FROM marquee_frases');
            $insF = $pdo->prepare('INSERT INTO marquee_frases (texto, ordem) VALUES (?, ?)');
            foreach ($frases as $i => $f) {
                $insF->execute([$f, $i + 1]);
            }
        }

        // Settings: só as chaves que mudaram.
        $set = [
            'bloco_editorial_tipo_midia'        => $edTipo,
            'bloco_editorial_titulo'            => $str($ed['titulo'] ?? ''),
            'bloco_editorial_subtitulo'         => $str($ed['sub'] ?? ''),
            'bloco_editorial_botao_texto'       => $str($ed['btn'] ?? ''),
            'bloco_editorial_botao_link'        => $edUrl,
            'bloco_editorial_botao_categoria_id' => $edCat ? (string) $edCat : '',
        ];
        $chaveMidia = $edTipo === 'foto' ? 'bloco_editorial_imagem' : 'bloco_editorial_video';
        $chaveOutra = $edTipo === 'foto' ? 'bloco_editorial_video' : 'bloco_editorial_imagem';
        if ($edTmp !== '') {
            $mover($edTmp, false);
            $set[$chaveMidia] = $edTmp;
            if ($edAtual !== '' && $edTipo === 'foto') {
                $apagarImg[] = $edAtual;
            } elseif ($edAtual !== '') {
                $apagarArq[] = $edAtual;
            }
        }
        // Trocou de tipo: a mídia do outro tipo sai (como antes).
        $outra = (string) cfg($chaveOutra, '');
        if ($outra !== '') {
            $set[$chaveOutra] = '';
            if ($edTipo === 'foto') {
                $apagarArq[] = $outra;
            } else {
                $apagarImg[] = $outra;
            }
        }
        if ($colTmp !== '') {
            $mover($colTmp, false);
            $set['colecoes_imagem_lateral'] = $colTmp;
            if ((string) cfg('colecoes_imagem_lateral', '') !== '') {
                $apagarImg[] = (string) cfg('colecoes_imagem_lateral', '');
            }
        }
        $up = $pdo->prepare('INSERT INTO settings (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
        foreach ($set as $k => $v) {
            if ((string) cfg($k, '') !== $v) {
                $up->execute([$k, $v]);
                $GLOBALS['settings'][$k] = $v;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        foreach (array_reverse($movidos) as [$de, $para]) {
            @rename($para, $de);
        }
        error_log('[admin/home] salvar: ' . $e->getMessage());
        $responder(500, ['ok' => false, 'mensagem' => 'Não foi possível salvar. Tente de novo.']);
        return;
    }

    foreach (array_unique(array_filter($apagarImg)) as $f) {
        imagem_apagar($f);
    }
    foreach (array_unique(array_filter($apagarArq)) as $f) {
        _home_apagar_upload($f);
    }
    foreach ([$edTmp, $colTmp, ...array_column($banners, 'tmp')] as $f) {
        unset($_SESSION['home_tmp'][$f]);
    }
    $responder(200, ['ok' => true, 'mensagem' => 'Home atualizada na loja.', 'estado' => _home_estado(_home_banners() ?? [])]);
    return;
}

// =============================================================================
// GET
// =============================================================================
if (in_array($params[0] ?? '', ['novo', 'editar'], true)) {
    redirect('admin/banners?parte=banners');
}
_home_limpar_tmp();

$PARTES = ['banners' => 'Banners', 'frases' => 'Frases', 'col' => 'Coleções', 'ed' => 'Bloco editorial'];
$parte = isset($PARTES[$_GET['parte'] ?? '']) ? $_GET['parte'] : 'banners';
$banners = _home_banners();

if ($banners === null) {
    ob_start();
    ?>
    <section class="pe-card hm-migracao">
        <h2>Falta atualizar o banco</h2>
        <p>Rode o arquivo <code>database/migracao_home_editor.sql</code> no phpMyAdmin e recarregue esta página.</p>
    </section>
    <?php
    view('admin_layout', ['titulo' => 'Home', 'subtitulo' => 'O que aparece na página inicial da loja.', 'conteudo' => ob_get_clean()]);
    return;
}

$frases = _home_frases();
$dados = [
    'url'        => url('admin/banners'),
    'loja'       => url(),
    'csrf'       => csrf_token(),
    'parte'      => $parte,
    'hoje'       => _home_hoje(),
    'limites'    => _home_limites($frases),
    'categorias' => array_map(fn ($c) => ['id' => (int) $c['id'], 'nome' => (string) $c['nome'], 'ativo' => (bool) $c['ativo']], _home_categorias()),
    'estado'     => _home_estado($banners),
];

ob_start();
?>
<div class="hm" data-hm>
    <noscript><p class="pe-aviso">Esta tela precisa de JavaScript ligado.</p></noscript>
    <div class="hm-lay">
        <nav class="hm-mapa" aria-label="Partes da home" data-hm-mapa></nav>
        <div class="hm-edicao" data-hm-edicao></div>
    </div>

    <div class="pe-barra" role="region" aria-label="Salvar a home">
        <span class="pe-estado" data-hm-estado><i aria-hidden="true"></i><span data-hm-estado-txt>Tudo salvo</span></span>
        <a class="ap-btn ap-btn-linha hm-ver" href="<?= e(url()) ?>" target="_blank" rel="noopener">Ver a home</a>
        <button type="button" class="ap-btn ap-btn-linha" data-hm-descartar hidden>Descartar</button>
        <button type="button" class="ap-btn ap-btn-primario" data-hm-salvar disabled>Salvar alterações</button>
    </div>
    <div class="pe-toast" data-hm-toast role="status" aria-live="polite" hidden></div>
    <input type="file" data-hm-arquivo hidden>
</div>
<script type="application/json" id="hm-dados"><?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(asset('assets/js/admin-home.js')) ?>"></script>
<?php
view('admin_layout', [
    'titulo'       => 'Home',
    'subtitulo'    => 'O que aparece na página inicial da loja.',
    'layout_largo' => true,
    'conteudo'     => ob_get_clean(),
]);
