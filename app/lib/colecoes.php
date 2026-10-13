<?php
/**
 * Coleções: grupos de produtos dentro de uma categoria (ex.: "Dia das Mães 2027"
 * em Coleções Sazonais), com a mesma data limite para encomendar e a mesma data
 * de entrega/retirada. Produto sem coleção usa o prazo de produção (dias úteis).
 * Toleram a migração migracao_colecoes.sql ainda não rodada (listas vazias).
 */

/** Coleções não excluídas (id, category_id, nome, encomendas_ate, entrega_em, ativo), na ordem. */
function colecoes_admin(): array
{
    try {
        return db()->query('SELECT id, category_id, nome, encomendas_ate, entrega_em, ativo
                              FROM colecoes WHERE excluida_em IS NULL ORDER BY ordem ASC, id ASC')->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** A coleção existe, não foi excluída e é desta categoria? */
function colecao_valida(int $id, int $category_id): bool
{
    try {
        $st = db()->prepare('SELECT 1 FROM colecoes WHERE id = ? AND category_id = ? AND excluida_em IS NULL');
        $st->execute([$id, $category_id]);
        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/** "dd/mm/aaaa" de uma data do banco (Y-m-d). */
function data_br(?string $ymd): string
{
    return $ymd ? date('d/m/Y', strtotime($ymd)) : '';
}

/** "dd/mm" de uma data do banco (Y-m-d). */
function data_dm(?string $ymd): string
{
    return $ymd ? date('d/m', strtotime(substr($ymd, 0, 10))) : '';
}

// =============================================================================
// Loja: situação de cada coleção (Pedidos abertos / Em breve / Encerrada)
// =============================================================================

/** "Hoje" da loja (Rondônia) para a situação das coleções; o admin usa o mesmo. */
function colecao_hoje(): string
{
    return (new DateTime('now', new DateTimeZone('America/Porto_Velho')))->format('Y-m-d');
}

/**
 * Situação da coleção num dia (padrão: hoje).
 * Abre no "Início da campanha" (abre_em) ou, sem ele, no dia da criação, e aceita
 * pedidos até encomendas_ate, inclusive. Desligada conta como encerrada.
 * Retorna: st (aberta|em_breve|encerrada), rotulo, prazo (linha de prazo), abre (dd/mm).
 */
function colecao_situacao(array $c, ?string $hoje = null): array
{
    $hoje = $hoje ?? colecao_hoje();
    $abre = substr((string) ($c['abre_em'] ?? $c['criado_em'] ?? ''), 0, 10);
    $ate = (string) $c['encomendas_ate'];
    if (isset($c['ativo']) && (int) $c['ativo'] !== 1) {
        $st = 'encerrada';
    } elseif ($abre !== '' && $hoje < $abre) {
        $st = 'em_breve';
    } elseif ($hoje > $ate) {
        $st = 'encerrada';
    } else {
        $st = 'aberta';
    }
    if ($st === 'aberta') {
        $dias = (int) (new DateTime($hoje))->diff(new DateTime($ate))->days;
        $prazo = $dias === 0 ? 'Último dia para pedir!' : ($dias === 1 ? 'Falta 1 dia para pedir' : 'Faltam ' . $dias . ' dias para pedir');
    } elseif ($st === 'em_breve') {
        $prazo = 'Abre em ' . data_dm($abre);
    } else {
        $prazo = 'Volta no próximo ano';
    }
    $rotulos = ['aberta' => 'Pedidos abertos', 'em_breve' => 'Em breve', 'encerrada' => 'Encerrada'];
    return ['st' => $st, 'rotulo' => $rotulos[$st], 'prazo' => $prazo, 'abre' => data_dm($abre)];
}

/** Texto do botão de compra fora do prazo ('' = pode comprar). */
function colecao_botao_bloqueado(array $sit): string
{
    if ($sit['st'] === 'em_breve') {
        return 'Abre em ' . $sit['abre'];
    }
    return $sit['st'] === 'encerrada' ? 'Encerrada' : '';
}

/** Slug livre dentro da categoria (halloween, halloween-2, ...). */
function colecao_slug_unico(string $nome, int $category_id, int $id): string
{
    $base = gerar_slug($nome);
    $base = $base !== '' ? substr($base, 0, 160) : 'colecao';
    $st = db()->prepare('SELECT 1 FROM colecoes WHERE category_id = ? AND slug = ? AND id <> ? AND excluida_em IS NULL');
    for ($i = 1; ; $i++) {
        $slug = $i === 1 ? $base : $base . '-' . $i;
        $st->execute([$category_id, $slug, $id]);
        if (!$st->fetchColumn()) {
            return $slug;
        }
    }
}

/** Coleções criadas antes da migração da capa ainda não têm slug: gera agora. */
function colecoes_preencher_slugs(): void
{
    $sem = db()->query("SELECT id, nome, category_id FROM colecoes WHERE (slug IS NULL OR slug = '') AND excluida_em IS NULL ORDER BY id")->fetchAll();
    $up = db()->prepare('UPDATE colecoes SET slug = ? WHERE id = ?');
    foreach ($sem as $c) {
        $up->execute([colecao_slug_unico((string) $c['nome'], (int) $c['category_id'], (int) $c['id']), (int) $c['id']]);
    }
}

/** Colunas que a loja lê de uma coleção. */
const COLECAO_COLUNAS_LOJA = 'o.id, o.category_id, o.nome, o.slug, o.abre_em, o.encomendas_ate, o.entrega_em, o.observacao,
                              o.capa, o.capa_alt, o.ativo, o.ordem, o.criado_em';

/**
 * Coleções de uma categoria para a página da loja.
 * null  -> a categoria não usa coleções (ou a migração ainda não rodou): página normal.
 * array -> coleções ligadas e com produtos ativos, cada uma com 'n' (produtos ativos)
 *          e 'sit' (colecao_situacao), em ordem: abertas, em breve, encerradas;
 *          dentro de cada grupo, a entrega mais próxima primeiro.
 */
function colecoes_da_categoria(int $category_id): ?array
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM colecoes WHERE category_id = ? AND excluida_em IS NULL');
        $st->execute([$category_id]);
        if (!(int) $st->fetchColumn()) {
            return null;
        }
        colecoes_preencher_slugs();
        $st = db()->prepare(
            'SELECT ' . COLECAO_COLUNAS_LOJA . ', COUNT(p.id) AS n
               FROM colecoes o
               JOIN products p ON p.colecao_id = o.id AND p.ativo = 1 AND p.category_id = o.category_id
              WHERE o.category_id = ? AND o.excluida_em IS NULL AND o.ativo = 1
              GROUP BY o.id, o.category_id, o.nome, o.slug, o.abre_em, o.encomendas_ate, o.entrega_em, o.observacao,
                       o.capa, o.capa_alt, o.ativo, o.ordem, o.criado_em'
        );
        $st->execute([$category_id]);
        $lista = $st->fetchAll();
    } catch (PDOException $e) {
        return null;
    }
    $hoje = colecao_hoje();
    $grupo = ['aberta' => 0, 'em_breve' => 1, 'encerrada' => 2];
    foreach ($lista as &$c) {
        $c['sit'] = colecao_situacao($c, $hoje);
    }
    unset($c);
    usort($lista, fn ($a, $b) => [$grupo[$a['sit']['st']], $a['entrega_em'], (int) $a['ordem'], (int) $a['id']]
                             <=> [$grupo[$b['sit']['st']], $b['entrega_em'], (int) $b['ordem'], (int) $b['id']]);
    return $lista;
}

/** Coleção ligada de uma categoria pelo slug (página da coleção); null se não houver. */
function colecao_por_slug(int $category_id, string $slug): ?array
{
    try {
        colecoes_preencher_slugs();
        $st = db()->prepare('SELECT ' . COLECAO_COLUNAS_LOJA . ' FROM colecoes o
                              WHERE o.category_id = ? AND o.slug = ? AND o.ativo = 1 AND o.excluida_em IS NULL LIMIT 1');
        $st->execute([$category_id, $slug]);
        $c = $st->fetch();
    } catch (PDOException $e) {
        return null;
    }
    if (!$c) {
        return null;
    }
    $c['sit'] = colecao_situacao($c);
    return $c;
}

/** Coleção do produto (com 'sit'); null se o produto não tem coleção. */
function colecao_do_produto(int $product_id): ?array
{
    try {
        colecoes_preencher_slugs();   // o link para a coleção precisa do slug
        $st = db()->prepare('SELECT ' . COLECAO_COLUNAS_LOJA . ', c.slug AS cat_slug FROM products p
                               JOIN colecoes o ON o.id = p.colecao_id AND o.excluida_em IS NULL
                               LEFT JOIN categories c ON c.id = o.category_id AND c.ativo = 1
                              WHERE p.id = ? LIMIT 1');
        $st->execute([$product_id]);
        $c = $st->fetch();
    } catch (PDOException $e) {
        return null;
    }
    if (!$c) {
        return null;
    }
    $c['sit'] = colecao_situacao($c);
    return $c;
}

/**
 * O produto pode entrar no carrinho agora? '' = sim; senão, o motivo.
 * Produto sem coleção: sempre pode (vale o prazo de produção).
 */
function colecao_recusa_carrinho(int $product_id): string
{
    $c = colecao_do_produto($product_id);
    if (!$c || $c['sit']['st'] === 'aberta') {
        return '';
    }
    return $c['sit']['st'] === 'em_breve'
        ? 'Os pedidos da coleção “' . $c['nome'] . '” abrem em ' . $c['sit']['abre'] . '.'
        : 'Os pedidos da coleção “' . $c['nome'] . '” estão encerrados.';
}

/**
 * Tira do carrinho os produtos de coleção fora do prazo de pedidos (coleção que
 * encerrou, foi desligada ou ainda não abriu depois que o item entrou). Os avisos
 * ficam em $_SESSION['carrinho_avisos'] até alguém mostrá-los (colecao_avisos_carrinho).
 */
function colecao_limpar_carrinho(): void
{
    $itens = carrinho();
    if (!$itens) {
        return;
    }
    $ids = array_map('intval', array_keys($itens));
    try {
        $st = db()->prepare('SELECT p.id AS produto_id, p.nome AS produto, ' . COLECAO_COLUNAS_LOJA . '
                               FROM products p JOIN colecoes o ON o.id = p.colecao_id AND o.excluida_em IS NULL
                              WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $linhas = $st->fetchAll();
    } catch (PDOException $e) {
        return;   // migração ainda não rodou
    }
    $hoje = colecao_hoje();
    foreach ($linhas as $c) {
        $sit = colecao_situacao($c, $hoje);
        if ($sit['st'] === 'aberta') {
            continue;
        }
        carrinho_remover((int) $c['produto_id']);
        $_SESSION['carrinho_avisos'][] = '“' . $c['produto'] . '” saiu do carrinho: os pedidos da coleção “' . $c['nome'] . '” '
            . ($sit['st'] === 'em_breve' ? 'abrem em ' . $sit['abre'] . '.' : 'estão encerrados.');
    }
}

/** Avisos de itens tirados do carrinho por colecao_limpar_carrinho (lê e apaga). */
function colecao_avisos_carrinho(): array
{
    $a = $_SESSION['carrinho_avisos'] ?? [];
    unset($_SESSION['carrinho_avisos']);
    return array_values(array_unique($a));
}

/**
 * Texto do prazo do produto para "Regras e prazos" (HTML escapado):
 * coleção -> pedidos até / entrega a partir de; senão, o prazo de produção.
 */
function colecao_prazo_produto_html(?array $colecao, int $dias_producao): string
{
    if ($colecao) {
        $h = '<p>Este produto faz parte da coleção <b>' . e($colecao['nome']) . '</b>.</p><ul>';
        if ($colecao['sit']['st'] === 'em_breve') {
            $h .= '<li>Pedidos a partir de <b>' . e(data_br($colecao['abre_em'] ?? $colecao['criado_em'])) . '</b>.</li>';
        }
        $h .= '<li>Pedidos até <b>' . e(data_br($colecao['encomendas_ate'])) . '</b>.</li>'
            . '<li>Entrega ou retirada a partir de <b>' . e(data_br($colecao['entrega_em'])) . '</b>.</li></ul>';
        if (trim((string) $colecao['observacao']) !== '') {
            $h .= '<p>' . nl2br(e($colecao['observacao'])) . '</p>';
        }
        return $h;
    }
    if ($dias_producao > 0) {
        return '<p>Tempo de produção: <b>' . $dias_producao . ' ' . ($dias_producao === 1 ? 'dia útil' : 'dias úteis') . '</b>.</p>';
    }
    return '';
}

// =============================================================================
// Capa
// =============================================================================

/**
 * O arquivo é usado em outro lugar além desta coleção? (outras coleções, mesmo as
 * excluídas que ainda podem voltar, produtos, galerias, banners e configurações)
 */
function colecao_capa_em_uso(string $arquivo, int $exceto_colecao): bool
{
    $consultas = [
        ['SELECT 1 FROM colecoes WHERE capa = ? AND id <> ? LIMIT 1', [$arquivo, $exceto_colecao]],
        ['SELECT 1 FROM products WHERE imagem = ? LIMIT 1', [$arquivo]],
        ['SELECT 1 FROM product_images WHERE arquivo = ? LIMIT 1', [$arquivo]],
        ['SELECT 1 FROM banners WHERE imagem = ? LIMIT 1', [$arquivo]],
        ['SELECT 1 FROM settings WHERE valor = ? LIMIT 1', [$arquivo]],
    ];
    foreach ($consultas as [$sql, $args]) {
        try {
            $st = db()->prepare($sql);
            $st->execute($args);
            if ($st->fetchColumn()) {
                return true;
            }
        } catch (PDOException $e) {
            // tabela ausente nesta instalação: não conta como uso
        }
    }
    return false;
}

/** Apaga a capa (e a miniatura) se nada mais usa o arquivo. */
function colecao_capa_apagar(?string $arquivo, int $colecao_id): void
{
    $arquivo = basename((string) $arquivo);
    if ($arquivo !== '' && !colecao_capa_em_uso($arquivo, $colecao_id)) {
        imagem_apagar($arquivo);
    }
}

/** Atributos src/srcset da capa (miniatura de 400 px + a de 1200 px). */
function colecao_capa_src(string $arquivo): string
{
    $full = url('assets/uploads/' . $arquivo);
    $mini = imagem_miniatura($arquivo);
    if ($mini === $arquivo) {
        return 'src="' . e($full) . '"';
    }
    return 'src="' . e($full) . '" srcset="' . e(url('assets/uploads/' . $mini)) . ' 400w, ' . e($full) . ' 1200w"';
}
