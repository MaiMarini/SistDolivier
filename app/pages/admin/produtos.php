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

// =============================================================================
// POST
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/produtos');
    }

    $op = $_POST['op'] ?? '';

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
        $id             = (int) ($_POST['id'] ?? 0);
        $nome           = trim($_POST['nome'] ?? '');
        $slug           = trim($_POST['slug'] ?? '');
        $descricao      = trim($_POST['descricao'] ?? '');
        $regras         = trim($_POST['regras_produto'] ?? '');
        $dias           = (int) ($_POST['dias_producao'] ?? 0);
        $personalizavel = isset($_POST['personalizavel']) ? 1 : 0;
        $destaque       = isset($_POST['destaque']) ? 1 : 0;
        $permite_pers   = isset($_POST['permite_personalizacao']) ? 1 : 0;
        $ativo          = isset($_POST['ativo']) ? 1 : 0;
        $preco_centavos = reais_para_centavos($_POST['preco'] ?? '');

        $category_id = (int) ($_POST['category_id'] ?? 0);
        $category_id = $category_id > 0 ? $category_id : null;

        $destino_erro = $id > 0 ? 'admin/produtos/editar/' . $id : 'admin/produtos/novo';

        if (mb_strlen($nome) < 2) {
            flash('erro', 'Informe o nome do produto.');
            redirect($destino_erro);
        }
        // Preço obrigatório quando NÃO for personalizável.
        if (!$personalizavel && $preco_centavos <= 0) {
            flash('erro', 'Informe um preço válido (ou marque como personalizável).');
            redirect($destino_erro);
        }

        $base = gerar_slug($slug !== '' ? $slug : $nome);
        $slug = slug_unico('products', $base, $id > 0 ? $id : null);

        if ($id > 0) {
            $stmt = db()->prepare(
                'UPDATE products
                    SET nome = ?, slug = ?, descricao = ?, regras_produto = ?,
                        preco_centavos = ?, category_id = ?, dias_producao = ?,
                        personalizavel = ?, destaque = ?, permite_personalizacao = ?, ativo = ?
                  WHERE id = ?'
            );
            $stmt->execute([
                $nome, $slug, ($descricao !== '' ? $descricao : null),
                ($regras !== '' ? $regras : null), $preco_centavos, $category_id,
                $dias, $personalizavel, $destaque, $permite_pers, $ativo, $id,
            ]);
            flash('sucesso', 'Produto atualizado.');
            redirect('admin/produtos/editar/' . $id);
        }

        $stmt = db()->prepare(
            'INSERT INTO products
                (slug, nome, descricao, regras_produto, preco_centavos, category_id,
                 dias_producao, personalizavel, destaque, permite_personalizacao, ativo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $slug, $nome, ($descricao !== '' ? $descricao : null),
            ($regras !== '' ? $regras : null), $preco_centavos, $category_id,
            $dias, $personalizavel, $destaque, $permite_pers, $ativo,
        ]);
        $novo_id = (int) db()->lastInsertId();
        flash('sucesso', 'Produto criado. Agora adicione as imagens.');
        redirect('admin/produtos/editar/' . $novo_id);
    }

    // -------------------------------------------------- AÇÕES DE IMAGEM (galeria)
    $pid = (int) ($_POST['produto_id'] ?? 0);
    if ($pid <= 0) {
        redirect('admin/produtos');
    }

    if ($op === 'img_adicionar') {
        $enviados = $_FILES['imagens'] ?? null;
        $adicionadas = 0;
        $erros = [];

        // Ordem inicial = após a maior existente.
        $stmt = db()->prepare('SELECT COALESCE(MAX(ordem), 0) FROM product_images WHERE product_id = ?');
        $stmt->execute([$pid]);
        $ordem = (int) $stmt->fetchColumn();

        $primeira_nova = null;

        if (is_array($enviados) && isset($enviados['name']) && is_array($enviados['name'])) {
            $total = count($enviados['name']);
            for ($i = 0; $i < $total; $i++) {
                if (($enviados['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue; // campo vazio
                }
                $arquivo = [
                    'name'     => $enviados['name'][$i],
                    'type'     => $enviados['type'][$i] ?? '',
                    'tmp_name' => $enviados['tmp_name'][$i] ?? '',
                    'error'    => $enviados['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $enviados['size'][$i] ?? 0,
                ];
                $res = processar_upload_imagem($arquivo);
                if (!empty($res['ok'])) {
                    $ordem++;
                    $ins = db()->prepare(
                        'INSERT INTO product_images (product_id, arquivo, ordem) VALUES (?, ?, ?)'
                    );
                    $ins->execute([$pid, $res['arquivo'], $ordem]);
                    $adicionadas++;
                    if ($primeira_nova === null) {
                        $primeira_nova = $res['arquivo'];
                    }
                } else {
                    $erros[] = $res['erro'] ?? 'Falha em uma imagem.';
                }
            }
        }

        // Se o produto ainda não tem capa, usa a primeira imagem enviada.
        if ($primeira_nova !== null) {
            $stmt = db()->prepare('SELECT imagem FROM products WHERE id = ?');
            $stmt->execute([$pid]);
            $capa_atual = $stmt->fetchColumn();
            if (!$capa_atual) {
                $up = db()->prepare('UPDATE products SET imagem = ? WHERE id = ?');
                $up->execute([$primeira_nova, $pid]);
            }
        }

        if ($adicionadas > 0) {
            flash('sucesso', $adicionadas . ' imagem(ns) adicionada(s).');
        }
        if (!empty($erros)) {
            flash('erro', implode(' ', $erros));
        }
        redirect('admin/produtos/editar/' . $pid);
    }

    if ($op === 'img_salvar_ordem') {
        $imagem_id = (int) ($_POST['imagem_id'] ?? 0);
        $ordem = (int) ($_POST['ordem'] ?? 0);
        if (_produto_imagem($imagem_id, $pid)) {
            $stmt = db()->prepare('UPDATE product_images SET ordem = ? WHERE id = ? AND product_id = ?');
            $stmt->execute([$ordem, $imagem_id, $pid]);
            flash('sucesso', 'Ordem atualizada.');
        }
        redirect('admin/produtos/editar/' . $pid);
    }

    if ($op === 'img_capa') {
        $imagem_id = (int) ($_POST['imagem_id'] ?? 0);
        $img = _produto_imagem($imagem_id, $pid);
        if ($img) {
            $stmt = db()->prepare('UPDATE products SET imagem = ? WHERE id = ?');
            $stmt->execute([$img['arquivo'], $pid]);
            flash('sucesso', 'Capa definida.');
        }
        redirect('admin/produtos/editar/' . $pid);
    }

    if ($op === 'img_remover') {
        $imagem_id = (int) ($_POST['imagem_id'] ?? 0);
        $img = _produto_imagem($imagem_id, $pid);
        if ($img) {
            imagem_apagar($img['arquivo']);
            $del = db()->prepare('DELETE FROM product_images WHERE id = ? AND product_id = ?');
            $del->execute([$imagem_id, $pid]);

            // Se era a capa, escolhe outra imagem (a de menor ordem) ou limpa.
            $stmt = db()->prepare('SELECT imagem FROM products WHERE id = ?');
            $stmt->execute([$pid]);
            if ($stmt->fetchColumn() === $img['arquivo']) {
                $q = db()->prepare(
                    'SELECT arquivo FROM product_images WHERE product_id = ? ORDER BY ordem ASC, id ASC LIMIT 1'
                );
                $q->execute([$pid]);
                $nova_capa = $q->fetchColumn();
                $up = db()->prepare('UPDATE products SET imagem = ? WHERE id = ?');
                $up->execute([$nova_capa !== false ? $nova_capa : null, $pid]);
            }
            flash('sucesso', 'Imagem removida.');
        }
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
        'id' => 0, 'nome' => '', 'slug' => '', 'descricao' => '', 'regras_produto' => '',
        'preco_centavos' => 0, 'category_id' => null, 'dias_producao' => 0,
        'personalizavel' => 0, 'destaque' => 0, 'permite_personalizacao' => 0,
        'ativo' => 1, 'imagem' => null,
    ];
    $imagens = [];

    if ($acao === 'editar') {
        $id = (int) ($params[1] ?? 0);
        $stmt = db()->prepare(
            'SELECT id, nome, slug, descricao, regras_produto, preco_centavos, category_id,
                    dias_producao, personalizavel, destaque, permite_personalizacao, ativo, imagem
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

    $titulo = $produto['id'] > 0 ? 'Editar produto' : 'Novo produto';

    ob_start();
    ?>
    <p><a href="<?= e(url('admin/produtos')) ?>">&larr; Voltar para produtos</a></p>

    <form class="formulario" method="post" action="<?= e(url('admin/produtos')) ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="op" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $produto['id'] ?>">

        <div class="campo">
            <label for="nome">Nome</label>
            <input type="text" id="nome" name="nome" value="<?= e($produto['nome']) ?>" required>
        </div>
        <div class="campo">
            <label for="slug">Slug (endereço)</label>
            <input type="text" id="slug" name="slug" value="<?= e($produto['slug']) ?>"
                   placeholder="Deixe vazio para gerar a partir do nome">
        </div>
        <div class="campo">
            <label for="descricao">Descrição</label>
            <textarea id="descricao" name="descricao" rows="4"><?= e($produto['descricao']) ?></textarea>
        </div>
        <div class="campo">
            <label for="preco">Preço (R$)</label>
            <input type="text" id="preco" name="preco" inputmode="decimal"
                   value="<?= e(centavos_para_input((int) $produto['preco_centavos'])) ?>"
                   placeholder="Ex.: 35,90">
            <small>Para produtos personalizáveis, o preço é opcional.</small>
        </div>
        <div class="campo">
            <label for="category_id">Categoria</label>
            <select id="category_id" name="category_id">
                <option value="">— sem categoria —</option>
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"
                        <?= ((int) $produto['category_id'] === (int) $c['id']) ? 'selected' : '' ?>>
                        <?= e($c['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="dias_producao">Dias de produção</label>
            <input type="number" id="dias_producao" name="dias_producao" min="0"
                   value="<?= (int) $produto['dias_producao'] ?>">
        </div>
        <div class="campo campo-inline">
            <input type="checkbox" id="personalizavel" name="personalizavel" value="1"
                   <?= $produto['personalizavel'] ? 'checked' : '' ?>>
            <label for="personalizavel">Produto personalizável (venda via WhatsApp)</label>
        </div>
        <div class="campo campo-inline">
            <input type="checkbox" id="destaque" name="destaque" value="1"
                   <?= $produto['destaque'] ? 'checked' : '' ?>>
            <label for="destaque">Destaque (aparece na home)</label>
        </div>
        <div class="campo campo-inline">
            <input type="checkbox" id="permite_personalizacao" name="permite_personalizacao" value="1"
                   <?= $produto['permite_personalizacao'] ? 'checked' : '' ?>>
            <label for="permite_personalizacao">Permitir personalização (mostra botão que leva ao WhatsApp)</label>
        </div>
        <div class="campo">
            <label for="regras_produto">Regras/observações deste produto</label>
            <textarea id="regras_produto" name="regras_produto" rows="3"><?= e($produto['regras_produto']) ?></textarea>
        </div>
        <div class="campo campo-inline">
            <input type="checkbox" id="ativo" name="ativo" value="1"
                   <?= $produto['ativo'] ? 'checked' : '' ?>>
            <label for="ativo">Ativo (aparece na loja)</label>
        </div>

        <button class="btn" type="submit">Salvar</button>
    </form>

    <?php if ($produto['id'] > 0): ?>
        <h2 class="mt-1">Imagens</h2>

        <form class="formulario" method="post" action="<?= e(url('admin/produtos')) ?>"
              enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="op" value="img_adicionar">
            <input type="hidden" name="produto_id" value="<?= (int) $produto['id'] ?>">
            <div class="campo">
                <label for="imagens">Adicionar imagens (JPG, PNG ou WebP)</label>
                <input type="file" id="imagens" name="imagens[]" accept="image/jpeg,image/png,image/webp" multiple>
                <small>As imagens são otimizadas automaticamente.</small>
            </div>
            <button class="btn" type="submit">Enviar imagens</button>
        </form>

        <?php if (empty($imagens)): ?>
            <p class="mt-1">Nenhuma imagem ainda. A primeira enviada vira a capa.</p>
        <?php else: ?>
            <div class="grade mt-1">
                <?php foreach ($imagens as $img): ?>
                    <?php $eh_capa = ($produto['imagem'] === $img['arquivo']); ?>
                    <div class="pedido">
                        <img class="card-img"
                             src="<?= e(url('assets/uploads/' . imagem_miniatura($img['arquivo']))) ?>"
                             alt="">
                        <?php if ($eh_capa): ?>
                            <span class="etiqueta">Capa</span>
                        <?php endif; ?>
                        <form method="post" action="<?= e(url('admin/produtos')) ?>" class="mt-1">
                            <?= csrf_input() ?>
                            <input type="hidden" name="produto_id" value="<?= (int) $produto['id'] ?>">
                            <input type="hidden" name="imagem_id" value="<?= (int) $img['id'] ?>">
                            <div class="campo-inline">
                                <label>Ordem</label>
                                <input type="number" name="ordem" value="<?= (int) $img['ordem'] ?>"
                                       style="width:70px;">
                                <button class="btn sec" type="submit" name="op" value="img_salvar_ordem">Salvar</button>
                            </div>
                            <div class="produto-acoes mt-1">
                                <?php if (!$eh_capa): ?>
                                    <button class="btn sec" type="submit" name="op" value="img_capa">Definir capa</button>
                                <?php endif; ?>
                                <button class="btn" type="submit" name="op" value="img_remover"
                                        onclick="return confirm('Remover esta imagem?');">Remover</button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    view('admin_layout', ['titulo' => $titulo, 'conteudo' => ob_get_clean()]);
    return;
}

// ----------------------------------------------------------------- LISTAGEM
$produtos = db()->query(
    'SELECT p.id, p.nome, p.imagem, p.preco_centavos, p.personalizavel, p.ativo,
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
            <tr><th></th><th>Nome</th><th>Categoria</th><th>Preço</th><th>Ativo</th><th>Ações</th></tr>
        </thead>
        <tbody>
            <?php foreach ($produtos as $p): ?>
                <tr>
                    <td style="width:60px;">
                        <?php if (!empty($p['imagem'])): ?>
                            <img src="<?= e(url('assets/uploads/' . imagem_miniatura($p['imagem']))) ?>"
                                 alt="" style="width:48px;height:48px;object-fit:cover;border-radius:6px;">
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= e($p['nome']) ?></td>
                    <td><?= e($p['categoria'] ?? '—') ?></td>
                    <td>
                        <?php if ($p['personalizavel']): ?>
                            Sob consulta
                        <?php else: ?>
                            <?= e(money((int) $p['preco_centavos'])) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $p['ativo'] ? 'Sim' : 'Não' ?></td>
                    <td>
                        <a class="btn sec" href="<?= e(url('admin/produtos/editar/' . $p['id'])) ?>">Editar</a>
                        <form method="post" action="<?= e(url('admin/produtos')) ?>" style="display:inline"
                              onsubmit="return confirm('Excluir este produto e suas imagens?');">
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
