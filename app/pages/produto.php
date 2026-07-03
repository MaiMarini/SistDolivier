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

// Galeria: capa (products.imagem) + imagens de product_images, capa primeiro,
// sem duplicar. Se não houver nenhuma, fica vazio (placeholder).
$stmt = db()->prepare(
    'SELECT arquivo FROM product_images WHERE product_id = ? ORDER BY ordem ASC, id ASC'
);
$stmt->execute([(int) $produto['id']]);
$imagens = array_column($stmt->fetchAll(), 'arquivo');

$capa = (string) ($produto['imagem'] ?? '');
if ($capa !== '') {
    array_unshift($imagens, $capa);
}
$imagens = array_values(array_unique(array_filter($imagens, 'strlen')));

// Tabelas nutricionais associadas (na ordem). Tolerante se as tabelas não existirem.
$tabelas_nutri = [];
try {
    $stmt = db()->prepare(
        'SELECT t.* FROM produto_tabelas_nutricionais pt
           JOIN tabelas_nutricionais t ON t.id = pt.tabela_nutricional_id
          WHERE pt.produto_id = ?
          ORDER BY pt.ordem ASC, t.nome ASC'
    );
    $stmt->execute([(int) $produto['id']]);
    $tabelas_nutri = $stmt->fetchAll();
} catch (PDOException $e) {
    $tabelas_nutri = [];
}

/** Número no padrão brasileiro (vírgula; sem zeros à direita desnecessários). */
if (!function_exists('_num_br')) {
    function _num_br($v): string
    {
        $s = number_format((float) $v, 2, ',', '.');
        if (strpos($s, ',') !== false) {
            $s = rtrim(rtrim($s, '0'), ',');
        }
        return $s;
    }
}

/** HTML de uma tabela nutricional: só os campos preenchidos (não NULL). */
if (!function_exists('_tabela_nutri_html')) {
    function _tabela_nutri_html(array $t): string
    {
        $campos = [
            'nutri_valor_energetico' => ['Valor energético', 'kcal'],
            'nutri_carboidratos'     => ['Carboidratos', 'g'],
            'nutri_acucares_totais'  => ['Açúcares totais', 'g'],
            'nutri_acucares_add'     => ['Açúcares adicionados', 'g'],
            'nutri_proteinas'        => ['Proteínas', 'g'],
            'nutri_gorduras_totais'  => ['Gorduras totais', 'g'],
            'nutri_gorduras_sat'     => ['Gorduras saturadas', 'g'],
            'nutri_gorduras_trans'   => ['Gorduras trans', 'g'],
            'nutri_fibra'            => ['Fibra alimentar', 'g'],
            'nutri_sodio'            => ['Sódio', 'mg'],
        ];
        ob_start();
        ?>
        <?php if (!empty($t['alergenicos'])): ?>
            <div class="nutri-alerta">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div><strong>Alérgenos:</strong> <?= nl2br(e($t['alergenicos'])) ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($t['nutri_porcao']) || !empty($t['nutri_porcao_individual'])): ?>
            <p class="nutri-porcao">
                <?php if (!empty($t['nutri_porcao'])): ?>Porção: <strong><?= e($t['nutri_porcao']) ?></strong><?php endif; ?>
                <?php if (!empty($t['nutri_porcao']) && !empty($t['nutri_porcao_individual'])): ?> &middot; <?php endif; ?>
                <?php if (!empty($t['nutri_porcao_individual'])): ?>Porção individual: <strong><?= e($t['nutri_porcao_individual']) ?></strong><?php endif; ?>
            </p>
        <?php endif; ?>
        <?php
        $linhas = '';
        foreach ($campos as $k => $info) {
            $v = $t[$k] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $linhas .= '<tr><td>' . e($info[0]) . '</td><td>' . e(_num_br($v) . ' ' . $info[1]) . '</td></tr>';
        }
        ?>
        <?php if ($linhas !== ''): ?>
            <table class="nutri-tabela"><tbody><?= $linhas ?></tbody></table>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }
}

ob_start();
?>
<div class="produto">
    <div>
        <?php if (empty($imagens)): ?>
            <div class="galeria-palco"></div>
        <?php else: ?>
            <div class="galeria" data-galeria>
                <?php if (count($imagens) > 1): ?>
                    <div class="galeria-miniaturas">
                        <?php foreach ($imagens as $i => $arq): ?>
                            <button type="button" class="galeria-mini<?= $i === 0 ? ' ativa' : '' ?>"
                                    data-galeria-mini="<?= (int) $i ?>" aria-label="Ver foto <?= (int) $i + 1 ?>">
                                <img src="<?= e(url('assets/uploads/' . imagem_miniatura($arq))) ?>" alt="">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="galeria-palco">
                    <?php foreach ($imagens as $i => $arq): ?>
                        <img class="galeria-foto<?= $i === 0 ? ' ativa' : '' ?>"
                             data-galeria-foto="<?= (int) $i ?>"
                             src="<?= e(url('assets/uploads/' . $arq)) ?>"
                             alt="<?= e($produto['nome']) ?>">
                    <?php endforeach; ?>

                    <?php if (count($imagens) > 1): ?>
                        <button type="button" class="galeria-zona galeria-zona-esq"
                                data-galeria-prev aria-label="Foto anterior"><span class="galeria-seta">&lsaquo;</span></button>
                        <button type="button" class="galeria-zona galeria-zona-dir"
                                data-galeria-next aria-label="Próxima foto"><span class="galeria-seta">&rsaquo;</span></button>
                        <div class="galeria-dots">
                            <?php foreach ($imagens as $i => $arq): ?>
                                <button type="button" class="galeria-dot<?= $i === 0 ? ' ativa' : '' ?>"
                                        data-galeria-dot="<?= (int) $i ?>" aria-label="Foto <?= (int) $i + 1 ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Lightbox (tela cheia) -->
                <div class="lightbox" data-lightbox>
                    <button type="button" class="lightbox-fechar" data-lightbox-fechar aria-label="Fechar">&times;</button>
                    <?php if (count($imagens) > 1): ?>
                        <button type="button" class="lightbox-seta lightbox-prev" data-lightbox-prev aria-label="Foto anterior">&lsaquo;</button>
                        <button type="button" class="lightbox-seta lightbox-next" data-lightbox-next aria-label="Próxima foto">&rsaquo;</button>
                    <?php endif; ?>
                    <img class="lightbox-img" data-lightbox-img src="" alt="<?= e($produto['nome']) ?>">
                    <div class="lightbox-contador" data-lightbox-contador></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="produto-info">
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
            <div class="produto-desc"><?= nl2br(e($produto['descricao'])) ?></div>
        <?php endif; ?>

        <?php if ((int) $produto['preco_centavos'] > 0): ?>
            <form method="post" action="<?= e(url('carrinho')) ?>" class="compra-form">
                <?= csrf_input() ?>
                <input type="hidden" name="acao" value="adicionar">
                <input type="hidden" name="produto_id" value="<?= (int) $produto['id'] ?>">

                <div class="qtd-linha">
                    <span class="qtd-rotulo">Quantidade</span>
                    <div class="qtd-pilula" data-qtd>
                        <button type="button" data-qtd-menos aria-label="Diminuir">&minus;</button>
                        <span class="qtd-num" data-qtd-num>1</span>
                        <input type="hidden" name="quantidade" value="1" data-qtd-input>
                        <button type="button" data-qtd-mais aria-label="Aumentar">+</button>
                    </div>
                </div>

                <button class="btn btn-carrinho" type="submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    Adicionar ao carrinho
                </button>
            </form>
        <?php endif; ?>

        <div class="produto-botoes">
            <?php if ($mostrar_personalizar): ?>
                <a class="btn btn-wpp-out" href="<?= e($link_personalizar) ?>"
                   target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.82 11.82 0 018.413 3.488 11.82 11.82 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.149-.173.198-.297.298-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.876 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                    Personalizar
                </a>
            <?php endif; ?>
            <button class="btn sec" type="button" data-abrir-modal="modal-produto-regras">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                Regras e prazos
            </button>
        </div>
    </div>
</div>

<?php if (!empty($tabelas_nutri)): ?>
    <!-- Informações nutricionais: largura total, abaixo das duas colunas -->
    <div class="acordeon" data-acordeon>
        <button type="button" class="acordeon-cabeca" data-acordeon-toggle aria-expanded="false">
            <span class="acordeon-titulo">
                <svg class="acordeon-icone" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21c.5-4.5 2.5-8 7-10"/><path d="M9 18c6.218 0 10.5-3.288 11-12V4h-4.014c-9 0-11.986 4-12 9 0 1 0 3 2 5h3z"/></svg>
                Informações nutricionais
            </span>
            <span class="acordeon-seta" aria-hidden="true">&#9662;</span>
        </button>
        <div class="acordeon-corpo" data-acordeon-corpo>
            <div class="acordeon-conteudo">
                <?php if (count($tabelas_nutri) === 1): ?>
                    <?= _tabela_nutri_html($tabelas_nutri[0]) ?>
                <?php else: ?>
                    <div class="nutri-abas" role="tablist">
                        <?php foreach ($tabelas_nutri as $i => $t): ?>
                            <button type="button" class="nutri-aba<?= $i === 0 ? ' ativa' : '' ?>"
                                    data-nutri-aba="<?= (int) $i ?>"><?= e($t['nome']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php foreach ($tabelas_nutri as $i => $t): ?>
                        <div class="nutri-painel<?= $i === 0 ? ' ativo' : '' ?>" data-nutri-painel="<?= (int) $i ?>">
                            <?= _tabela_nutri_html($t) ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

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
