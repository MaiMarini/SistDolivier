<?php
/**
 * Admin: gerenciamento de produtos. Rotas:
 *   /admin/produtos               -> listar (por categoria)
 *   /admin/produtos/novo          -> formulário (mesmo do editar; view admin-produto-form)
 *   /admin/produtos/editar/{id}   -> formulário
 *   /admin/produtos/tabelas       -> tabelas nutricionais em JSON (recarregar a lista no formulário)
 *   POST op=foto_enviar           -> envia uma foto na hora (fica temporária até salvar; JSON)
 *   POST op=salvar                -> cria/atualiza tudo numa transação (fetch: JSON; sem JS: redirect)
 *   POST op=nome_existe           -> há outro produto com este nome? (JSON)
 *   POST op=excluir               -> exclui produto (+ fotos, ligações e arquivos sem uso)
 *   Listagem (fetch, JSON; exigem CSRF):
 *   POST op=lista_preco           -> altera o preço (reais -> centavos)
 *   POST op=lista_ativo           -> mostra/oculta na loja
 *   POST op=lista_mover           -> sobe/desce dentro da categoria (dir=up|down)
 *   POST op=lista_duplicar        -> cria uma cópia oculta logo abaixo (devolve o HTML da linha)
 */
exigir_admin();

/** Próxima posição no fim de uma categoria (null = sem categoria). */
function _produto_proxima_ordem(?int $category_id): int
{
    $st = db()->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM products WHERE category_id <=> ?');
    $st->execute([$category_id]);
    return (int) $st->fetchColumn();
}

/** Grava a ordem 1..N de uma lista de ids (já na ordem desejada). */
function _produto_gravar_ordem(array $ids): void
{
    $up = db()->prepare('UPDATE products SET ordem = ? WHERE id = ?');
    foreach (array_values($ids) as $i => $pid) {
        $up->execute([$i + 1, (int) $pid]);
    }
}

/** Ids da categoria na ordem da loja (todos, inclusive ocultos), travando as linhas. */
function _produto_ids_da_categoria(?int $category_id): array
{
    $st = db()->prepare('SELECT id FROM products WHERE category_id <=> ? ORDER BY ordem ASC, id ASC FOR UPDATE');
    $st->execute([$category_id]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** O arquivo de imagem ainda é usado por algum produto (galeria ou capa)? */
function _produto_arquivo_em_uso(string $arquivo): bool
{
    $st = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM product_images WHERE arquivo = ?) + (SELECT COUNT(*) FROM products WHERE imagem = ?)'
    );
    $st->execute([$arquivo, $arquivo]);
    return (int) $st->fetchColumn() > 0;
}

/** Copia um arquivo de upload (e a miniatura) com nome novo. Devolve o nome novo ou null. */
function _produto_copiar_arquivo(string $arquivo): ?string
{
    $dir = imagem_dir_uploads();
    if ($arquivo === '' || basename($arquivo) !== $arquivo || !is_file($dir . '/' . $arquivo)) {
        return null;
    }
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION)) ?: 'jpg';
    $base = uniqid('img_', false);
    if (!@copy($dir . '/' . $arquivo, $dir . '/' . $base . '.' . $ext)) {
        return null;
    }
    $thumb = preg_replace('/\.jpg$/i', '-thumb.jpg', $arquivo);
    if ($thumb !== $arquivo && is_file($dir . '/' . $thumb)) {
        @copy($dir . '/' . $thumb, $dir . '/' . $base . '-thumb.jpg');
    }
    return $base . '.' . $ext;
}

/**
 * Uma linha da listagem. $p: id, nome, imagem, preco_centavos, ativo, category_id,
 * categoria, vendidos (30 dias), pedidos (todos), tem_tabela. $ctx: primeiro/ultimo
 * (posição na categoria), busca (bool), visivel (bool).
 */
function _produto_linha_html(array $p, array $ctx): string
{
    $id = (int) $p['id'];
    $nome = (string) $p['nome'];
    $ativo = (int) $p['ativo'] === 1;
    $preco = (int) $p['preco_centavos'];
    $pedidos = (int) ($p['pedidos'] ?? 0);
    $busca = !empty($ctx['busca']);
    $seta = function (string $dir, bool $limite) use ($nome, $busca): string {
        $rot = ($dir === 'up' ? 'Subir ' : 'Descer ') . $nome;
        return '<button type="button" class="pr-seta" data-pr-mover="' . $dir . '" aria-label="' . e($rot) . '"'
            . ($busca ? ' title="Limpe a busca para reordenar"' : '')
            . (($busca || $limite) ? ' disabled' : '') . '>' . ($dir === 'up' ? '▲' : '▼') . '</button>';
    };
    ob_start();
    ?>
    <div class="pr-item<?= $ativo ? '' : ' is-oculto' ?>" data-pr-item data-id="<?= $id ?>" data-nome="<?= e($nome) ?>"
         data-chave="<?= e(mb_strtolower(trim($nome), 'UTF-8')) ?>" data-cat="<?= $p['category_id'] !== null ? (int) $p['category_id'] : 'sem' ?>"
         data-cat-nome="<?= e($p['categoria'] ?? 'Sem categoria') ?>" data-ativo="<?= $ativo ? 1 : 0 ?>" data-pedidos="<?= $pedidos ?>"
         <?= !empty($ctx['visivel']) ? '' : 'hidden' ?>>
        <div class="pr-ordem"><?= $seta('up', !empty($ctx['primeiro'])) ?><?= $seta('down', !empty($ctx['ultimo'])) ?></div>
        <?php if (!empty($p['imagem'])): ?>
            <img class="pr-thumb" src="<?= e(url('assets/uploads/' . imagem_miniatura($p['imagem']))) ?>" alt="" loading="lazy">
        <?php else: ?>
            <span class="pr-thumb pr-thumb-vazia" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($nome), 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
        <?php endif; ?>
        <div class="pr-nome">
            <b><?= e($nome) ?></b>
            <span class="ap-sub"><?= $vend = (int) ($p['vendidos'] ?? 0) ?> vendido<?= $vend === 1 ? '' : 's' ?> (30 dias)</span>
            <span class="pr-aviso" data-pr-repetido hidden>Nome repetido</span>
            <?php if (empty($p['tem_tabela'])): ?><span class="pr-aviso">Sem tabela nutricional</span><?php endif; ?>
        </div>
        <div class="pr-ctrl">
            <label class="pr-preco">
                <span>Preço</span>
                <input type="text" inputmode="decimal" autocomplete="off" data-pr-preco
                       value="<?= $preco > 0 ? e(centavos_para_input($preco)) : '' ?>"
                       placeholder="<?= $preco > 0 ? '' : 'Sob consulta' ?>" aria-label="<?= e('Preço de ' . $nome) ?>">
            </label>
            <button type="button" class="pr-sw" role="switch" aria-checked="<?= $ativo ? 'true' : 'false' ?>" data-pr-ativo
                    aria-label="<?= e('Na loja: ' . $nome) ?>"><span class="pr-sw-trilho" aria-hidden="true"></span><span data-pr-sw-txt><?= $ativo ? 'Na loja' : 'Oculto' ?></span></button>
            <div class="pr-acoes">
                <a class="ap-btn ap-btn-linha pr-editar" href="<?= e(url('admin/produtos/editar/' . $id)) ?>">Editar</a>
                <button type="button" class="pr-kebab" data-pr-menu aria-haspopup="menu" aria-expanded="false"
                        aria-label="<?= e('Mais ações para ' . $nome) ?>">⋯</button>
                <div class="pr-menu" role="menu" hidden>
                    <button type="button" role="menuitem" data-pr-duplicar>Duplicar produto</button>
                    <button type="button" role="menuitem" data-pr-alternar><?= $ativo ? 'Ocultar da loja' : 'Mostrar na loja' ?></button>
                    <button type="button" role="menuitem" class="is-perigo" data-pr-excluir>Excluir</button>
                    <?php if ($pedidos > 0): ?>
                        <span class="pr-menu-dica">Este produto já foi vendido. Para manter o histórico dos pedidos, prefira ocultar.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Marca a "última alteração" do produto (sem a coluna — migração não rodada —, ignora). */
function _produto_tocar(int $id): void
{
    try {
        db()->prepare('UPDATE products SET atualizado_em = NOW() WHERE id = ?')->execute([$id]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S22') { throw $e; }
    }
}

/** "agora mesmo", "há 5 min", "há 3 h", "ontem", "há 4 dias" ou "em 02/09/2026". */
function _produto_quando(int $segundos, string $data): string
{
    if ($segundos < 60) { return 'agora mesmo'; }
    if ($segundos < 3600) { return 'há ' . intdiv($segundos, 60) . ' min'; }
    if ($segundos < 86400) { return 'há ' . intdiv($segundos, 3600) . ' h'; }
    if ($segundos < 2 * 86400) { return 'ontem'; }
    if ($segundos < 30 * 86400) { return 'há ' . intdiv($segundos, 86400) . ' dias'; }
    return 'em ' . date('d/m/Y', strtotime($data));
}

/** Novo token de rascunho do formulário (fotos enviadas antes de salvar), guardado na sessão. */
function _produto_rascunho_novo(): string
{
    $agora = time();
    $lista = array_filter((array) ($_SESSION['prod_rascunhos'] ?? []), fn ($t) => $t > $agora - 86400);
    $token = bin2hex(random_bytes(16));
    $lista[$token] = $agora;
    $_SESSION['prod_rascunhos'] = array_slice($lista, -30, null, true);
    return $token;
}

function _produto_rascunho_valido(string $token): bool
{
    return preg_match('/^[a-f0-9]{32}$/', $token) === 1 && isset($_SESSION['prod_rascunhos'][$token]);
}

/** Apaga as fotos temporárias abandonadas há mais de 24 h (registros e arquivos). */
function _produto_limpar_temporarias(): void
{
    try {
        $velhas = db()->query('SELECT id, arquivo FROM product_upload_temp WHERE criado_em < NOW() - INTERVAL 24 HOUR')->fetchAll();
    } catch (PDOException $e) {
        return;
    }
    $del = db()->prepare('DELETE FROM product_upload_temp WHERE id = ?');
    foreach ($velhas as $t) {
        $del->execute([(int) $t['id']]);
        if (!_produto_arquivo_em_uso($t['arquivo'])) {
            imagem_apagar($t['arquivo']);
        }
    }
}

/** URL da miniatura de um arquivo de upload. */
function _produto_thumb_url(string $arquivo): string
{
    return url('assets/uploads/' . imagem_miniatura($arquivo));
}

/** Fotos do produto para o formulário: [{chave: "e:ID", url}] na ordem (capa primeiro). */
function _produto_fotos_form(int $pid): array
{
    $st = db()->prepare('SELECT id, arquivo FROM product_images WHERE product_id = ? ORDER BY ordem ASC, id ASC');
    $st->execute([$pid]);
    return array_map(fn ($i) => ['chave' => 'e:' . (int) $i['id'], 'url' => _produto_thumb_url($i['arquivo'])], $st->fetchAll());
}

/**
 * Sincroniza as tabelas nutricionais associadas a um produto: remove as antigas
 * e insere as selecionadas (na ordem em que vieram). INSERT IGNORE evita erro se
 * algum id inválido for enviado.
 */
function _produto_salvar_tabelas(int $pid, array $ids): void
{
    db()->prepare('DELETE FROM produto_tabelas_nutricionais WHERE produto_id = ?')->execute([$pid]);
    $ins = db()->prepare(
        'INSERT IGNORE INTO produto_tabelas_nutricionais (produto_id, tabela_nutricional_id, ordem)
         VALUES (?, ?, ?)'
    );
    $ordem = 0;
    foreach ($ids as $tid) {
        $tid = (int) $tid;
        if ($tid > 0) {
            $ins->execute([$pid, $tid, $ordem]);
            $ordem++;
        }
    }
}

// =============================================================================
// POST
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';

    // ------------------------------------------- AÇÕES DA LISTAGEM (fetch/JSON)
    if (in_array($op, ['lista_preco', 'lista_ativo', 'lista_mover', 'lista_duplicar'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        $responder = function (int $http, array $dados): void {
            http_response_code($http);
            echo json_encode($dados, JSON_UNESCAPED_UNICODE);
        };
        if (!csrf_validar()) {
            $responder(403, ['ok' => false, 'erro' => 'csrf']);
            return;
        }
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $st = db()->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([(int) $id]);
        $prod = $id ? $st->fetch() : false;
        if (!$prod) {
            $responder(404, ['ok' => false, 'erro' => 'produto']);
            return;
        }

        if ($op === 'lista_preco') {
            // Aceita "10", "10,5", "10,50", "1.234,56" ou "10.50"; nada além disso.
            $txt = trim((string) ($_POST['preco'] ?? ''));
            $valido = preg_match('/^(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$|^\d+\.\d{1,2}$/', $txt) === 1;
            $centavos = $valido ? reais_para_centavos($txt) : 0;
            if ($centavos <= 0 || $centavos > 99999999) {
                $responder(422, ['ok' => false, 'erro' => 'preco']);
                return;
            }
            db()->prepare('UPDATE products SET preco_centavos = ? WHERE id = ?')->execute([$centavos, $id]);
            _produto_tocar($id);
            $responder(200, ['ok' => true, 'centavos' => $centavos, 'valor' => centavos_para_input($centavos), 'texto' => money($centavos)]);
            return;
        }

        if ($op === 'lista_ativo') {
            $ativo = ($_POST['ativo'] ?? '') === '1' ? 1 : (($_POST['ativo'] ?? '') === '0' ? 0 : null);
            if ($ativo === null) {
                $responder(422, ['ok' => false, 'erro' => 'ativo']);
                return;
            }
            db()->prepare('UPDATE products SET ativo = ? WHERE id = ?')->execute([$ativo, $id]);
            _produto_tocar($id);
            $responder(200, ['ok' => true, 'ativo' => $ativo]);
            return;
        }

        if ($op === 'lista_mover') {
            $dir = $_POST['dir'] ?? '';
            if (!in_array($dir, ['up', 'down'], true)) {
                $responder(422, ['ok' => false, 'erro' => 'dir']);
                return;
            }
            // Troca com o vizinho da MESMA categoria (todos, inclusive ocultos) e renumera 1..N.
            $cat = $prod['category_id'] !== null ? (int) $prod['category_id'] : null;
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $ids = _produto_ids_da_categoria($cat);
                $i = array_search((int) $id, $ids, true);
                $j = $dir === 'up' ? $i - 1 : $i + 1;
                if ($i === false || $j < 0 || $j >= count($ids)) {
                    $pdo->rollBack();
                    $responder(409, ['ok' => false, 'erro' => 'limite']);
                    return;
                }
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                _produto_gravar_ordem($ids);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $responder(500, ['ok' => false, 'erro' => 'db']);
                return;
            }
            $responder(200, ['ok' => true, 'vizinho' => $ids[$i]]);
            return;
        }

        // lista_duplicar: cópia oculta logo abaixo, com tabelas nutricionais e fotos (arquivos copiados).
        $nome = mb_substr($prod['nome'] . ' (cópia)', 0, 150);
        $slug = slug_unico('products', gerar_slug($nome));
        $fixas = ['id', 'slug', 'nome', 'ativo', 'ordem', 'imagem', 'criado_em', 'atualizado_em'];
        $colunas = array_values(array_diff(
            db()->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND EXTRA NOT LIKE '%GENERATED%'
                          ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN),
            $fixas
        ));
        $lista = implode(', ', array_map(fn ($c) => '`' . str_replace('`', '', $c) . '`', $colunas));
        $cat = $prod['category_id'] !== null ? (int) $prod['category_id'] : null;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $ids = _produto_ids_da_categoria($cat);
            $pdo->prepare("INSERT INTO products (slug, nome, ativo, imagem, $lista)
                           SELECT ?, ?, 0, NULL, $lista FROM products WHERE id = ?")->execute([$slug, $nome, $id]);
            $novo = (int) $pdo->lastInsertId();
            try {
                $pdo->prepare('INSERT INTO produto_tabelas_nutricionais (produto_id, tabela_nutricional_id, ordem)
                               SELECT ?, tabela_nutricional_id, ordem FROM produto_tabelas_nutricionais WHERE produto_id = ?')
                    ->execute([$novo, $id]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '42S02') { throw $e; }   // sem a tabela (instalação antiga)
            }
            $pos = array_search((int) $id, $ids, true);
            array_splice($ids, $pos === false ? count($ids) : $pos + 1, 0, [$novo]);
            _produto_gravar_ordem($ids);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $responder(500, ['ok' => false, 'erro' => 'db']);
            return;
        }

        // Fotos: copia os arquivos (a cópia nunca divide arquivo com o original).
        $st = db()->prepare('SELECT arquivo, ordem FROM product_images WHERE product_id = ? ORDER BY ordem ASC, id ASC');
        $st->execute([$id]);
        $ins = db()->prepare('INSERT INTO product_images (product_id, arquivo, ordem) VALUES (?, ?, ?)');
        $copias = [];   // arquivo original => cópia
        foreach ($st->fetchAll() as $img) {
            $copia = _produto_copiar_arquivo((string) $img['arquivo']);
            if ($copia !== null) {
                $ins->execute([$novo, $copia, (int) $img['ordem']]);
                $copias[$img['arquivo']] = $copia;
            }
        }
        // Capa = cópia da capa original; se ela não está na galeria (cadastros antigos), copia à parte.
        $capa = $copias[$prod['imagem'] ?? ''] ?? null;
        if ($capa === null && !empty($prod['imagem'])) {
            $capa = _produto_copiar_arquivo((string) $prod['imagem']);
        }
        if ($capa === null && $copias) {
            $capa = reset($copias);
        }
        if ($capa !== null) {
            db()->prepare('UPDATE products SET imagem = ? WHERE id = ?')->execute([$capa, $novo]);
        }

        $st = db()->prepare('SELECT EXISTS (SELECT 1 FROM produto_tabelas_nutricionais WHERE produto_id = ?)');
        try { $st->execute([$novo]); $tem_tabela = (bool) $st->fetchColumn(); } catch (PDOException $e) { $tem_tabela = false; }
        $cat_nome = null;
        if ($cat !== null) {
            $st = db()->prepare('SELECT nome FROM categories WHERE id = ?');
            $st->execute([$cat]);
            $cat_nome = $st->fetchColumn() ?: null;
        }
        $html = _produto_linha_html([
            'id' => $novo, 'nome' => $nome, 'imagem' => $capa, 'preco_centavos' => $prod['preco_centavos'], 'ativo' => 0,
            'category_id' => $cat, 'categoria' => $cat_nome, 'vendidos' => 0, 'pedidos' => 0, 'tem_tabela' => $tem_tabela,
        ], ['visivel' => true]);
        $responder(200, ['ok' => true, 'id' => $novo, 'html' => $html]);
        return;
    }

    // ------------------------------------------- FOTO NOVA (fetch/JSON, envio na hora)
    // Fica em product_upload_temp, presa ao token de rascunho do formulário, até salvar.
    if ($op === 'foto_enviar') {
        header('Content-Type: application/json; charset=utf-8');
        $falha = function (int $http, string $msg): void {
            http_response_code($http);
            echo json_encode(['ok' => false, 'mensagem' => $msg], JSON_UNESCAPED_UNICODE);
        };
        if (!csrf_validar()) {
            $falha(403, 'Sua sessão expirou. Recarregue a página.');
            return;
        }
        $token = (string) ($_POST['rascunho'] ?? '');
        if (!_produto_rascunho_valido($token)) {
            $falha(422, 'Formulário expirado. Recarregue a página.');
            return;
        }
        _produto_limpar_temporarias();
        $arq = $_FILES['foto'] ?? null;
        if (!is_array($arq) || is_array($arq['error'] ?? null)) {
            $falha(422, 'Envio de arquivo inválido.');
            return;
        }
        // Tipo real pelo conteúdo (assinatura), não pela extensão nem pelo tipo informado.
        if (($arq['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($arq['tmp_name'])) {
            $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $arq['tmp_name']) : '';
            if ($mime !== '' && !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                $falha(422, 'Formato não suportado. Use JPG, PNG ou WebP.');
                return;
            }
        }
        try {
            $st = db()->prepare('SELECT COUNT(*) FROM product_upload_temp WHERE token = ?');
            $st->execute([$token]);
            if ((int) $st->fetchColumn() >= 20) {   // folga para trocas; o limite de 8 vale ao salvar
                $falha(422, 'Muitas fotos enviadas neste formulário. Salve ou recarregue a página.');
                return;
            }
        } catch (PDOException $e) {
            $falha(500, 'Falta rodar a migração do editor de produto (migracao_produto_editor.sql).');
            return;
        }
        $res = processar_upload_imagem($arq);   // otimiza (GD) e gera o nome no servidor
        if (empty($res['ok'])) {
            $falha(422, $res['erro'] ?? 'Não foi possível enviar a foto.');
            return;
        }
        db()->prepare('INSERT INTO product_upload_temp (token, arquivo) VALUES (?, ?)')->execute([$token, $res['arquivo']]);
        echo json_encode(['ok' => true, 'chave' => 't:' . (int) db()->lastInsertId(), 'url' => _produto_thumb_url($res['arquivo'])]);
        return;
    }

    // ------------------------------------------------------------------ SALVAR
    // Tudo de uma vez, numa transação: campos, chaves, fotos (ordem, novas e
    // removidas) e tabelas nutricionais. Com fetch responde JSON; sem JS, redireciona.
    if ($op === 'salvar') {
        $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
        $id = (int) ($_POST['id'] ?? 0);
        $voltar_form = $id > 0 ? 'admin/produtos/editar/' . $id : 'admin/produtos/novo';
        $responder = function (int $http, array $dados) use ($ajax, $voltar_form): void {
            if (!$ajax) {
                if (empty($dados['ok'])) {
                    flash('erro', $dados['mensagem'] ?? implode(' ', (array) ($dados['erros'] ?? [])));
                    redirect($voltar_form);
                }
                flash('sucesso', $dados['mensagem'] ?? 'Produto salvo.');
                redirect($dados['ir'] ?? $voltar_form);
            }
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($http);
            echo json_encode($dados, JSON_UNESCAPED_UNICODE);
        };
        if (!csrf_validar()) {
            $responder(403, ['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página e tente de novo.']);
            return;
        }
        $antes = null;
        if ($id > 0) {
            $st = db()->prepare('SELECT * FROM products WHERE id = ?');
            $st->execute([$id]);
            $antes = $st->fetch();
            if (!$antes) {
                $responder(404, ['ok' => false, 'mensagem' => 'Produto não encontrado.']);
                return;
            }
        }
        $token = (string) ($_POST['rascunho'] ?? '');
        $token_ok = _produto_rascunho_valido($token);

        $nome     = trim((string) ($_POST['nome'] ?? ''));
        $slug_txt = trim((string) ($_POST['slug'] ?? ''));
        $cat_txt  = (string) ($_POST['category_id'] ?? '');
        $preco_tx = trim((string) ($_POST['preco'] ?? ''));
        $dias_tx  = trim((string) ($_POST['dias_producao'] ?? ''));
        $descricao = str_replace("\r\n", "\n", trim((string) ($_POST['descricao'] ?? '')));
        $regras    = str_replace("\r\n", "\n", trim((string) ($_POST['regras_produto'] ?? '')));
        $ativo     = ($_POST['ativo'] ?? '') === '1' ? 1 : 0;
        $destaque  = ($_POST['destaque'] ?? '') === '1' ? 1 : 0;
        $pers      = ($_POST['permite_personalizacao'] ?? '') === '1' ? 1 : 0;

        $erros = [];
        if (mb_strlen($nome) < 2) {
            $erros['nome'] = 'Informe o nome do produto.';
        } elseif (mb_strlen($nome) > 150) {
            $erros['nome'] = 'Use no máximo 150 caracteres.';
        }
        $category_id = ctype_digit($cat_txt) ? (int) $cat_txt : 0;
        if ($category_id > 0) {
            $st = db()->prepare('SELECT 1 FROM categories WHERE id = ?');
            $st->execute([$category_id]);
            if (!$st->fetchColumn()) { $category_id = 0; }
        }
        if ($category_id <= 0) {
            $erros['category_id'] = 'Escolha a categoria.';
        }
        $preco = 0;
        if ($preco_tx === '') {
            if (!$pers) { $erros['preco'] = 'Informe o preço (ou ligue “Aceita personalização”).'; }
        } else {
            $valido = preg_match('/^(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$|^\d+\.\d{1,2}$/', $preco_tx) === 1;
            $preco = $valido ? reais_para_centavos($preco_tx) : 0;
            if ($preco <= 0 || $preco > 99999999) {
                $erros['preco'] = 'Preço inválido. Use, por exemplo, 35,90.';
            }
        }
        if ($dias_tx !== '' && !preg_match('/^\d{1,4}$/', $dias_tx)) {
            $erros['dias_producao'] = 'Use um número inteiro de dias (0 ou mais).';
        }
        $dias = (int) $dias_tx;
        if (mb_strlen($descricao) > 1200) { $erros['descricao'] = 'Use no máximo 1200 caracteres.'; }
        if (mb_strlen($regras) > 600) { $erros['regras_produto'] = 'Use no máximo 600 caracteres.'; }

        // Fotos na ordem da grade: "e:ID" (já do produto) ou "t:ID" (temporária deste rascunho).
        $fotos_in = array_values(array_unique(array_filter((array) ($_POST['fotos'] ?? []), fn ($c) => is_string($c) && preg_match('/^[et]:\d+$/', $c))));
        if (count($fotos_in) > 8) { $erros['fotos'] = 'Use no máximo 8 fotos.'; }
        $tabelas_in = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['tabelas'] ?? [])), fn ($v) => $v > 0)));

        if ($erros) {
            $responder(422, ['ok' => false, 'erros' => $erros, 'mensagem' => 'Confira os campos destacados.']);
            return;
        }

        // Endereço: normaliza e, se já existir em outro produto, usa -2, -3…
        $base = mb_substr(gerar_slug($slug_txt !== '' ? $slug_txt : $nome), 0, 140) ?: 'produto';
        $slug = slug_unico('products', rtrim($base, '-'), $id > 0 ? $id : null);
        $aviso_slug = $slug !== $base ? 'O endereço “' . $base . '” já era de outro produto; este ficou em “' . $slug . '”.' : null;

        $pdo = db();
        $removidos = [];
        $pdo->beginTransaction();
        try {
            if ($antes) {
                $cat_antes = $antes['category_id'] === null ? null : (int) $antes['category_id'];
                if ($cat_antes !== $category_id) {
                    $pdo->prepare('UPDATE products SET ordem = ? WHERE id = ?')->execute([_produto_proxima_ordem($category_id), $id]);
                }
                $pdo->prepare(
                    'UPDATE products
                        SET nome = ?, slug = ?, descricao = ?, regras_produto = ?, preco_centavos = ?, category_id = ?,
                            dias_producao = ?, destaque = ?, permite_personalizacao = ?, ativo = ?
                      WHERE id = ?'
                )->execute([$nome, $slug, $descricao !== '' ? $descricao : null, $regras !== '' ? $regras : null,
                    $preco, $category_id, $dias, $destaque, $pers, $ativo, $id]);
            } else {
                $pdo->prepare(
                    'INSERT INTO products (slug, nome, descricao, regras_produto, preco_centavos, category_id, ordem,
                                           dias_producao, destaque, permite_personalizacao, ativo)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$slug, $nome, $descricao !== '' ? $descricao : null, $regras !== '' ? $regras : null,
                    $preco, $category_id, _produto_proxima_ordem($category_id), $dias, $destaque, $pers, $ativo]);
                $id = (int) $pdo->lastInsertId();
            }
            _produto_tocar($id);

            try {
                _produto_salvar_tabelas($id, $tabelas_in);
            } catch (PDOException $e) {
                if ($e->getCode() !== '42S02') { throw $e; }
            }

            // Fotos: mantém/reordena as do produto, liga as temporárias, apaga as que saíram.
            $st = $pdo->prepare('SELECT id, arquivo FROM product_images WHERE product_id = ? FOR UPDATE');
            $st->execute([$id]);
            $atuais = $st->fetchAll(PDO::FETCH_KEY_PAIR);   // id => arquivo
            $up_ordem = $pdo->prepare('UPDATE product_images SET ordem = ? WHERE id = ? AND product_id = ?');
            $ins_img = $pdo->prepare('INSERT INTO product_images (product_id, arquivo, ordem) VALUES (?, ?, ?)');
            $pega_tmp = $pdo->prepare('SELECT arquivo FROM product_upload_temp WHERE id = ? AND token = ? FOR UPDATE');
            $del_tmp = $pdo->prepare('DELETE FROM product_upload_temp WHERE id = ?');
            $mantidos = [];
            $capa = null;
            $pos = 1;
            foreach ($fotos_in as $chave) {
                [$tipo, $fid] = explode(':', $chave);
                $fid = (int) $fid;
                if ($tipo === 'e' && isset($atuais[$fid])) {
                    $up_ordem->execute([$pos, $fid, $id]);
                    $mantidos[$fid] = true;
                    $arq = $atuais[$fid];
                } elseif ($tipo === 't' && $token_ok) {
                    $pega_tmp->execute([$fid, $token]);
                    $arq = $pega_tmp->fetchColumn();
                    if ($arq === false) { continue; }
                    $ins_img->execute([$id, $arq, $pos]);
                    $del_tmp->execute([$fid]);
                } else {
                    continue;
                }
                $capa = $capa ?? $arq;
                $pos++;
            }
            $del_img = $pdo->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?');
            foreach ($atuais as $iid => $arq) {
                if (!isset($mantidos[$iid])) {
                    $del_img->execute([$iid, $id]);
                    $removidos[] = $arq;
                }
            }
            $pdo->prepare('UPDATE products SET imagem = ? WHERE id = ?')->execute([$capa, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('produto salvar: ' . $e->getMessage());
            $responder(500, ['ok' => false, 'mensagem' => 'Não foi possível salvar. Tente de novo.']);
            return;
        }

        // Depois do banco: arquivos das fotos removidas e temporárias que sobraram deste rascunho.
        if ($token_ok) {
            try {
                $st = db()->prepare('SELECT id, arquivo FROM product_upload_temp WHERE token = ?');
                $st->execute([$token]);
                foreach ($st->fetchAll() as $t) {
                    db()->prepare('DELETE FROM product_upload_temp WHERE id = ?')->execute([(int) $t['id']]);
                    $removidos[] = $t['arquivo'];
                }
            } catch (PDOException $e) {
                // sem a tabela temporária: nada a limpar
            }
        }
        foreach (array_unique($removidos) as $arq) {
            if (!_produto_arquivo_em_uso($arq)) {
                imagem_apagar($arq);
            }
        }

        if (!$antes) {
            if ($ajax) { flash('sucesso', 'Produto criado.' . ($aviso_slug ? ' ' . $aviso_slug : '')); }
            $responder(200, ['ok' => true, 'mensagem' => 'Produto criado.', 'ir' => 'admin/produtos/editar/' . $id,
                'redirecionar' => url('admin/produtos/editar/' . $id)]);
            return;
        }
        $responder(200, [
            'ok' => true, 'mensagem' => 'Produto salvo.', 'aviso_slug' => $aviso_slug, 'slug' => $slug,
            'url_loja' => url('produto/' . $slug), 'quando' => 'agora mesmo', 'fotos' => _produto_fotos_form($id),
        ]);
        return;
    }

    // ------------------------------------------- CHECAR NOME REPETIDO (AJAX/JSON)
    // Retorna se já existe OUTRO produto com o mesmo nome (ignora o próprio id).
    if ($op === 'nome_existe') {
        header('Content-Type: application/json; charset=utf-8');
        if (!csrf_validar()) {
            echo json_encode(['ok' => false, 'erro' => 'csrf']);
            return;
        }
        $nome = trim($_POST['nome'] ?? '');
        $id   = (int) ($_POST['id'] ?? 0);
        $existe = false;
        if ($nome !== '') {
            $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE nome = ? AND id <> ?');
            $stmt->execute([$nome, $id]);
            $existe = ((int) $stmt->fetchColumn()) > 0;
        }
        echo json_encode(['ok' => true, 'existe' => $existe]);
        return;
    }

    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/produtos');
    }

    // ----------------------------------------------------------------- EXCLUIR
    // Exclusão definitiva: os itens de pedido guardam nome e preço próprios e a FK
    // order_items.product_id é ON DELETE SET NULL, então os pedidos antigos continuam.
    if ($op === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        $voltar = (string) ($_POST['voltar'] ?? '');
        $destino = 'admin/produtos' . (preg_match('/^[\w%=&+.\-]*$/', $voltar) && $voltar !== '' ? '?' . $voltar : '');
        $st = db()->prepare('SELECT nome, imagem FROM products WHERE id = ?');
        $st->execute([$id]);
        $prod = $id > 0 ? $st->fetch() : false;
        if ($prod) {
            $st = db()->prepare('SELECT arquivo FROM product_images WHERE product_id = ?');
            $st->execute([$id]);
            $arquivos = array_unique(array_filter(array_merge($st->fetchAll(PDO::FETCH_COLUMN), [(string) $prod['imagem']])));

            $pdo = db();
            $pdo->beginTransaction();
            try {
                try {
                    $pdo->prepare('DELETE FROM produto_tabelas_nutricionais WHERE produto_id = ?')->execute([$id]);
                } catch (PDOException $e) {
                    if ($e->getCode() !== '42S02') { throw $e; }
                }
                $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('erro', 'Não foi possível excluir o produto.');
                redirect($destino);
            }
            // Arquivos só depois do banco, e só os que nenhum outro produto usa.
            foreach ($arquivos as $arq) {
                if (!_produto_arquivo_em_uso($arq)) {
                    imagem_apagar($arq);
                }
            }
            flash('sucesso', '“' . $prod['nome'] . '” excluído.');
        }
        redirect($destino);
    }

    redirect('admin/produtos');
}

// =============================================================================
// GET
// =============================================================================
$acao = $params[0] ?? 'listar';

// Tabelas nutricionais em JSON (o formulário recarrega a lista ao voltar para a aba).
if ($acao === 'tabelas') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $tabs = db()->query('SELECT id, nome FROM tabelas_nutricionais ORDER BY nome ASC')->fetchAll();
    } catch (PDOException $e) {
        $tabs = [];
    }
    echo json_encode(['ok' => true, 'tabelas' => array_map(fn ($t) => ['id' => (int) $t['id'], 'nome' => $t['nome']], $tabs)], JSON_UNESCAPED_UNICODE);
    return;
}

// ------------------------------------------------------- NOVO / EDITAR (mesmo formulário)
if ($acao === 'novo' || $acao === 'editar') {
    $novo = $acao === 'novo';
    $produto = [
        'id' => 0, 'nome' => '', 'slug' => '', 'descricao' => '', 'regras_produto' => '', 'preco_centavos' => 0,
        'category_id' => null, 'dias_producao' => 0, 'destaque' => 0, 'permite_personalizacao' => 0, 'ativo' => 1,
    ];
    $fotos = [];
    $ligadas = [];
    $quando = '';
    if (!$novo) {
        $id = (int) ($params[1] ?? 0);
        $st = db()->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) {
            flash('erro', 'Produto não encontrado.');
            redirect('admin/produtos');
        }
        $produto = $p;
        // Capa antiga fora da galeria (cadastros de antes da galeria): entra como 1ª foto.
        if (!empty($p['imagem'])) {
            $st = db()->prepare('SELECT 1 FROM product_images WHERE product_id = ? AND arquivo = ?');
            $st->execute([$id, $p['imagem']]);
            if (!$st->fetchColumn()) {
                db()->prepare('INSERT INTO product_images (product_id, arquivo, ordem) VALUES (?, ?, 0)')->execute([$id, $p['imagem']]);
            }
        }
        $fotos = _produto_fotos_form($id);
        try {
            $st = db()->prepare('SELECT tabela_nutricional_id FROM produto_tabelas_nutricionais WHERE produto_id = ? ORDER BY ordem ASC');
            $st->execute([$id]);
            $ligadas = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            $ligadas = [];
        }
        $st = db()->prepare('SELECT TIMESTAMPDIFF(SECOND, ?, NOW())');
        $ref = !empty($p['atualizado_em']) ? $p['atualizado_em'] : $p['criado_em'];
        $st->execute([$ref]);
        $quando = _produto_quando((int) $st->fetchColumn(), (string) $ref);
    }
    try {
        $tabelas = db()->query('SELECT id, nome FROM tabelas_nutricionais ORDER BY nome ASC')->fetchAll();
    } catch (PDOException $e) {
        $tabelas = [];
    }
    $categorias = db()->query('SELECT id, nome FROM categories ORDER BY ordem ASC, id ASC')->fetchAll();
    $voltar = (string) ($_GET['voltar'] ?? '');
    $voltar = preg_match('/^[\w%=&+.\-]*$/', $voltar) ? $voltar : '';
    $host = parse_url(url(), PHP_URL_HOST) ?: 'dolivier.com.br';

    ob_start();
    view('admin-produto-form', [
        'produto'    => $produto,
        'novo'       => $novo,
        'categorias' => $categorias,
        'tabelas'    => $tabelas,
        'ligadas'    => $ligadas,
        'fotos'      => $fotos,
        'rascunho'   => _produto_rascunho_novo(),
        'quando'     => $quando,
        'voltar_url' => url('admin/produtos') . ($voltar !== '' ? '?' . $voltar : ''),
        'prefixo'    => preg_replace('/^www\./', '', $host) . '/produto/',
    ]);
    view('admin_layout', [
        'titulo'       => $novo ? 'Novo produto' : 'Editar produto',
        'sem_titulo'   => true,
        'conteudo'     => ob_get_clean(),
        'layout_largo' => true,
    ]);
    return;
}

// ----------------------------------------------------------------- LISTAGEM
// Agrupada por categoria (na ordem do menu da loja), com os produtos na ordem da loja.
// Filtros na URL (?st=loja|ocultos|todos&cat=ID|sem&q=...); o JS filtra sem recarregar.
$f = [
    'st'  => in_array($_GET['st'] ?? '', ['loja', 'ocultos', 'todos'], true) ? $_GET['st'] : 'loja',
    'cat' => ($_GET['cat'] ?? '') === 'sem' ? 'sem' : (ctype_digit((string) ($_GET['cat'] ?? '')) ? (string) (int) $_GET['cat'] : ''),
    'q'   => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
];

$cats = db()->query('SELECT id, nome FROM categories ORDER BY ordem ASC, id ASC')->fetchAll();
$produtos = db()->query(
    'SELECT p.id, p.nome, p.imagem, p.preco_centavos, p.ativo, p.category_id, c.nome AS categoria
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      ORDER BY p.ordem ASC, p.id ASC'
)->fetchAll();

// Vendidos nos últimos 30 dias (pedidos pagos e não cancelados) e nº de pedidos de cada produto: uma consulta para a página.
$vendidos = db()->query(
    "SELECT oi.product_id, SUM(oi.quantidade) AS qtd
       FROM order_items oi
       JOIN orders o ON o.id = oi.order_id
      WHERE oi.product_id IS NOT NULL
        AND o.status NOT IN ('aguardando_pagamento','cancelado')
        AND COALESCE(o.pago_em, o.criado_em) >= NOW() - INTERVAL 30 DAY
      GROUP BY oi.product_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$pedidos_por_produto = db()->query(
    'SELECT product_id, COUNT(DISTINCT order_id) FROM order_items WHERE product_id IS NOT NULL GROUP BY product_id'
)->fetchAll(PDO::FETCH_KEY_PAIR);
try {
    $com_tabela = array_flip(db()->query('SELECT DISTINCT produto_id FROM produto_tabelas_nutricionais')->fetchAll(PDO::FETCH_COLUMN));
} catch (PDOException $e) {
    $com_tabela = [];
}

$na_loja = count(array_filter($produtos, fn ($p) => (int) $p['ativo'] === 1));
$ocultos = count($produtos) - $na_loja;
$ABAS = ['loja' => ['Na loja', $na_loja], 'ocultos' => ['Ocultos', $ocultos], 'todos' => ['Todos', count($produtos)]];

/** O produto entra nos filtros atuais? (o JS repete esta regra ao filtrar sem recarregar) */
$q_min = mb_strtolower($f['q'], 'UTF-8');
$visivel = function (array $p) use ($f, $q_min): bool {
    if ($f['st'] !== 'todos' && ((int) $p['ativo'] === 1) !== ($f['st'] === 'loja')) { return false; }
    $cat = $p['category_id'] !== null ? (string) (int) $p['category_id'] : 'sem';
    if ($f['cat'] !== '' && $f['cat'] !== $cat) { return false; }
    return $q_min === '' || mb_strpos(mb_strtolower($p['nome'], 'UTF-8'), $q_min) !== false;
};

// Grupos: categorias na ordem do menu; "Sem categoria" no fim (só se houver).
$grupos = [];
foreach ($cats as $c) {
    $grupos[(string) (int) $c['id']] = ['nome' => $c['nome'], 'itens' => []];
}
$grupos['sem'] = ['nome' => 'Sem categoria', 'itens' => []];
foreach ($produtos as $p) {
    $k = $p['category_id'] !== null && isset($grupos[(string) (int) $p['category_id']]) ? (string) (int) $p['category_id'] : 'sem';
    $p['vendidos'] = (int) ($vendidos[$p['id']] ?? 0);
    $p['pedidos'] = (int) ($pedidos_por_produto[$p['id']] ?? 0);
    $p['tem_tabela'] = isset($com_tabela[$p['id']]);
    $grupos[$k]['itens'][] = $p;
}
$tem_sem_categoria = !empty($grupos['sem']['itens']);

$qs = fn (array $mudar) => http_build_query(array_filter(array_merge($f, $mudar), fn ($v) => $v !== '' && $v !== 'loja'));

ob_start();
?>
<div class="pr" data-pr data-url="<?= e(url('admin/produtos')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <div data-pr-confirmar></div>

    <nav class="pr-abas" aria-label="Situação">
        <?php foreach ($ABAS as $k => [$rot, $n]): $s = $qs(['st' => $k]); ?>
            <a class="ap-aba" href="<?= e(url('admin/produtos') . ($s !== '' ? '?' . $s : '')) ?>" data-pr-aba="<?= e($k) ?>"
               <?= $f['st'] === $k ? 'aria-current="page"' : '' ?>><?= e($rot) ?> <span class="ap-count" data-pr-conta="<?= e($k) ?>"><?= (int) $n ?></span></a>
        <?php endforeach; ?>
    </nav>

    <form class="ap-filtros" method="get" action="<?= e(url('admin/produtos')) ?>" data-pr-filtros>
        <input type="hidden" name="st" value="<?= e($f['st']) ?>" data-pr-st>
        <label class="ap-campo ap-busca" for="pr-q">Buscar
            <input id="pr-q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nome do produto" autocomplete="off" data-pr-q>
        </label>
        <label class="ap-campo" for="pr-cat">Categoria
            <select id="pr-cat" name="cat" data-pr-cat>
                <option value="">Todas</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $f['cat'] === (string) (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                <?php endforeach; ?>
                <?php if ($tem_sem_categoria): ?>
                    <option value="sem" <?= $f['cat'] === 'sem' ? 'selected' : '' ?>>Sem categoria</option>
                <?php endif; ?>
            </select>
        </label>
        <noscript><button class="ap-btn ap-btn-linha" type="submit">Filtrar</button></noscript>
    </form>

    <p class="ap-sub pr-ajuda">A ordem aqui é a ordem em que os produtos aparecem na loja, dentro de cada categoria. O preço pode ser alterado direto na linha.</p>

    <?php if (!$produtos): ?>
        <p class="pr-vazio">Nenhum produto cadastrado. <a href="<?= e(url('admin/produtos/novo')) ?>">Cadastrar o primeiro</a>.</p>
    <?php endif; ?>

    <?php foreach ($grupos as $k => $g): if (!$g['itens']) { continue; }
        $n_vis = count(array_filter($g['itens'], $visivel)); $ultimo = count($g['itens']) - 1; ?>
        <section class="pr-grupo" data-pr-grupo="<?= e($k) ?>" aria-label="<?= e($g['nome']) ?>" <?= $n_vis ? '' : 'hidden' ?>>
            <div class="pr-grupo-topo">
                <h2><?= e($g['nome']) ?></h2>
                <span class="ap-sub" data-pr-grupo-conta><?= $n_vis ?> produto<?= $n_vis === 1 ? '' : 's' ?></span>
            </div>
            <div class="pr-itens">
                <?php foreach ($g['itens'] as $i => $p): ?>
                    <?= _produto_linha_html($p, ['primeiro' => $i === 0, 'ultimo' => $i === $ultimo, 'busca' => $f['q'] !== '', 'visivel' => $visivel($p)]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <p class="pr-vazio" data-pr-nada <?= $produtos && !array_filter($produtos, $visivel) ? '' : 'hidden' ?>>Nenhum produto com esses filtros.</p>

    <div class="pr-toast" data-pr-toast role="status" aria-live="polite" hidden></div>
</div>
<script src="<?= e(asset('assets/js/admin-produtos.js')) ?>"></script>
<?php
view('admin_layout', [
    'titulo'       => 'Produtos',
    'subtitulo'    => $na_loja . ' na loja · ' . $ocultos . ' oculto' . ($ocultos === 1 ? '' : 's'),
    'titulo_acoes' => '<a class="ap-btn ap-btn-primario" href="' . e(url('admin/produtos/novo')) . '">+ Novo produto</a>',
    'conteudo'     => ob_get_clean(),
    'layout_largo' => true,
]);
