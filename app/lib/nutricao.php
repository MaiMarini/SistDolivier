<?php
/**
 * Tabelas nutricionais: nutrientes na ordem do rótulo, %VD, texto de alérgenos e
 * o rótulo em HTML (página do produto na loja e Prévia do admin).
 *
 * Os valores ficam gravados POR 100 g. A porção (porcao_individual_g) gera a
 * coluna da porção e o %VD:  porção = valor_100g × porção ÷ 100;
 * %VD = arredondar(porção ÷ VD × 100). VDs: RDC 429/2020 · IN 75/2020.
 * O mesmo cálculo está em assets/js/admin-tabelas-nutri.js (Prévia ao vivo).
 */

const NUTRI_ALERGENOS = ['Trigo', 'Leite', 'Ovos', 'Soja', 'Castanhas', 'Amendoim', 'Aveia', 'Cevada'];

/** coluna => [rótulo, unidade, casas decimais, VD (null = sem %VD), nível de recuo] */
function nutri_campos(): array
{
    return [
        'nutri_valor_energetico' => ['Valor energético', 'kcal', 0, 2000, 0],
        'nutri_carboidratos'     => ['Carboidratos', 'g', 1, 300, 0],
        'nutri_acucares_totais'  => ['Açúcares totais', 'g', 1, null, 1],
        'nutri_acucares_add'     => ['Açúcares adicionados', 'g', 1, 50, 2],
        'nutri_proteinas'        => ['Proteínas', 'g', 1, 50, 0],
        'nutri_gorduras_totais'  => ['Gorduras totais', 'g', 1, 65, 0],
        'nutri_gorduras_sat'     => ['Gorduras saturadas', 'g', 1, 20, 1],
        'nutri_gorduras_trans'   => ['Gorduras trans', 'g', 1, null, 1],
        'nutri_fibra'            => ['Fibra alimentar', 'g', 1, 25, 0],
        'nutri_sodio'            => ['Sódio', 'mg', 0, 2000, 0],
    ];
}

/** Valor do banco (ou digitado, com vírgula) -> float; vazio -> null (vazio é diferente de zero). */
function nutri_num($v): ?float
{
    if ($v === null) {
        return null;
    }
    $v = str_replace(',', '.', trim((string) $v));
    return $v === '' || !is_numeric($v) ? null : (float) $v;
}

/** "—" para vazio; kcal e sódio sem casas; os demais com até 1 casa, com vírgula. */
function nutri_fmt(?float $v, int $casas): string
{
    if ($v === null) {
        return '—';
    }
    $s = number_format($v, $casas, ',', '.');
    return $casas > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
}

/** Itens de alérgeno gravados (lista separada por vírgula), só os conhecidos, na ordem padrão. */
function nutri_alergenos_lista(?string $csv): array
{
    $itens = array_map('trim', explode(',', (string) $csv));
    return array_values(array_filter(NUTRI_ALERGENOS, fn ($a) => in_array($a, $itens, true)));
}

/** "Alérgicos: contém trigo, leite e ovos. Pode conter amendoim. Contém glúten." */
function nutri_alergenos_texto(array $contem, array $traco, bool $gluten): string
{
    $lista = function (array $a): string {
        $a = array_map(fn ($x) => mb_strtolower($x, 'UTF-8'), $a);
        return count($a) > 1 ? implode(', ', array_slice($a, 0, -1)) . ' e ' . end($a) : (string) ($a[0] ?? '');
    };
    $partes = [];
    if ($contem) {
        $partes[] = 'Alérgicos: contém ' . $lista($contem) . '.';
    }
    if ($traco) {
        $partes[] = ($contem ? 'Pode conter ' : 'Alérgicos: pode conter ') . $lista($traco) . '.';
    }
    $partes[] = $gluten ? 'Contém glúten.' : 'Não contém glúten.';
    return implode(' ', $partes);
}

/** Rótulo "INFORMAÇÃO NUTRICIONAL" (100 g | porção | %VD*) + texto de alérgenos. */
function nutri_rotulo_html(array $t): string
{
    $p = nutri_num($t['porcao_individual_g'] ?? null);
    $p = $p !== null && $p > 0 ? $p : null;
    $medida = trim((string) ($t['medida_caseira'] ?? ''));
    $linhas = '';
    foreach (nutri_campos() as $col => [$rotulo, $un, $casas, $vd, $nivel]) {
        $x = nutri_num($t[$col] ?? null);
        $xp = $x !== null && $p !== null ? $x * $p / 100 : null;
        $pvd = $vd && $xp !== null ? round($xp / $vd * 100) . '%' : '';
        $linhas .= '<tr' . ($nivel ? ' class="nivel-' . $nivel . '"' : '') . '><td>' . e($rotulo . ' (' . $un . ')') . '</td>'
            . '<td>' . e(nutri_fmt($x, $casas)) . '</td><td>' . e(nutri_fmt($xp, $casas)) . '</td><td>' . e($pvd) . '</td></tr>';
    }
    $porcao = $p !== null ? nutri_fmt($p, 1) . ' g' : '—';
    $alerg = trim((string) ($t['alergenicos'] ?? ''));
    return '<div class="nutri-rotulo">'
        . '<div class="nutri-rotulo-titulo">INFORMAÇÃO NUTRICIONAL</div>'
        . '<div class="nutri-rotulo-porcao">Porção: ' . e($porcao . ($medida !== '' ? ' (' . $medida . ')' : '')) . '</div>'
        . '<table><thead><tr><th></th><th>100 g</th><th>' . e($p !== null ? nutri_fmt($p, 1) . ' g' : 'Porção') . '</th><th>%VD*</th></tr></thead>'
        . '<tbody>' . $linhas . '</tbody></table>'
        . '<div class="nutri-rotulo-rodape">*Percentual de valores diários fornecidos pela porção.</div>'
        . '</div>'
        . ($alerg !== '' ? '<p class="nutri-rotulo-alergenos">' . e(mb_strtoupper($alerg, 'UTF-8')) . '</p>' : '');
}

/** Tabelas ligadas a um produto, na ordem (sem as excluídas). Tolerante a migração não rodada. */
function nutri_tabelas_do_produto(int $produto_id): array
{
    $sql = 'SELECT t.* FROM produto_tabelas_nutricionais pt
              JOIN tabelas_nutricionais t ON t.id = pt.tabela_nutricional_id
             WHERE pt.produto_id = ? %s
             ORDER BY pt.ordem ASC, t.nome ASC';
    foreach (['AND t.excluida_em IS NULL', ''] as $filtro) {
        try {
            $st = db()->prepare(sprintf($sql, $filtro));
            $st->execute([$produto_id]);
            return $st->fetchAll();
        } catch (PDOException $e) {
            if ($filtro === '' || $e->getCode() !== '42S22') {
                return [];
            }
        }
    }
    return [];
}

/** Tabelas para escolher no produto (id, nome), sem as excluídas. */
function nutri_tabelas_ativas(): array
{
    foreach (['WHERE excluida_em IS NULL', ''] as $filtro) {
        try {
            return db()->query("SELECT id, nome FROM tabelas_nutricionais $filtro ORDER BY nome ASC")->fetchAll();
        } catch (PDOException $e) {
            if ($filtro === '' || $e->getCode() !== '42S22') {
                return [];
            }
        }
    }
    return [];
}
