<?php
/**
 * Admin: Tabelas nutricionais — lista e editor lado a lado.
 * Uma tabela pode ser usada em vários produtos (produto_tabelas_nutricionais).
 * Rotas:
 *   /admin/tabelas-nutricionais                  -> lista (+ "Escolha uma tabela…")
 *   /admin/tabelas-nutricionais?id=12            -> lista + editor da tabela 12
 *   /admin/tabelas-nutricionais?nova=1[&de=12]   -> lista + editor de tabela nova (de=12: cópia da 12)
 *   /admin/tabelas-nutricionais/novo             -> redireciona para ?nova=1 (link antigo)
 *   POST op=salvar | op=excluir (lógica) | op=restaurar   (fetch: JSON; sem JS: redirect)
 * Valores gravados POR 100 g; porção, %VD, alérgenos e rótulo em app/lib/nutricao.php.
 */
exigir_admin();

$CAMPOS = nutri_campos();
$BASE = 'admin/tabelas-nutricionais';

/** Número digitado: vazio -> [true, null]; "12,5" -> [true, 12.5]; negativo/texto -> [false, null]. */
function _tn_numero(string $txt): array
{
    $txt = trim($txt);
    if ($txt === '') {
        return [true, null];
    }
    if (!preg_match('/^\d{1,6}([.,]\d+)?$/', $txt)) {
        return [false, null];
    }
    $v = round((float) str_replace(',', '.', $txt), 2);
    return [$v <= 999999.99, $v];
}

/** Valor do banco para o campo: 450.00 -> "450"; 1.50 -> "1,5"; NULL -> "". */
function _tn_valor_campo($v): string
{
    if ($v === null || $v === '') {
        return '';
    }
    $s = number_format((float) $v, 2, ',', '');
    return rtrim(rtrim($s, '0'), ',');
}

// =============================================================================
// POST
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
    $op = $_POST['op'] ?? '';
    $responder = function (int $http, array $d) use ($ajax, $BASE): void {
        if (!$ajax) {
            flash(!empty($d['ok']) ? 'sucesso' : 'erro', $d['mensagem'] ?? 'Confira os campos.');
            redirect($d['ir'] ?? $BASE);
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($http);
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
    };
    if (!csrf_validar()) {
        $responder(403, ['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página e tente de novo.']);
        return;
    }
    $id = (int) ($_POST['id'] ?? 0);

    // ----------------------------------------------------------------- SALVAR
    if ($op === 'salvar') {
        if ($id > 0) {
            $st = db()->prepare('SELECT id FROM tabelas_nutricionais WHERE id = ? AND excluida_em IS NULL');
            $st->execute([$id]);
            if (!$st->fetchColumn()) {
                $responder(404, ['ok' => false, 'mensagem' => 'Tabela não encontrada.']);
                return;
            }
        }
        $erros = [];
        $nome = trim((string) ($_POST['nome'] ?? ''));
        if (mb_strlen($nome) < 2) {
            $erros['nome'] = 'Informe o nome da tabela.';
        } elseif (mb_strlen($nome) > 120) {
            $erros['nome'] = 'Use no máximo 120 caracteres.';
        }
        [$ok, $porcao] = _tn_numero((string) ($_POST['porcao_individual_g'] ?? ''));
        if (!$ok || ($porcao !== null && $porcao <= 0)) {
            $erros['porcao_individual_g'] = 'Use um número maior que zero (ex.: 30).';
        }
        $medida = trim((string) ($_POST['medida_caseira'] ?? ''));
        if (mb_strlen($medida) > 60) {
            $erros['medida_caseira'] = 'Use no máximo 60 caracteres.';
        }
        $valores = [];
        foreach (array_keys($CAMPOS) as $col) {
            [$ok, $v] = _tn_numero((string) ($_POST[$col] ?? ''));
            if (!$ok) {
                $erros[$col] = 'Use só números, sem sinal de menos (ex.: 12,5).';
            }
            $valores[$col] = $v;
        }
        // Alérgenos: só itens conhecidos; o que está em "Contém" sai de "Pode conter".
        $contem = nutri_alergenos_lista((string) ($_POST['alergenos_contem'] ?? ''));
        $traco = array_values(array_diff(nutri_alergenos_lista((string) ($_POST['alergenos_traco'] ?? '')), $contem));
        $gluten = ($_POST['contem_gluten'] ?? '') === '1';
        $manual = null;
        if (($_POST['alergenos_modo'] ?? '') === 'manual') {
            $manual = trim(str_replace("\r\n", "\n", (string) ($_POST['alergenos_manual'] ?? '')));
            if (mb_strlen($manual) > 500) {
                $erros['alergenos_manual'] = 'Use no máximo 500 caracteres.';
            }
        }
        if ($erros) {
            $responder(422, ['ok' => false, 'erros' => $erros, 'mensagem' => 'Confira os campos destacados.']);
            return;
        }
        // Texto final que a loja mostra (a loja não depende da lógica nova).
        $texto = $manual ?? nutri_alergenos_texto($contem, $traco, $gluten);

        $cols = $valores + [
            'nome' => $nome, 'porcao_individual_g' => $porcao, 'medida_caseira' => $medida !== '' ? $medida : null,
            'alergenos_contem' => $contem ? implode(',', $contem) : null, 'alergenos_traco' => $traco ? implode(',', $traco) : null,
            'contem_gluten' => $gluten ? 1 : 0, 'alergenos_manual' => $manual, 'alergenicos' => $texto !== '' ? $texto : null,
        ];
        if ($id > 0) {
            $sets = implode(', ', array_map(fn ($k) => "`$k` = ?", array_keys($cols)));
            db()->prepare("UPDATE tabelas_nutricionais SET $sets, updated_at = NOW() WHERE id = ?")
                ->execute(array_merge(array_values($cols), [$id]));
        } else {
            $lista = implode(', ', array_map(fn ($k) => "`$k`", array_keys($cols)));
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            db()->prepare("INSERT INTO tabelas_nutricionais ($lista, updated_at) VALUES ($ph, NOW())")->execute(array_values($cols));
            $id = (int) db()->lastInsertId();
        }
        $responder(200, ['ok' => true, 'id' => $id, 'mensagem' => 'Tabela salva. Os produtos ligados já mostram os novos valores.',
            'url' => url($BASE) . '?id=' . $id, 'ir' => $BASE . '?id=' . $id]);
        return;
    }

    // ------------------------------------------------- EXCLUIR (lógica) / RESTAURAR
    if ($op === 'excluir' || $op === 'restaurar') {
        $st = db()->prepare('SELECT nome, excluida_em FROM tabelas_nutricionais WHERE id = ?');
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) {
            $responder(404, ['ok' => false, 'mensagem' => 'Tabela não encontrada.']);
            return;
        }
        if ($op === 'restaurar') {
            db()->prepare('UPDATE tabelas_nutricionais SET excluida_em = NULL WHERE id = ?')->execute([$id]);
            $responder(200, ['ok' => true, 'mensagem' => '“' . $t['nome'] . '” voltou.', 'url' => url($BASE) . '?id=' . $id, 'ir' => $BASE . '?id=' . $id]);
            return;
        }
        // Nunca exclui tabela em uso, mesmo que a tela permita.
        $st = db()->prepare('SELECT COUNT(*) FROM produto_tabelas_nutricionais WHERE tabela_nutricional_id = ?');
        $st->execute([$id]);
        $n = (int) $st->fetchColumn();
        if ($n > 0) {
            $responder(409, ['ok' => false, 'mensagem' => 'Para excluir, desligue antes esta tabela dos ' . $n . ' produto(s) que a usam.', 'ir' => $BASE . '?id=' . $id]);
            return;
        }
        db()->prepare('UPDATE tabelas_nutricionais SET excluida_em = NOW() WHERE id = ? AND excluida_em IS NULL')->execute([$id]);
        $responder(200, ['ok' => true, 'id' => $id, 'nome' => $t['nome'], 'mensagem' => '“' . $t['nome'] . '” excluída.',
            'url' => url($BASE), 'ir' => $BASE]);
        return;
    }

    $responder(400, ['ok' => false, 'mensagem' => 'Ação desconhecida.']);
    return;
}

// =============================================================================
// GET
// =============================================================================
if (($params[0] ?? '') === 'novo') {           // link antigo (ex.: favoritos)
    redirect($BASE . '?nova=1');
}
if (($params[0] ?? '') === 'editar') {
    redirect($BASE . '?id=' . (int) ($params[1] ?? 0));
}

// Limpeza definitiva das excluídas há mais de 30 dias (sem produto ligado).
db()->exec('DELETE t FROM tabelas_nutricionais t
             WHERE t.excluida_em < NOW() - INTERVAL 30 DAY
               AND NOT EXISTS (SELECT 1 FROM produto_tabelas_nutricionais pt WHERE pt.tabela_nutricional_id = t.id)');

// Lista: em uso primeiro, depois por nome.
$lista = db()->query(
    'SELECT t.id, t.nome, COUNT(pt.produto_id) AS n
       FROM tabelas_nutricionais t
       LEFT JOIN produto_tabelas_nutricionais pt ON pt.tabela_nutricional_id = t.id
      WHERE t.excluida_em IS NULL
      GROUP BY t.id, t.nome'
)->fetchAll();
$chave_nome = fn (string $n) => mb_strtolower(trim($n), 'UTF-8');
$ordenar = fn (string $n) => gerar_slug($n) . ' ' . $chave_nome($n);
usort($lista, fn ($a, $b) => [(int) $b['n'] > 0, $ordenar($a['nome'])] <=> [(int) $a['n'] > 0, $ordenar($b['nome'])]);
$repetidos = array_count_values(array_map(fn ($t) => $chave_nome($t['nome']), $lista));
$em_uso = count(array_filter($lista, fn ($t) => (int) $t['n'] > 0));
$sem_uso = count($lista) - $em_uso;

// Editor: ?id=12 (existente), ?nova=1 (nova) ou ?nova=1&de=12 (cópia, ainda não salva).
$aberta = null;
$copia = false;
$produtos_ligados = [];
$id_get = (int) ($_GET['id'] ?? 0);
if ($id_get > 0) {
    $st = db()->prepare('SELECT * FROM tabelas_nutricionais WHERE id = ? AND excluida_em IS NULL');
    $st->execute([$id_get]);
    $aberta = $st->fetch() ?: null;
    if (!$aberta) {
        flash('erro', 'Tabela não encontrada.');
        redirect($BASE);
    }
    $st = db()->prepare('SELECT p.id, p.nome FROM produto_tabelas_nutricionais pt JOIN products p ON p.id = pt.produto_id
                          WHERE pt.tabela_nutricional_id = ? ORDER BY p.nome');
    $st->execute([$id_get]);
    $produtos_ligados = $st->fetchAll();
} elseif (isset($_GET['nova'])) {
    $aberta = ['id' => 0, 'nome' => '', 'porcao_individual_g' => null, 'medida_caseira' => null, 'alergenos_contem' => null,
        'alergenos_traco' => null, 'contem_gluten' => 1, 'alergenos_manual' => null, 'updated_at' => null];
    foreach (array_keys($CAMPOS) as $col) { $aberta[$col] = null; }
    $de = (int) ($_GET['de'] ?? 0);
    if ($de > 0) {
        $st = db()->prepare('SELECT * FROM tabelas_nutricionais WHERE id = ? AND excluida_em IS NULL');
        $st->execute([$de]);
        if ($src = $st->fetch()) {
            $aberta = array_merge($src, ['id' => 0, 'nome' => mb_substr($src['nome'] . ' (cópia)', 0, 120), 'updated_at' => null]);
            $copia = true;
        }
    }
}

$sel = $_GET['f'] ?? 'todas';
$sel = in_array($sel, ['todas', 'uso', 'sem'], true) ? $sel : 'todas';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$link = function (array $extra) use ($sel, $q, $BASE): string {
    $p = array_filter(['f' => $sel !== 'todas' ? $sel : '', 'q' => $q] + $extra, fn ($v) => $v !== '' && $v !== null);
    return url($BASE) . ($p ? '?' . http_build_query($p) : '');
};

ob_start();
?>
<div class="tn<?= $aberta ? ' is-editando' : '' ?>" data-tn>
    <?php if ($sem_uso > 0): ?>
        <div class="tn-faixa" data-tn-faixa <?= $sel === 'sem' ? 'hidden' : '' ?>>
            <span><?= $sem_uso ?> <?= $sem_uso === 1 ? 'tabela não está ligada' : 'tabelas não estão ligadas' ?> a nenhum produto, e algumas parecem ter sido criadas por engano (nomes como “senha”).</span>
            <button type="button" class="ap-btn tn-faixa-btn" data-tn-filtro="sem">Revisar e limpar</button>
        </div>
    <?php endif; ?>

    <div class="tn-split">
        <!-- Lista -->
        <aside class="tn-lista" aria-label="Tabelas nutricionais">
            <div class="tn-ferramentas">
                <input type="search" class="tn-busca" placeholder="Buscar" aria-label="Buscar tabela" value="<?= e($q) ?>" autocomplete="off" data-tn-q>
                <div class="tn-filtros" role="group" aria-label="Filtrar">
                    <?php foreach (['todas' => ['Todas', count($lista)], 'uso' => ['Em uso', $em_uso], 'sem' => ['Sem produto', $sem_uso]] as $k => [$rot, $n]): ?>
                        <button type="button" class="tn-pill" data-tn-filtro="<?= $k ?>" aria-pressed="<?= $sel === $k ? 'true' : 'false' ?>"><?= e($rot) ?> <span class="ap-num">(<?= $n ?>)</span></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <ul class="tn-itens" data-tn-itens>
                <?php foreach ($lista as $t): $n = (int) $t['n']; ?>
                    <li data-tn-item data-nome="<?= e($chave_nome($t['nome'])) ?>" data-uso="<?= $n > 0 ? 1 : 0 ?>">
                        <a href="<?= e($link(['id' => (int) $t['id']])) ?>" data-tn-nav <?= $aberta && (int) $aberta['id'] === (int) $t['id'] ? 'aria-current="true"' : '' ?>>
                            <b><?= e($t['nome']) ?></b>
                            <small><?= $n ? $n . ' produto' . ($n > 1 ? 's' : '') : 'Nenhum produto' ?><?= $repetidos[$chave_nome($t['nome'])] > 1 ? ' · nome repetido' : '' ?></small>
                        </a>
                    </li>
                <?php endforeach; ?>
                <li class="tn-vazio" data-tn-vazio hidden>Nada encontrado.</li>
            </ul>
            <a class="ap-btn ap-btn-primario tn-nova" href="<?= e($link(['nova' => 1])) ?>" data-tn-nav>+ Nova tabela</a>
        </aside>

        <!-- Editor -->
        <div class="tn-direita">
            <?php if (!$aberta): ?>
                <div class="tn-placeholder">Escolha uma tabela à esquerda para ver e editar.</div>
            <?php else:
                $novo = (int) $aberta['id'] === 0;
                $manual = $aberta['alergenos_manual'] !== null;
                $contem = nutri_alergenos_lista($aberta['alergenos_contem'] ?? '');
                $traco = nutri_alergenos_lista($aberta['alergenos_traco'] ?? '');
                $gluten = (int) $aberta['contem_gluten'] === 1;
                $texto_auto = nutri_alergenos_texto($contem, $traco, $gluten);
                $previa = $aberta;
                $previa['alergenicos'] = $manual ? $aberta['alergenos_manual'] : $texto_auto;
                $dados = [
                    'id' => (int) $aberta['id'], 'novo' => $novo, 'copia' => $copia, 'csrf' => csrf_token(),
                    'urlPost' => url($BASE), 'urlLista' => $link([]),
                    'campos' => array_map(fn ($c) => ['rotulo' => $c[0], 'un' => $c[1], 'casas' => $c[2], 'vd' => $c[3], 'nivel' => $c[4]], $CAMPOS),
                    'nomes' => array_map(fn ($t) => [(int) $t['id'], $chave_nome($t['nome'])], $lista),
                ];
                ?>
                <form class="tn-editor" method="post" action="<?= e(url($BASE)) ?>" novalidate data-tn-form>
                    <?= csrf_input() ?>
                    <input type="hidden" name="op" value="salvar">
                    <input type="hidden" name="id" value="<?= (int) $aberta['id'] ?>">

                    <div class="tn-topo">
                        <a class="pe-voltar" href="<?= e($link([])) ?>" data-tn-nav>← Tabelas nutricionais</a>
                        <h2 class="tn-titulo" data-tn-titulo><?= e($aberta['nome'] !== '' ? $aberta['nome'] : 'Nova tabela nutricional') ?></h2>
                        <p class="tn-sub"><?= $novo ? 'Preencha os valores do rótulo do produto.'
                            : 'Última alteração ' . e(!empty($aberta['updated_at']) ? date('d/m/Y \à\s H:i', strtotime($aberta['updated_at'])) : date('d/m/Y \à\s H:i', strtotime($aberta['created_at']))) ?></p>
                    </div>

                    <!-- a) Identificação -->
                    <section class="pe-card" aria-labelledby="tn-h-id">
                        <h2 id="tn-h-id">Identificação</h2>
                        <p class="legenda-obrigatorio"><span aria-hidden="true">*</span> Campos obrigatórios</p>
                        <div class="tn-grid3">
                            <div class="pe-campo tn-f-nome">
                                <label for="tn-nome">Nome da tabela</label>
                                <input id="tn-nome" name="nome" type="text" maxlength="120" required autocomplete="off"
                                       value="<?= e($aberta['nome']) ?>" placeholder="Ex.: Biscoito de baunilha" aria-describedby="tn-nome-dica tn-nome-erro">
                                <span class="pe-dica" id="tn-nome-dica" data-tn-dup hidden>Já existe outra tabela com esse nome.</span>
                                <span class="pe-erro" id="tn-nome-erro" data-tn-erro="nome" role="alert" hidden></span>
                            </div>
                            <div class="pe-campo">
                                <label for="tn-porcao">Porção</label>
                                <span class="pe-affix"><input id="tn-porcao" name="porcao_individual_g" type="text" inputmode="decimal" autocomplete="off" class="ap-num"
                                       value="<?= e(_tn_valor_campo($aberta['porcao_individual_g'])) ?>" placeholder="30" aria-describedby="tn-porcao-erro"><span aria-hidden="true">g</span></span>
                                <span class="pe-erro" id="tn-porcao-erro" data-tn-erro="porcao_individual_g" role="alert" hidden></span>
                            </div>
                            <div class="pe-campo">
                                <label for="tn-medida">Medida caseira</label>
                                <input id="tn-medida" name="medida_caseira" type="text" maxlength="60" autocomplete="off"
                                       value="<?= e((string) $aberta['medida_caseira']) ?>" placeholder="Ex.: 3 biscoitos" aria-describedby="tn-medida-erro">
                                <span class="pe-erro" id="tn-medida-erro" data-tn-erro="medida_caseira" role="alert" hidden></span>
                            </div>
                        </div>
                    </section>

                    <!-- b) Valores -->
                    <section class="pe-card" aria-labelledby="tn-h-val">
                        <h2 id="tn-h-val">Valores nutricionais <small>por 100 g do produto</small></h2>
                        <p class="pe-ajuda tn-ajuda">Digite os valores por 100 g, com vírgula se precisar. A porção e o %VD são calculados.</p>
                        <div class="tn-vals" role="table" aria-label="Valores nutricionais">
                            <div class="tn-vhead" role="row"><span role="columnheader">Nutriente</span><span role="columnheader">100 g</span><span role="columnheader">Porção</span><span role="columnheader">%VD</span></div>
                            <?php foreach ($CAMPOS as $col => [$rot, $un, $casas, $vd, $nivel]): ?>
                                <div class="tn-vrow<?= $nivel ? ' nivel-' . $nivel : '' ?>" role="row" data-tn-linha="<?= $col ?>">
                                    <label for="tn-<?= $col ?>" role="rowheader"><?= e($rot) ?></label>
                                    <span class="pe-affix"><input id="tn-<?= $col ?>" name="<?= $col ?>" type="text" inputmode="decimal" autocomplete="off" class="ap-num"
                                           value="<?= e(_tn_valor_campo($aberta[$col])) ?>" placeholder="—" aria-describedby="tn-<?= $col ?>-erro"><span aria-hidden="true"><?= e($un) ?></span></span>
                                    <span class="tn-calc ap-num" role="cell" data-tn-porcao="<?= $col ?>">—</span>
                                    <span class="tn-calc ap-num" role="cell" data-tn-vd="<?= $col ?>"></span>
                                    <span class="pe-erro tn-verro" id="tn-<?= $col ?>-erro" data-tn-erro="<?= $col ?>" role="alert" hidden></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="tn-avisos" data-tn-avisos aria-live="polite"></div>
                    </section>

                    <!-- c) Prévia -->
                    <section class="pe-card" aria-labelledby="tn-h-prev">
                        <h2 id="tn-h-prev">Prévia</h2>
                        <div class="tn-previa" data-tn-previa><?= nutri_rotulo_html($previa) ?></div>
                    </section>

                    <!-- d) Alérgenos -->
                    <section class="pe-card" aria-labelledby="tn-h-alg">
                        <h2 id="tn-h-alg">Alérgenos</h2>
                        <input type="hidden" name="alergenos_contem" value="<?= e(implode(',', $contem)) ?>" data-tn-lista="contem">
                        <input type="hidden" name="alergenos_traco" value="<?= e(implode(',', $traco)) ?>" data-tn-lista="traco">
                        <input type="hidden" name="contem_gluten" value="<?= $gluten ? 1 : 0 ?>" data-tn-gluten-campo>
                        <input type="hidden" name="alergenos_modo" value="<?= $manual ? 'manual' : 'auto' ?>" data-tn-modo>
                        <?php foreach (['contem' => ['Contém', $contem], 'traco' => ['Pode conter (traços)', $traco]] as $grupo => [$rot, $marcados]): ?>
                            <div>
                                <p class="tn-sublabel" id="tn-alg-<?= $grupo ?>"><?= e($rot) ?></p>
                                <div class="tn-chips" role="group" aria-labelledby="tn-alg-<?= $grupo ?>">
                                    <?php foreach (NUTRI_ALERGENOS as $a): ?>
                                        <button type="button" class="tn-chip tn-chip-<?= $grupo ?>" data-tn-alg="<?= $grupo ?>" data-a="<?= e($a) ?>"
                                                aria-pressed="<?= in_array($a, $marcados, true) ? 'true' : 'false' ?>"><?= e($a) ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="button" class="pe-tg" role="switch" aria-checked="<?= $gluten ? 'true' : 'false' ?>" data-tn-gluten aria-describedby="tn-glu-ajuda">
                            <span class="pe-tg-txt"><b>Contém glúten</b><span id="tn-glu-ajuda">Obrigatório no rótulo: aparece “Contém glúten” ou “Não contém glúten”.</span></span>
                            <span class="pe-tg-trilho" aria-hidden="true"></span>
                        </button>
                        <div>
                            <p class="tn-sublabel">Texto que aparece na loja</p>
                            <div data-tn-auto <?= $manual ? 'hidden' : '' ?>>
                                <p class="tn-algtexto" data-tn-algtexto><?= e($texto_auto) ?></p>
                                <button type="button" class="pe-link" data-tn-ir-manual>Escrever o texto à mão</button>
                            </div>
                            <div class="pe-campo" data-tn-manual <?= $manual ? '' : 'hidden' ?>>
                                <label class="tn-sr" for="tn-manual">Texto de alérgenos</label>
                                <textarea id="tn-manual" name="alergenos_manual" rows="3" maxlength="500" aria-describedby="tn-manual-erro"><?= e((string) $aberta['alergenos_manual']) ?></textarea>
                                <span class="pe-erro" id="tn-manual-erro" data-tn-erro="alergenos_manual" role="alert" hidden></span>
                                <button type="button" class="pe-link" data-tn-ir-auto>Voltar ao texto automático</button>
                            </div>
                        </div>
                    </section>

                    <?php if (!$novo): ?>
                        <!-- e) Usada em -->
                        <section class="pe-card" aria-labelledby="tn-h-uso">
                            <h2 id="tn-h-uso">Usada em</h2>
                            <?php if ($produtos_ligados): ?>
                                <div class="tn-usada">
                                    <?php foreach ($produtos_ligados as $p): ?>
                                        <a href="<?= e(url('admin/produtos/editar/' . (int) $p['id'])) ?>" data-tn-nav><?= e($p['nome']) ?> ↗</a>
                                    <?php endforeach; ?>
                                </div>
                                <p class="pe-ajuda tn-ajuda">Para trocar a tabela de um produto, abra o produto. Mudanças aqui valem para todos eles.</p>
                            <?php else: ?>
                                <p class="pe-ajuda tn-ajuda">Nenhum produto usa esta tabela ainda. Ligue-a na tela de edição do produto.</p>
                            <?php endif; ?>
                        </section>

                        <!-- f) Outras ações -->
                        <section class="pe-card tn-perigo" aria-labelledby="tn-h-acoes">
                            <h2 id="tn-h-acoes">Outras ações</h2>
                            <div class="tn-acoes">
                                <a class="ap-btn ap-btn-linha" href="<?= e($link(['nova' => 1, 'de' => (int) $aberta['id']])) ?>" data-tn-nav>Duplicar tabela</a>
                                <?php if ($produtos_ligados): ?>
                                    <span class="pe-ajuda">Para excluir, desligue antes esta tabela dos <?= count($produtos_ligados) ?> produto(s) acima.</span>
                                <?php else: ?>
                                    <button type="button" class="ap-btn tn-btn-excluir" data-tn-excluir>Excluir tabela</button>
                                    <span class="tn-confirmar" data-tn-confirmar hidden>
                                        <b>Excluir “<?= e($aberta['nome']) ?>”?</b>
                                        <button type="button" class="ap-btn ap-btn-perigo-cheio" data-tn-excluir-sim>Sim, excluir</button>
                                        <button type="button" class="ap-btn" data-tn-excluir-nao>Cancelar</button>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <div class="pe-barra" role="region" aria-label="Salvar tabela">
                        <span class="pe-estado" data-tn-estado><i aria-hidden="true"></i><span data-tn-estado-txt><?= $novo ? 'Tabela nova' : 'Tudo salvo' ?></span></span>
                        <a class="ap-btn ap-btn-linha" href="<?= e($link([])) ?>" data-tn-nav data-tn-descartar>Voltar</a>
                        <button type="submit" class="ap-btn ap-btn-primario" data-tn-salvar disabled><?= $novo ? 'Criar tabela' : 'Salvar alterações' ?></button>
                    </div>
                </form>
                <script type="application/json" id="tn-dados"><?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sair sem salvar? -->
    <div class="tn-modal" data-tn-modal hidden>
        <div role="dialog" aria-modal="true" aria-labelledby="tn-modal-titulo">
            <h2 id="tn-modal-titulo">Sair sem salvar?</h2>
            <p data-tn-modal-texto>As alterações vão ser perdidas.</p>
            <div class="tn-modal-botoes">
                <button type="button" class="ap-btn ap-btn-linha" data-tn-ficar>Continuar editando</button>
                <button type="button" class="ap-btn ap-btn-perigo-cheio" data-tn-sair>Descartar</button>
            </div>
        </div>
    </div>
    <div class="pe-toast" data-tn-toast role="status" aria-live="polite" hidden></div>
</div>
<script>window.TN_URL = <?= json_encode(['post' => url($BASE), 'csrf' => csrf_token()]) ?>;</script>
<script src="<?= e(asset('assets/js/admin-tabelas-nutri.js')) ?>"></script>
<?php
view('admin_layout', ['titulo' => 'Tabelas nutricionais', 'conteudo' => ob_get_clean(), 'layout_largo' => true]);
