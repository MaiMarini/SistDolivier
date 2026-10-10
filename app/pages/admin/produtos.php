<?php
/**
 * Admin: gerenciamento de produtos + galeria de imagens. Rotas:
 *   /admin/produtos               -> listar
 *   /admin/produtos/novo          -> form de criação
 *   /admin/produtos/editar/{id}   -> form de edição + galeria
 *   POST op=salvar                -> cria/atualiza (preço em reais -> centavos)
 *   POST op=excluir               -> exclui produto (+ imagens: arquivos e registros)
 *   POST op=img_adicionar         -> upload de várias imagens (otimizadas)
 *   POST op=img_salvar_ordem      -> atualiza a ordem de uma imagem
 *   POST op=img_capa              -> define a capa (products.imagem)
 *   POST op=img_remover           -> remove uma imagem (arquivo + registro)
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

/** Busca uma imagem garantindo que pertence ao produto. */
function _produto_imagem(int $imagem_id, int $produto_id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, product_id, arquivo FROM product_images WHERE id = ? AND product_id = ? LIMIT 1'
    );
    $stmt->execute([$imagem_id, $produto_id]);
    $img = $stmt->fetch();
    return $img ?: null;
}

/**
 * Processa os arquivos enviados em $_FILES['imagens'] para um produto: otimiza
 * (GD), grava em product_images (na ordem seguinte à existente) e define a capa
 * (products.imagem) se ainda não houver. Usado tanto na criação quanto na galeria.
 * Retorna ['adicionadas' => int, 'erros' => string[]].
 */
function _produto_processar_imagens(int $pid): array
{
    $enviados = $_FILES['imagens'] ?? null;
    if (!is_array($enviados) || !isset($enviados['name']) || !is_array($enviados['name'])) {
        return ['adicionadas' => 0, 'erros' => []];
    }

    $stmt = db()->prepare('SELECT COALESCE(MAX(ordem), 0) FROM product_images WHERE product_id = ?');
    $stmt->execute([$pid]);
    $ordem = (int) $stmt->fetchColumn();

    $adicionadas = 0;
    $erros = [];
    $primeira_nova = null;

    $total = count($enviados['name']);
    for ($i = 0; $i < $total; $i++) {
        if (($enviados['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue; // campo vazio
        }
        $arquivo = [
            'name' => $enviados['name'][$i],
            'type' => $enviados['type'][$i] ?? '',
            'tmp_name' => $enviados['tmp_name'][$i] ?? '',
            'error' => $enviados['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $enviados['size'][$i] ?? 0,
        ];
        $res = processar_upload_imagem($arquivo);
        if (!empty($res['ok'])) {
            $ordem++;
            db()->prepare('INSERT INTO product_images (product_id, arquivo, ordem) VALUES (?, ?, ?)')
                ->execute([$pid, $res['arquivo'], $ordem]);
            $adicionadas++;
            if ($primeira_nova === null) {
                $primeira_nova = $res['arquivo'];
            }
        } else {
            $erros[] = $res['erro'] ?? 'Falha em uma imagem.';
        }
    }

    // Define a capa com a primeira imagem enviada, se ainda não houver.
    if ($primeira_nova !== null) {
        $st = db()->prepare('SELECT imagem FROM products WHERE id = ?');
        $st->execute([$pid]);
        if (!$st->fetchColumn()) {
            db()->prepare('UPDATE products SET imagem = ? WHERE id = ?')
                ->execute([$primeira_nova, $pid]);
        }
    }

    return ['adicionadas' => $adicionadas, 'erros' => $erros];
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
        $fixas = ['id', 'slug', 'nome', 'ativo', 'ordem', 'imagem', 'criado_em'];
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

    // ------------------------------------------- REORDENAR IMAGENS (AJAX/JSON)
    // Renumera a ordem de TODAS as imagens do produto (1..N) e define a capa
    // (products.imagem) como o arquivo da PRIMEIRA. Tudo numa transação.
    if ($op === 'img_reordenar') {
        header('Content-Type: application/json; charset=utf-8');
        if (!csrf_validar()) {
            echo json_encode(['ok' => false, 'erro' => 'csrf']);
            return;
        }
        $pid = (int) ($_POST['produto_id'] ?? 0);
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        }));

        if ($pid > 0 && !empty($ids)) {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $up = $pdo->prepare('UPDATE product_images SET ordem = ? WHERE id = ? AND product_id = ?');
                $sel = $pdo->prepare('SELECT arquivo FROM product_images WHERE id = ? AND product_id = ? LIMIT 1');
                $pos = 1;
                $capa = null;
                foreach ($ids as $iid) {
                    $up->execute([$pos, $iid, $pid]);
                    if ($pos === 1) {
                        $sel->execute([$iid, $pid]);
                        $capa = $sel->fetchColumn();
                    }
                    $pos++;
                }
                if ($capa !== null && $capa !== false) {
                    $pdo->prepare('UPDATE products SET imagem = ? WHERE id = ?')->execute([$capa, $pid]);
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

    // ------------------------------------------------------------------ SALVAR
    if ($op === 'salvar') {
        $id = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $regras = trim($_POST['regras_produto'] ?? '');
        $dias = (int) ($_POST['dias_producao'] ?? 0);
        $destaque = isset($_POST['destaque']) ? 1 : 0;
        $permite_pers = isset($_POST['permite_personalizacao']) ? 1 : 0;
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        $preco_centavos = reais_para_centavos($_POST['preco'] ?? '');

        $category_id = (int) ($_POST['category_id'] ?? 0);
        $category_id = $category_id > 0 ? $category_id : null;

        $tabelas_sel = (isset($_POST['tabelas']) && is_array($_POST['tabelas'])) ? $_POST['tabelas'] : [];

        $destino_erro = $id > 0 ? 'admin/produtos/editar/' . $id : 'admin/produtos/novo';

        if (mb_strlen($nome) < 2) {
            flash('erro', 'Informe o nome do produto.');
            redirect($destino_erro);
        }
        // Preço obrigatório quando NÃO for personalizável.
        if (!$permite_pers && $preco_centavos <= 0) {
            flash('erro', 'Informe um preço válido (ou marque "Permitir personalização").');
            redirect($destino_erro);
        }

        $base = gerar_slug($slug !== '' ? $slug : $nome);
        $slug = slug_unico('products', $base, $id > 0 ? $id : null);

        if ($id > 0) {
            // Trocou de categoria: vai para o fim da nova.
            $st = db()->prepare('SELECT category_id FROM products WHERE id = ?');
            $st->execute([$id]);
            $cat_antes = $st->fetchColumn();
            if ($cat_antes !== false && ($cat_antes === null ? null : (int) $cat_antes) !== $category_id) {
                db()->prepare('UPDATE products SET ordem = ? WHERE id = ?')
                    ->execute([_produto_proxima_ordem($category_id), $id]);
            }

            $stmt = db()->prepare(
                'UPDATE products
                    SET nome = ?, slug = ?, descricao = ?, regras_produto = ?,
                        preco_centavos = ?, category_id = ?, dias_producao = ?,
                        destaque = ?, permite_personalizacao = ?, ativo = ?
                  WHERE id = ?'
            );
            $stmt->execute([
                $nome,
                $slug,
                ($descricao !== '' ? $descricao : null),
                ($regras !== '' ? $regras : null),
                $preco_centavos,
                $category_id,
                $dias,
                $destaque,
                $permite_pers,
                $ativo,
                $id,
            ]);
            _produto_salvar_tabelas($id, $tabelas_sel);
            $r = _produto_processar_imagens($id);
            $msg = 'Produto atualizado.';
            if ($r['adicionadas'] > 0) {
                $msg .= ' ' . $r['adicionadas'] . ' imagem(ns) adicionada(s).';
            }
            flash('sucesso', $msg);
            if (!empty($r['erros'])) {
                flash('erro', implode(' ', $r['erros']));
            }
            redirect('admin/produtos/editar/' . $id);
        }

        // Produto novo entra no fim da categoria.
        $stmt = db()->prepare(
            'INSERT INTO products
                (slug, nome, descricao, regras_produto, preco_centavos, category_id, ordem,
                 dias_producao, destaque, permite_personalizacao, ativo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $slug,
            $nome,
            ($descricao !== '' ? $descricao : null),
            ($regras !== '' ? $regras : null),
            $preco_centavos,
            $category_id,
            _produto_proxima_ordem($category_id),
            $dias,
            $destaque,
            $permite_pers,
            $ativo,
        ]);
        $novo_id = (int) db()->lastInsertId();

        _produto_salvar_tabelas($novo_id, $tabelas_sel);

        // Processa as fotos escolhidas no cadastro (se houver): otimiza, grava
        // em product_images e define a capa. Nada é criado antes de salvar.
        $r = _produto_processar_imagens($novo_id);
        $msg = 'Produto criado.';
        if ($r['adicionadas'] > 0) {
            $msg .= ' ' . $r['adicionadas'] . ' imagem(ns) adicionada(s).';
        }
        flash('sucesso', $msg);
        if (!empty($r['erros'])) {
            flash('erro', implode(' ', $r['erros']));
        }
        redirect('admin/produtos/editar/' . $novo_id);
    }

    // -------------------------------------------------- AÇÕES DE IMAGEM (galeria)
    $pid = (int) ($_POST['produto_id'] ?? 0);
    if ($pid <= 0) {
        redirect('admin/produtos');
    }

    if ($op === 'img_adicionar') {
        $r = _produto_processar_imagens($pid);
        if ($r['adicionadas'] > 0) {
            flash('sucesso', $r['adicionadas'] . ' imagem(ns) adicionada(s).');
        }
        if (!empty($r['erros'])) {
            flash('erro', implode(' ', $r['erros']));
        }
        redirect('admin/produtos/editar/' . $pid);
    }

    if ($op === 'img_remover') {
        // Chamada via fetch (AJAX) responde JSON; sem JS, mantém o redirect.
        $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
        $imagem_id = (int) ($_POST['imagem_id'] ?? 0);
        $img = _produto_imagem($imagem_id, $pid);

        if (!$img) {
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'erro' => 'nao_encontrada']);
                return;
            }
            redirect('admin/produtos/editar/' . $pid);
        }

        imagem_apagar($img['arquivo']);
        db()->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?')
            ->execute([$imagem_id, $pid]);

        // Renumera o restante (1..N) e define a capa = primeira (ou limpa).
        $rest = db()->prepare(
            'SELECT id, arquivo FROM product_images WHERE product_id = ? ORDER BY ordem ASC, id ASC'
        );
        $rest->execute([$pid]);
        $rows = $rest->fetchAll();
        $capa = $rows[0]['arquivo'] ?? null;
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare('UPDATE product_images SET ordem = ? WHERE id = ?');
            $pos = 1;
            foreach ($rows as $im) {
                $up->execute([$pos, $im['id']]);
                $pos++;
            }
            $pdo->prepare('UPDATE products SET imagem = ? WHERE id = ?')->execute([$capa, $pid]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            if ($ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'erro' => 'db']);
                return;
            }
            flash('erro', 'Não foi possível remover a imagem.');
            redirect('admin/produtos/editar/' . $pid);
        }

        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'capa' => $capa]);
            return;
        }
        flash('sucesso', 'Imagem removida.');
        redirect('admin/produtos/editar/' . $pid);
    }

    redirect('admin/produtos');
}

// =============================================================================
// GET
// =============================================================================
$acao = $params[0] ?? 'listar';

// Categorias para o select (usado nos forms).
$categorias = db()->query('SELECT id, nome FROM categories ORDER BY nome ASC')->fetchAll();

if ($acao === 'novo' || $acao === 'editar') {
    $produto = [
        'id' => 0,
        'nome' => '',
        'slug' => '',
        'descricao' => '',
        'regras_produto' => '',
        'preco_centavos' => 0,
        'category_id' => null,
        'dias_producao' => 0,
        'destaque' => 0,
        'permite_personalizacao' => 0,
        'ativo' => 1,
        'imagem' => null,
    ];
    $imagens = [];

    if ($acao === 'editar') {
        $id = (int) ($params[1] ?? 0);
        $stmt = db()->prepare(
            'SELECT id, nome, slug, descricao, regras_produto, preco_centavos, category_id,
                    dias_producao, destaque, permite_personalizacao, ativo, imagem
               FROM products WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) {
            flash('erro', 'Produto não encontrado.');
            redirect('admin/produtos');
        }
        $produto = $p;

        $stmt = db()->prepare(
            'SELECT id, arquivo, ordem FROM product_images WHERE product_id = ? ORDER BY ordem ASC, id ASC'
        );
        $stmt->execute([$id]);
        $imagens = $stmt->fetchAll();
    }

    // Tabelas nutricionais: todas (para os checkboxes) e as já associadas.
    $tabelas_nutri = db()->query('SELECT id, nome FROM tabelas_nutricionais ORDER BY nome ASC')->fetchAll();
    $tabelas_sel = [];
    if ($produto['id'] > 0) {
        $stmt = db()->prepare(
            'SELECT tabela_nutricional_id FROM produto_tabelas_nutricionais WHERE produto_id = ?'
        );
        $stmt->execute([(int) $produto['id']]);
        $tabelas_sel = array_map('intval', array_column($stmt->fetchAll(), 'tabela_nutricional_id'));
    }

    $titulo = $produto['id'] > 0 ? 'Editar produto' : 'Novo produto';

    ob_start();
    ?>
    <p><a href="<?= e(url('admin/produtos')) ?>">&larr; Voltar para produtos</a></p>

    <form id="form-produto" method="post" action="<?= e(url('admin/produtos')) ?>" enctype="multipart/form-data" novalidate>
        <?= csrf_input() ?>
        <input type="hidden" name="op" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $produto['id'] ?>">

        <!-- Bloco 1: Informações do produto -->
        <div class="card-bloco">
            <h2>Informações do produto</h2>
            <div class="produto-form-grid">
                <div class="produto-col">
                    <div class="campo">
                        <label for="nome">Nome</label>
                        <input type="text" id="nome" name="nome" value="<?= e($produto['nome']) ?>" required minlength="2"
                            data-slug-source>
                    </div>
                    <div class="campo">
                        <label for="slug">Slug (endereço)</label>
                        <input type="text" id="slug" name="slug" value="<?= e($produto['slug']) ?>"
                            placeholder="Gerado a partir do nome" data-slug-target>
                        <small>Gerado automaticamente do nome. Edite só se souber o que está fazendo.</small>
                    </div>
                    <div class="campo">
                        <label for="category_id">Categoria</label>
                        <select id="category_id" name="category_id">
                            <option value="">— sem categoria —</option>
                            <?php foreach ($categorias as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= ((int) $produto['category_id'] === (int) $c['id']) ? 'selected' : '' ?>>
                                    <?= e($c['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="produto-col">
                    <div class="campo">
                        <label for="preco">Preço (R$)</label>
                        <input type="text" id="preco" name="preco" inputmode="decimal"
                            value="<?= e(centavos_para_input((int) $produto['preco_centavos'])) ?>"
                            placeholder="Ex.: 35,90">
                        <small>Para produtos personalizáveis, o preço é opcional.</small>
                    </div>
                    <div class="campo">
                        <label for="dias_producao">Dias de produção</label>
                        <input type="number" id="dias_producao" name="dias_producao" min="0"
                            value="<?= (int) $produto['dias_producao'] ?>">
                    </div>
                    <div class="campo campo-inline">
                        <input type="checkbox" id="destaque" name="destaque" value="1" <?= $produto['destaque'] ? 'checked' : '' ?>>
                        <label for="destaque">Destaque (aparece na home)</label>
                    </div>
                    <div class="campo campo-inline">
                        <input type="checkbox" id="permite_personalizacao" name="permite_personalizacao" value="1"
                            <?= $produto['permite_personalizacao'] ? 'checked' : '' ?>>
                        <label for="permite_personalizacao">Permitir personalização (mostra botão que leva ao
                            WhatsApp)</label>
                    </div>
                    <div class="campo campo-inline">
                        <input type="checkbox" id="ativo" name="ativo" value="1" <?= $produto['ativo'] ? 'checked' : '' ?>>
                        <label for="ativo">Ativo (aparece na loja)</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bloco 2: Descrição e informações nutricionais -->
        <div class="card-bloco">
            <h2>Descrição e informações nutricionais</h2>
            <div class="produto-form-grid">
                <div class="produto-col">
                    <div class="campo">
                        <label for="descricao">Descrição</label>
                        <textarea id="descricao" name="descricao" rows="10"><?= e($produto['descricao']) ?></textarea>
                    </div>
                    <div class="campo">
                        <label for="regras_produto">Regras/observações deste produto</label>
                        <textarea id="regras_produto" name="regras_produto"
                            rows="10"><?= e($produto['regras_produto']) ?></textarea>
                    </div>
                </div>

                <div class="produto-col">
                    <div class="campo">
                        <label>Tabelas nutricionais</label>
                        <?php if (empty($tabelas_nutri)): ?>
                            <small>Nenhuma tabela nutricional cadastrada.</small>
                        <?php else: ?>
                            <div class="tn-lista">
                                <?php foreach ($tabelas_nutri as $tn): ?>
                                    <?php $tnid = (int) $tn['id']; ?>
                                    <input class="tn-input" type="checkbox" id="tn-<?= $tnid ?>" name="tabelas[]"
                                        value="<?= $tnid ?>" <?= in_array($tnid, $tabelas_sel, true) ? 'checked' : '' ?>>
                                    <label class="tn-item" for="tn-<?= $tnid ?>">
                                        <span><?= e($tn['nome']) ?></span>
                                        <svg class="tn-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <polyline points="20 6 9 17 4 12" />
                                        </svg>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Bloco 3: Fotos do produto (input + galeria juntos; fora do form para não aninhar) -->
    <div class="card-bloco">
        <h2>Fotos do produto</h2>
        <div class="campo">
            <input class="input-arquivo" type="file" id="imagens" name="imagens[]" form="form-produto"
                accept="image/jpeg,image/png,image/webp" multiple data-arquivo-input>
            <div class="arquivo-linha">
                <label for="imagens" class="btn btn-arquivo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 15V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14l4-4h6" />
                        <line x1="18" y1="14" x2="18" y2="20" />
                        <line x1="15" y1="17" x2="21" y2="17" />
                    </svg>
                    Escolher fotos
                </label>
                <span class="arquivo-info" data-arquivo-info>As fotos são enviadas ao salvar. A 1ª vira a capa.</span>
            </div>
        </div>

        <!-- Prévia: fotos escolhidas no navegador, ainda NÃO enviadas -->
        <div class="fotos-preview-wrap" data-preview-wrap hidden>
            <h3 class="mt-1">A enviar ao salvar</h3>
            <div class="grade-fotos" data-fotos-preview></div>
        </div>

        <?php if ($produto['id'] > 0 && !empty($imagens)): ?>
            <h3 class="mt-1">Fotos já salvas</h3>
            <p><small>Arraste as fotos para reordenar. A primeira é sempre a capa.</small></p>
            <div class="grade-fotos" id="fotos-galeria">
                <?php foreach ($imagens as $img): ?>
                    <div class="foto-card" data-img-id="<?= (int) $img['id'] ?>">
                        <span class="foto-handle" title="Arraste para reordenar" aria-hidden="true">&#9776;</span>
                        <span class="foto-capa etiqueta">Capa</span>
                        <img class="card-img" src="<?= e(url('assets/uploads/' . imagem_miniatura($img['arquivo']))) ?>" alt="">
                        <form method="post" action="<?= e(url('admin/produtos')) ?>" data-img-remover-form>
                            <?= csrf_input() ?>
                            <input type="hidden" name="produto_id" value="<?= (int) $produto['id'] ?>">
                            <input type="hidden" name="imagem_id" value="<?= (int) $img['id'] ?>">
                            <input type="hidden" name="op" value="img_remover">
                            <button class="btn sec" type="submit">Remover</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="fotos-feedback" class="reorder-feedback" hidden></div>

            <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
            <script>
                (function () {
                    var cont = document.getElementById('fotos-galeria');
                    if (!cont) { return; }
                    var endpoint = <?= json_encode(url('admin/produtos')) ?>;
                    var csrf = <?= json_encode(csrf_token()) ?>;
                    var pid = <?= (int) $produto['id'] ?>;
                    var feedback = document.getElementById('fotos-feedback');

                    function ids() {
                        return Array.prototype.map.call(
                            cont.querySelectorAll('[data-img-id]'),
                            function (el) { return el.getAttribute('data-img-id'); }
                        );
                    }
                    function fb(ok, msg) {
                        if (!feedback) { return; }
                        feedback.textContent = msg || (ok ? 'Ordem salva ✓' : 'Erro ao salvar');
                        feedback.classList.toggle('erro', !ok);
                        feedback.hidden = false;
                        clearTimeout(feedback._t);
                        feedback._t = setTimeout(function () { feedback.hidden = true; }, 1800);
                    }

                    // Remover foto via AJAX: some da galeria sem recarregar a página.
                    cont.querySelectorAll('[data-img-remover-form]').forEach(function (form) {
                        form.addEventListener('submit', function (ev) {
                            ev.preventDefault();
                            confirmar('Remover esta imagem?', function () {
                                var card = form.closest('[data-img-id]');
                                var idInput = form.querySelector('[name="imagem_id"]');
                                var btn = form.querySelector('button');
                                if (btn) { btn.disabled = true; }

                                var body = new URLSearchParams();
                                body.append('op', 'img_remover');
                                body.append('_csrf', csrf);
                                body.append('produto_id', pid);
                                body.append('imagem_id', idInput ? idInput.value : '');

                                fetch(endpoint, {
                                    method: 'POST',
                                    headers: { 'X-Requested-With': 'fetch' },
                                    credentials: 'same-origin',
                                    body: body
                                })
                                    .then(function (r) { return r.json(); })
                                    .then(function (d) {
                                        if (d && d.ok) {
                                            // A etiqueta "Capa" segue o :first-child no CSS,
                                            // então a nova capa aparece sozinha ao remover o card.
                                            if (card) { card.remove(); }
                                            notificar('sucesso', 'Foto removida.');
                                        } else {
                                            if (btn) { btn.disabled = false; }
                                            notificar('erro', 'Erro ao remover a foto.');
                                        }
                                    })
                                    .catch(function () {
                                        if (btn) { btn.disabled = false; }
                                        notificar('erro', 'Erro ao remover a foto.');
                                    });
                            }, { ok: 'Remover', titulo: 'Remover foto' });
                        });
                    });
                    function salvar() {
                        var body = new URLSearchParams();
                        body.append('op', 'img_reordenar');
                        body.append('_csrf', csrf);
                        body.append('produto_id', pid);
                        ids().forEach(function (id) { body.append('ids[]', id); });
                        fetch(endpoint, {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'fetch' },
                            credentials: 'same-origin',
                            body: body
                        })
                            .then(function (r) { return r.json(); })
                            .then(function (d) { fb(!!(d && d.ok)); })
                            .catch(function () { fb(false); });
                    }
                    if (window.Sortable) {
                        Sortable.create(cont, { handle: '.foto-handle', animation: 150, onEnd: salvar });
                    }
                })();
            </script>
        <?php elseif ($produto['id'] > 0): ?>
            <p class="mt-1"><small>Nenhuma imagem ainda. A primeira enviada vira a capa.</small></p>
        <?php endif; ?>
    </div>

    <!-- Rodapé: salvar (submete o formulário principal) -->
    <div class="form-rodape">
        <button class="btn" type="submit" form="form-produto">Salvar produto</button>
    </div>

    <script>
    (function () {
        var form = document.getElementById('form-produto');
        if (!form) { return; }
        var nomeInput = document.getElementById('nome');
        var idInput = form.querySelector('input[name="id"]');
        var endpoint = <?= json_encode(url('admin/produtos')) ?>;
        var csrf = <?= json_encode(csrf_token()) ?>;
        var liberado = false;

        // Preço é obrigatório quando o produto NÃO é personalizável (igual ao servidor).
        var preco = document.getElementById('preco');
        var personalizavel = document.getElementById('permite_personalizacao');
        if (preco && personalizavel) {
            var aplicarPreco = function () { preco.required = !personalizavel.checked; };
            personalizavel.addEventListener('change', aplicarPreco);
            aplicarPreco();
        }

        function enviar() {
            liberado = true;
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        }

        // Antes de salvar: valida obrigatórios (padrão da marca) e checa se o
        // nome já existe em OUTRO produto.
        form.addEventListener('submit', function (ev) {
            if (liberado) { liberado = false; return; }     // já validado: segue
            // Validação estilizada dos campos obrigatórios (sem balão nativo).
            if (window.Notificacoes && !Notificacoes.validar(form)) {
                ev.preventDefault();
                notificar('erro', 'Confira os campos destacados.');
                return;
            }
            var nome = (nomeInput && nomeInput.value ? nomeInput.value : '').trim();
            if (nome === '') { return; }
            ev.preventDefault();

            var body = new URLSearchParams();
            body.append('op', 'nome_existe');
            body.append('_csrf', csrf);
            body.append('nome', nome);
            body.append('id', idInput ? idInput.value : '0');

            fetch(endpoint, {
                method: 'POST',
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin',
                body: body
            })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.existe) {
                        confirmar(
                            'Já existe um produto com esse nome. Deseja criar assim mesmo?',
                            enviar,
                            {
                                ok: 'Criar assim mesmo',
                                titulo: 'Nome repetido',
                                aoCancelar: function () {
                                    if (nomeInput) { nomeInput.focus(); nomeInput.select(); }
                                }
                            }
                        );
                    } else {
                        enviar();
                    }
                })
                .catch(function () { enviar(); });          // falha na checagem: não trava o salvar
        });
    })();
    </script>
    <?php
    view('admin_layout', ['titulo' => $titulo, 'conteudo' => ob_get_clean()]);
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
