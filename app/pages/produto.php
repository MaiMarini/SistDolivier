<?php
/**
 * Página de produto: /produto/{slug}
 * Mostra imagem, nome, preço (se não personalizável), prazo de produção e
 * descrição. Personalizável -> WhatsApp; senão -> form de carrinho + WhatsApp.
 */
$slug = isset($params[0]) ? $params[0] : '';

$produto = null;
if ($slug !== '') {
    $stmt = db()->prepare(
        'SELECT id, slug, nome, descricao, regras_produto, preco_centavos, imagem,
                dias_producao, permite_personalizacao
           FROM products
          WHERE slug = ? AND ativo = 1
          LIMIT 1'
    );
    $stmt->execute([$slug]);
    $produto = $stmt->fetch();
}

// Produto inexistente/inativo -> 404 estilizado.
if (!$produto) {
    http_response_code(404);
    ob_start();
    ?>
    <h1>Produto não encontrado</h1>
    <p>O produto que você procura não existe ou não está disponível.</p>
    <p class="mt-1"><a class="btn" href="<?= e(url()) ?>">Voltar ao início</a></p>
    <?php
    view('layout', ['titulo' => 'Não encontrado', 'conteudo' => ob_get_clean()]);
    return;
}

// Botão "Personalizar" (WhatsApp): só com permite_personalizacao = 1 e número
// configurado em settings. Monta o link com a mensagem-modelo.
$whats_numero = preg_replace('/\D+/', '', (string) cfg('whatsapp_numero', ''));
$mostrar_personalizar = !empty($produto['permite_personalizacao']) && $whats_numero !== '';
$link_personalizar = '';
if ($mostrar_personalizar) {
    // URL ABSOLUTA da página do produto (base_url do site).
    $url_produto = url('produto/' . $produto['slug']);
    $template = (string) cfg(
        'personalizar_msg_template',
        'Olá! Quero personalizar o produto {produto}. Link: {link}'
    );
    $mensagem = str_replace(
        ['{produto}', '{link}'],
        [$produto['nome'], $url_produto],
        $template
    );
    $link_personalizar = 'https://wa.me/' . $whats_numero . '?text=' . rawurlencode($mensagem);
}

// Galeria: imagens do produto (ordenadas). Capa como imagem inicial.
$imagens = [];
$stmt = db()->prepare(
    'SELECT arquivo FROM product_images WHERE product_id = ? ORDER BY ordem ASC, id ASC'
);
$stmt->execute([(int) $produto['id']]);
$imagens = array_column($stmt->fetchAll(), 'arquivo');

if (empty($imagens) && !empty($produto['imagem'])) {
    $imagens = [$produto['imagem']];
}
$principal = !empty($produto['imagem']) ? $produto['imagem'] : ($imagens[0] ?? null);

ob_start();
?>
<div class="produto">
    <div>
        <?php if ($principal !== null): ?>
            <img class="produto-img" id="galeria-principal"
                 src="<?= e(url('assets/uploads/' . $principal)) ?>"
                 alt="<?= e($produto['nome']) ?>">
            <?php if (count($imagens) > 1): ?>
                <div class="galeria-thumbs">
                    <?php foreach ($imagens as $arq): ?>
                        <img class="galeria-thumb<?= $arq === $principal ? ' ativa' : '' ?>"
                             src="<?= e(url('assets/uploads/' . imagem_miniatura($arq))) ?>"
                             data-src="<?= e(url('assets/uploads/' . $arq)) ?>"
                             data-galeria-img alt="">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="produto-img" style="aspect-ratio:1/1;"></div>
        <?php endif; ?>
    </div>

    <div>
        <?php if ($mostrar_personalizar): ?>
            <span class="etiqueta">Personalizável</span>
        <?php endif; ?>

        <h1><?= e($produto['nome']) ?></h1>

        <?php if ((int) $produto['preco_centavos'] > 0): ?>
            <div class="produto-preco"><?= e(money((int) $produto['preco_centavos'])) ?></div>
        <?php else: ?>
            <div class="produto-preco">Sob consulta</div>
        <?php endif; ?>

        <?php if ((int) $produto['dias_producao'] > 0): ?>
            <p>Tempo de produção: <strong><?= (int) $produto['dias_producao'] ?></strong>
               dia(s) úteis.</p>
        <?php endif; ?>

        <?php if (!empty($produto['descricao'])): ?>
            <div class="mt-1"><?= nl2br(e($produto['descricao'])) ?></div>
        <?php endif; ?>

        <div class="produto-acoes">
            <?php if ($mostrar_personalizar): ?>
                <a class="btn btn-personalizar" href="<?= e($link_personalizar) ?>"
                   target="_blank" rel="noopener">Personalizar</a>
            <?php endif; ?>
            <?php if ((int) $produto['preco_centavos'] > 0): ?>
                <form class="campo-inline" method="post" action="<?= e(url('carrinho')) ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="acao" value="adicionar">
                    <input type="hidden" name="produto_id" value="<?= (int) $produto['id'] ?>">
                    <label for="quantidade">Qtd.</label>
                    <input type="number" id="quantidade" name="quantidade"
                           value="1" min="1" max="99" style="width:80px;">
                    <button class="btn" type="submit">Adicionar ao carrinho</button>
                </form>
            <?php endif; ?>
        </div>

        <p class="mt-1">
            <button class="btn sec" type="button"
                    data-abrir-modal="modal-produto-regras">Ver regras e prazos</button>
        </p>
    </div>
</div>

<?php
// Conteúdo do modal informativo: regras específicas (se houver, em destaque) +
// regras gerais da loja. Tudo escapado com e().
$regras_html = '';
if (!empty($produto['regras_produto'])) {
    $regras_html .= '<h3>Sobre este produto</h3>';
    $regras_html .= '<div class="destaque-regras">'
                  . nl2br(e($produto['regras_produto'])) . '</div>';
}
$regras_html .= '<h3>Regras gerais</h3>';
$regras_html .= '<div>' . nl2br(e(cfg('regras_texto', 'Em breve.'))) . '</div>';

view('modal-info', [
    'modal_id'       => 'modal-produto-regras',
    'modal_titulo'   => 'Regras e prazos',
    'modal_conteudo' => $regras_html,
]);
?>
<?php
view('layout', ['titulo' => $produto['nome'], 'conteudo' => ob_get_clean()]);
