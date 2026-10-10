<?php
/**
 * Formulário de produto do admin (Novo e Editar): cartões à esquerda (Informações,
 * Fotos, Descrição, Tabela nutricional) e, à direita, Prévia + "Na loja" (sticky).
 * Barra de salvar fixa no rodapé. O comportamento fica em assets/js/admin-produto-form.js.
 *
 * Espera: $produto (linha de products ou padrão), $novo, $categorias [id, nome] na
 * ordem do menu, $tabelas [id, nome], $ligadas [ids], $fotos [{chave, url}],
 * $rascunho (token das fotos temporárias), $quando ("há 3 dias"), $voltar_url, $prefixo.
 */
$pid = (int) $produto['id'];
$preco = (int) $produto['preco_centavos'];
$pers = (int) $produto['permite_personalizacao'] === 1;
$ativo = (int) $produto['ativo'] === 1;
$destaque = (int) $produto['destaque'] === 1;
$url_loja = $novo ? '' : url('produto/' . $produto['slug']);

$dados = [
    'novo'       => $novo,
    'id'         => $pid,
    'urlPost'    => url('admin/produtos'),
    'urlTabelas' => url('admin/produtos/tabelas'),
    'urlLoja'    => $url_loja,
    'csrf'       => csrf_token(),
    'rascunho'   => $rascunho,
    'categorias' => array_map(fn ($c) => ['id' => (int) $c['id'], 'nome' => $c['nome']], $categorias),
    'tabelas'    => array_map(fn ($t) => ['id' => (int) $t['id'], 'nome' => $t['nome']], $tabelas),
    'ligadas'    => $ligadas,
    'fotos'      => $fotos,
    'max'        => 8,
];

/** Campo com rótulo, erro embaixo e ajuda opcional. */
$erro = fn (string $campo) => '<span class="pe-erro" id="pe-' . $campo . '-erro" data-pe-erro="' . $campo . '" role="alert" hidden></span>';

/** Chave (switch) com título e explicação; o valor vai num hidden do mesmo nome. */
$chave = function (string $campo, string $titulo, string $ajuda, bool $ligado): string {
    return '<button type="button" class="pe-tg" role="switch" aria-checked="' . ($ligado ? 'true' : 'false') . '" data-pe-sw="' . $campo . '"'
        . ' aria-describedby="pe-sw-' . $campo . '"><span class="pe-tg-txt"><b>' . e($titulo) . '</b><span id="pe-sw-' . $campo . '">' . e($ajuda) . '</span></span>'
        . '<span class="pe-tg-trilho" aria-hidden="true"></span></button>'
        . '<input type="hidden" name="' . $campo . '" value="' . ($ligado ? 1 : 0) . '" data-pe-sw-campo="' . $campo . '">';
};
?>
<form class="pe" id="pe-form" method="post" action="<?= e(url('admin/produtos')) ?>" novalidate data-pe>
    <?= csrf_input() ?>
    <input type="hidden" name="op" value="salvar">
    <input type="hidden" name="id" value="<?= $pid ?>">
    <input type="hidden" name="rascunho" value="<?= e($rascunho) ?>">

    <div class="pe-topo">
        <a class="pe-voltar" href="<?= e($voltar_url) ?>">← Produtos</a>
        <div class="pe-titulo">
            <h1 data-pe-titulo><?= e($novo ? 'Novo produto' : $produto['nome']) ?></h1>
            <?php if (!$novo): ?>
                <span class="pe-status"><span data-pe-status-loja><?= $ativo ? 'Na loja' : 'Oculto' ?></span> · última alteração <span data-pe-quando><?= e($quando) ?></span></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="pe-grade">
        <div class="pe-col">
            <!-- Informações -->
            <section class="pe-card" aria-labelledby="pe-h-info">
                <h2 id="pe-h-info">Informações</h2>
                <p class="legenda-obrigatorio"><span aria-hidden="true">*</span> Campos obrigatórios</p>
                <div class="pe-grid2">
                    <div class="pe-campo" data-pe-campo="nome">
                        <label for="pe-nome">Nome</label>
                        <input id="pe-nome" name="nome" type="text" maxlength="150" required autocomplete="off"
                               value="<?= e($produto['nome']) ?>" aria-describedby="pe-nome-erro pe-nome-dica">
                        <span class="pe-dica" id="pe-nome-dica" data-pe-nome-repetido hidden>Já existe outro produto com este nome.</span>
                        <?= $erro('nome') ?>
                    </div>
                    <div class="pe-campo" data-pe-campo="category_id">
                        <label for="pe-cat">Categoria</label>
                        <select id="pe-cat" name="category_id" required aria-describedby="pe-category_id-erro">
                            <option value="">Escolha…</option>
                            <?php foreach ($categorias as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= (int) $produto['category_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $erro('category_id') ?>
                    </div>
                    <div class="pe-campo" data-pe-campo="preco">
                        <label for="pe-preco">Preço</label>
                        <span class="pe-affix"><span aria-hidden="true">R$</span>
                            <input id="pe-preco" name="preco" type="text" inputmode="decimal" autocomplete="off" class="ap-num"
                                   value="<?= $preco > 0 ? e(centavos_para_input($preco)) : '' ?>"
                                   placeholder="<?= $pers ? 'Sob consulta' : '0,00' ?>" <?= $pers ? '' : 'required' ?>
                                   aria-describedby="pe-preco-ajuda pe-preco-erro"></span>
                        <span class="pe-ajuda" id="pe-preco-ajuda" data-pe-preco-ajuda><?= $pers
                            ? 'Opcional: sem preço, a loja mostra “Sob consulta” e o botão do WhatsApp.'
                            : 'Obrigatório para produtos que não aceitam personalização.' ?></span>
                        <?= $erro('preco') ?>
                    </div>
                    <div class="pe-campo" data-pe-campo="dias_producao">
                        <label for="pe-dias">Prazo de produção</label>
                        <span class="pe-affix"><input id="pe-dias" name="dias_producao" type="text" inputmode="numeric" maxlength="4" class="ap-num"
                                   value="<?= (int) $produto['dias_producao'] ?>" aria-describedby="pe-dias-ajuda pe-dias_producao-erro"><span aria-hidden="true">dias úteis</span></span>
                        <span class="pe-ajuda" id="pe-dias-ajuda">Aparece para o cliente na página do produto e no checkout.</span>
                        <?= $erro('dias_producao') ?>
                    </div>
                </div>
                <details class="pe-avancado">
                    <summary>Avançado</summary>
                    <div class="pe-campo" data-pe-campo="slug">
                        <label for="pe-slug">Endereço da página</label>
                        <span class="pe-affix pe-affix-longo"><span aria-hidden="true"><?= e($prefixo) ?></span>
                            <input id="pe-slug" name="slug" type="text" maxlength="140" autocomplete="off" spellcheck="false"
                                   value="<?= e($produto['slug']) ?>" aria-describedby="pe-slug-ajuda"></span>
                        <span class="pe-ajuda" id="pe-slug-ajuda">Mudar o endereço quebra links já compartilhados.
                            <button type="button" class="pe-link" data-pe-gerar-slug>Gerar a partir do nome</button></span>
                    </div>
                </details>
            </section>

            <!-- Fotos -->
            <section class="pe-card" aria-labelledby="pe-h-fotos">
                <h2 id="pe-h-fotos">Fotos <small data-pe-fotos-conta></small></h2>
                <div class="pe-fotos" data-pe-fotos></div>
                <input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden data-pe-arquivo>
                <span class="pe-ajuda">Arraste as fotos ou use as setas para mudar a ordem. As fotos novas são enviadas na hora e entram no produto ao salvar.</span>
                <?= $erro('fotos') ?>
            </section>

            <!-- Descrição -->
            <section class="pe-card" aria-labelledby="pe-h-desc">
                <h2 id="pe-h-desc">Descrição</h2>
                <div class="pe-campo" data-pe-campo="descricao">
                    <label for="pe-desc">Descrição na loja</label>
                    <textarea id="pe-desc" name="descricao" rows="6" maxlength="1200" data-pe-contar
                              placeholder="O que vem, sabores, tamanho, quantas unidades…"><?= e((string) $produto['descricao']) ?></textarea>
                    <span class="pe-contador ap-num" data-pe-contador="pe-desc"></span>
                    <?= $erro('descricao') ?>
                </div>
                <div class="pe-campo" data-pe-campo="regras_produto">
                    <label for="pe-regras">Regras e observações deste produto</label>
                    <textarea id="pe-regras" name="regras_produto" rows="4" maxlength="600" data-pe-contar aria-describedby="pe-regras-ajuda"
                              placeholder="Ex.: encomendas com 15 dias de antecedência; validade de 20 dias."><?= e((string) $produto['regras_produto']) ?></textarea>
                    <span class="pe-ajuda" id="pe-regras-ajuda">Aparecem em “Regras e prazos” na página do produto, junto das regras gerais da loja.</span>
                    <span class="pe-contador ap-num" data-pe-contador="pe-regras"></span>
                    <?= $erro('regras_produto') ?>
                </div>
            </section>

            <!-- Tabela nutricional -->
            <section class="pe-card" aria-labelledby="pe-h-nutri">
                <h2 id="pe-h-nutri">Tabela nutricional</h2>
                <div class="pe-chips" data-pe-chips></div>
                <p class="pe-aviso" data-pe-sem-tabela hidden>Nenhuma tabela ligada a este produto. Ela aparece para o cliente na página do produto.</p>
                <div class="pe-picker">
                    <input type="search" placeholder="Buscar tabela para adicionar" aria-label="Buscar tabela nutricional" autocomplete="off" data-pe-tq>
                    <ul data-pe-tlista></ul>
                    <a class="pe-picker-nova" href="<?= e(url('admin/tabelas-nutricionais/novo')) ?>" target="_blank" rel="noopener">+ Criar nova tabela nutricional</a>
                </div>
            </section>
        </div>

        <aside class="pe-lado" aria-label="Prévia e visibilidade">
            <section class="pe-card" aria-labelledby="pe-h-previa">
                <h2 id="pe-h-previa">Prévia</h2>
                <div class="pe-previa">
                    <div class="pe-previa-img" data-pe-previa-img>
                        <span class="pe-previa-vazia">Sem foto</span>
                        <span class="pe-previa-selo" data-pe-previa-destaque hidden>Destaque</span>
                        <span class="pe-previa-oculto" data-pe-previa-oculto hidden>Oculto da loja</span>
                    </div>
                    <div class="pe-previa-corpo">
                        <b data-pe-previa-nome></b>
                        <span class="pe-previa-preco ap-num" data-pe-previa-preco></span>
                        <span class="pe-ajuda" data-pe-previa-linha></span>
                    </div>
                </div>
                <?php if ($novo): ?>
                    <button type="button" class="ap-btn ap-btn-linha" disabled>Salve para ver na loja</button>
                <?php else: ?>
                    <a class="ap-btn ap-btn-linha" href="<?= e($url_loja) ?>" target="_blank" rel="noopener" data-pe-ver-loja>Ver página na loja ↗</a>
                    <span class="pe-ajuda" data-pe-ver-oculto <?= $ativo ? 'hidden' : '' ?>>Oculto: a página só abre na loja depois de mostrar o produto e salvar.</span>
                <?php endif; ?>
            </section>

            <section class="pe-card" aria-labelledby="pe-h-loja">
                <h2 id="pe-h-loja">Na loja</h2>
                <div class="pe-chaves">
                    <?= $chave('ativo', 'Mostrar na loja', 'Desligado, o produto fica oculto e não pode ser comprado.', $ativo) ?>
                    <?= $chave('destaque', 'Destaque na página inicial', 'Aparece na vitrine da home.', $destaque) ?>
                    <?= $chave('permite_personalizacao', 'Aceita personalização', 'Mostra o botão “Personalizar” que leva ao WhatsApp.', $pers) ?>
                </div>
            </section>
        </aside>
    </div>

    <div class="pe-barra" role="region" aria-label="Salvar produto">
        <span class="pe-estado" data-pe-estado><i aria-hidden="true"></i><span data-pe-estado-txt><?= $novo ? 'Preencha os dados do produto' : 'Tudo salvo' ?></span></span>
        <?php if (!$novo): ?>
            <a class="ap-btn ap-btn-linha pe-barra-ver" href="<?= e($url_loja) ?>" target="_blank" rel="noopener" data-pe-ver-loja>Ver na loja</a>
        <?php endif; ?>
        <button type="submit" class="ap-btn ap-btn-primario" data-pe-salvar disabled><?= $novo ? 'Criar produto' : 'Salvar alterações' ?></button>
    </div>
    <div class="pe-toast" data-pe-toast role="status" aria-live="polite" hidden></div>
</form>
<script type="application/json" id="pe-dados"><?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(asset('assets/js/admin-produto-form.js')) ?>"></script>
