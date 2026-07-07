<?php
/**
 * Admin: Tabelas nutricionais. CRUD sobre tabelas_nutricionais.
 * Uma tabela nutricional pode ser usada em vários produtos (produto_tabelas_nutricionais).
 * Rotas:
 *   /admin/tabelas-nutricionais              -> listar
 *   /admin/tabelas-nutricionais/novo         -> form de criação
 *   /admin/tabelas-nutricionais/editar/{id}  -> form de edição
 *   POST op=salvar / op=excluir
 */
exigir_admin();

// Porção padrão FIXA de 100 g: a cliente informa os valores POR 100 g e os
// gramas da porção individual (porcao_individual_g). Todos opcionais, exceto o nome.
$campos_num = [
    'nutri_valor_energetico' => 'Valor energético (kcal)',
    'nutri_carboidratos'     => 'Carboidratos (g)',
    'nutri_acucares_totais'  => 'Açúcares totais (g)',
    'nutri_acucares_add'     => 'Açúcares adicionados (g)',
    'nutri_proteinas'        => 'Proteínas (g)',
    'nutri_gorduras_totais'  => 'Gorduras totais (g)',
    'nutri_gorduras_sat'     => 'Gorduras saturadas (g)',
    'nutri_gorduras_trans'   => 'Gorduras trans (g)',
    'nutri_fibra'            => 'Fibra alimentar (g)',
    'nutri_sodio'            => 'Sódio (mg)',
];

/** Texto opcional: vazio vira NULL. */
function _rec_txt($v)
{
    $v = trim((string) $v);
    return $v === '' ? null : $v;
}
/** Número opcional: aceita vírgula ou ponto; vazio/inválido vira NULL. */
function _rec_num($v)
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? $v : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/tabelas-nutricionais');
    }

    $op = $_POST['op'] ?? '';

    if ($op === 'excluir') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            // FK ON DELETE CASCADE remove as ligações em produto_tabelas_nutricionais.
            $stmt = db()->prepare('DELETE FROM tabelas_nutricionais WHERE id = ?');
            $stmt->execute([$id]);
            flash('sucesso', 'Tabela nutricional excluída e removida dos produtos que a utilizavam.');
        }
        redirect('admin/tabelas-nutricionais');
    }

    if ($op === 'salvar') {
        $id   = (int) ($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');

        $destino_erro = $id > 0 ? 'admin/tabelas-nutricionais/editar/' . $id : 'admin/tabelas-nutricionais/novo';
        if (mb_strlen($nome) < 2) {
            flash('erro', 'Informe o nome da tabela nutricional.');
            redirect($destino_erro);
        }

        // Monta o conjunto de colunas -> valores (whitelist; nada vem cru do usuário).
        $cols = ['nome' => $nome, 'alergenicos' => _rec_txt($_POST['alergenicos'] ?? '')];
        $cols['porcao_individual_g'] = _rec_num($_POST['porcao_individual_g'] ?? '');
        foreach (array_keys($campos_num) as $c) {
            $cols[$c] = _rec_num($_POST[$c] ?? '');
        }

        if ($id > 0) {
            $sets = implode(', ', array_map(static function ($k) {
                return $k . ' = ?';
            }, array_keys($cols)));
            $params = array_values($cols);
            $params[] = $id;
            db()->prepare("UPDATE tabelas_nutricionais SET {$sets} WHERE id = ?")->execute($params);
            flash('sucesso', 'Tabela nutricional atualizada.');
        } else {
            $lista = implode(', ', array_keys($cols));
            $ph = implode(', ', array_fill(0, count($cols), '?'));
            db()->prepare("INSERT INTO tabelas_nutricionais ({$lista}) VALUES ({$ph})")
                ->execute(array_values($cols));
            flash('sucesso', 'Tabela nutricional criada.');
        }
        redirect('admin/tabelas-nutricionais');
    }

    redirect('admin/tabelas-nutricionais');
}

// --- GET ---------------------------------------------------------------------
$acao = $params[0] ?? 'listar';

if ($acao === 'novo' || $acao === 'editar') {
    // Valores padrão vazios.
    $rec = ['id' => 0, 'nome' => '', 'alergenicos' => '', 'porcao_individual_g' => ''];
    foreach (array_keys($campos_num) as $c) { $rec[$c] = ''; }

    if ($acao === 'editar') {
        $id = (int) ($params[1] ?? 0);
        $stmt = db()->prepare('SELECT * FROM tabelas_nutricionais WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            flash('erro', 'Tabela nutricional não encontrada.');
            redirect('admin/tabelas-nutricionais');
        }
        $rec = $row;
    }

    $titulo = $rec['id'] > 0 ? 'Editar tabela nutricional' : 'Nova tabela nutricional';

    ob_start();
    ?>
    <p><a href="<?= e(url('admin/tabelas-nutricionais')) ?>">&larr; Voltar para tabelas nutricionais</a></p>

    <form class="formulario" method="post" action="<?= e(url('admin/tabelas-nutricionais')) ?>" style="max-width:640px;">
        <?= csrf_input() ?>
        <input type="hidden" name="op" value="salvar">
        <input type="hidden" name="id" value="<?= (int) $rec['id'] ?>">

        <div class="campo">
            <label for="nome">Nome da tabela nutricional</label>
            <input type="text" id="nome" name="nome" value="<?= e($rec['nome']) ?>" required>
        </div>
        <div class="campo">
            <label for="alergenicos">Aviso de alérgenos</label>
            <textarea id="alergenicos" name="alergenicos" rows="2"
                      placeholder="Ex.: Contém glúten, leite e ovos."><?= e($rec['alergenicos'] ?? '') ?></textarea>
        </div>

        <div class="campo">
            <label for="porcao_individual_g">Porção individual (g)</label>
            <input type="number" step="0.01" min="0" id="porcao_individual_g" name="porcao_individual_g"
                   value="<?= e($rec['porcao_individual_g'] ?? '') ?>" placeholder="Ex.: 30">
            <small>Quantos gramas tem a porção individual (ex.: 30). Usado para calcular os valores por porção.</small>
        </div>

        <h2 class="mt-1">Valores nutricionais (por 100 g)</h2>
        <p><small>Informe os valores considerando <strong>100 g</strong> do produto.</small></p>
        <?php foreach ($campos_num as $k => $rotulo): ?>
            <div class="campo">
                <label for="<?= e($k) ?>"><?= e($rotulo) ?></label>
                <input type="number" step="0.01" min="0" id="<?= e($k) ?>" name="<?= e($k) ?>"
                       value="<?= e($rec[$k] ?? '') ?>">
            </div>
        <?php endforeach; ?>

        <button class="btn" type="submit">Salvar</button>
    </form>
    <?php
    view('admin_layout', ['titulo' => $titulo, 'conteudo' => ob_get_clean()]);
    return;
}

// Listagem
$tabelas = db()->query(
    'SELECT id, nome, updated_at FROM tabelas_nutricionais ORDER BY nome ASC'
)->fetchAll();

ob_start();
?>
<p><a class="btn" href="<?= e(url('admin/tabelas-nutricionais/novo')) ?>">Nova tabela nutricional</a></p>

<?php if (empty($tabelas)): ?>
    <p>Nenhuma tabela nutricional cadastrada.</p>
<?php else: ?>
    <table class="tabela">
        <thead>
            <tr>
                <th>Nome</th>
                <th class="t-centro">Última atualização</th>
                <th class="col-acoes">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tabelas as $r): ?>
                <tr>
                    <td><?= e($r['nome']) ?></td>
                    <td class="t-centro">
                        <?= !empty($r['updated_at'])
                            ? e(date('d/m/Y H:i', strtotime($r['updated_at'])))
                            : '—' ?>
                    </td>
                    <td class="col-acoes">
                        <a class="btn sec" href="<?= e(url('admin/tabelas-nutricionais/editar/' . $r['id'])) ?>">Editar</a>
                        <form method="post" action="<?= e(url('admin/tabelas-nutricionais')) ?>" style="display:inline"
                              onsubmit="return confirm('Excluir esta tabela nutricional? Ela será removida de todos os produtos que a utilizam.');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="op" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="btn" type="submit">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php
view('admin_layout', ['titulo' => 'Tabelas nutricionais', 'conteudo' => ob_get_clean()]);
