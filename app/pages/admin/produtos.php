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
 */
exigir_admin();

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
    if ($op === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            // Apaga os arquivos das imagens antes de remover o produto.
            $stmt = db()->prepare('SELECT arquivo FROM product_images WHERE product_id = ?');
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $img) {
                imagem_apagar($img['arquivo']);
            }
            // Apaga também o arquivo de capa, se ainda existir.
            $stmt = db()->prepare('SELECT imagem FROM products WHERE id = ?');
            $stmt->execute([$id]);
            $cap = $stmt->fetchColumn();
            if ($cap) {
                imagem_apagar($cap);
            }
            // Remove o produto (FK CASCADE remove os registros de product_images).
            $stmt = db()->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$id]);
            flash('sucesso', 'Produto excluído (com as imagens).');
        }
        redirect('admin/produtos');
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

        $stmt = db()->prepare(
            'INSERT INTO products
                (slug, nome, descricao, regras_produto, preco_centavos, category_id,
                 dias_producao, destaque, permite_personalizacao, ativo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $slug,
            $nome,
            ($descricao !== '' ? $descricao : null),
            ($regras !== '' ? $regras : null),
            $preco_centavos,
            $category_id,
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
                        <input type="text" id="nome" name="nome" value="<?= e($produto['nome']) ?>" required
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
$produtos = db()->query(
    'SELECT p.id, p.nome, p.imagem, p.preco_centavos, p.ativo,
            c.nome AS categoria
       FROM products p
       LEFT JOIN categories c ON c.id = p.category_id
      ORDER BY p.id DESC'
)->fetchAll();

ob_start();
?>
<p><a class="btn" href="<?= e(url('admin/produtos/novo')) ?>">Novo produto</a></p>

<?php if (empty($produtos)): ?>
    <p>Nenhum produto cadastrado.</p>
<?php else: ?>
    <table class="tabela">
        <thead>
            <tr>
                <th class="t-centro"></th>
                <th>Nome</th>
                <th>Categoria</th>
                <th class="t-centro">Preço</th>
                <th class="t-centro">Ativo</th>
                <th class="col-acoes">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($produtos as $p): ?>
                <tr>
                    <td class="t-centro" style="width:60px;">
                        <?php if (!empty($p['imagem'])): ?>
                            <img src="<?= e(url('assets/uploads/' . imagem_miniatura($p['imagem']))) ?>" alt=""
                                style="width:48px;height:48px;object-fit:cover;border-radius:6px;">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= e($p['nome']) ?></td>
                    <td><?= e($p['categoria'] ?? '—') ?></td>
                    <td class="t-centro">
                        <?php if ((int) $p['preco_centavos'] > 0): ?>
                            <?= e(money((int) $p['preco_centavos'])) ?>
                        <?php else: ?>
                            Sob consulta
                        <?php endif; ?>
                    </td>
                    <td class="t-centro"><?= $p['ativo'] ? 'Sim' : 'Não' ?></td>
                    <td class="col-acoes">
                        <a class="btn sec" href="<?= e(url('admin/produtos/editar/' . $p['id'])) ?>">Editar</a>
                        <form method="post" action="<?= e(url('admin/produtos')) ?>" style="display:inline"
                            data-confirmar="Excluir este produto e suas imagens?"
                            data-confirmar-ok="Excluir" data-confirmar-titulo="Excluir produto">
                            <?= csrf_input() ?>
                            <input type="hidden" name="op" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button class="btn" type="submit">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php
view('admin_layout', ['titulo' => 'Produtos', 'conteudo' => ob_get_clean()]);
