<?php
/**
 * Admin: editar as configurações da loja (tabela settings). Rota: /admin/configuracoes
 * Salva cada chave com INSERT ... ON DUPLICATE KEY UPDATE. Valores monetários
 * são digitados em reais e gravados em centavos. As cores refletem no site todo,
 * pois o tema usa as variáveis vindas de settings.
 */
exigir_admin();

// Campos por tipo de tratamento.
$campos_texto    = ['site_nome', 'site_descricao', 'whatsapp_numero', 'whatsapp_msg',
                    'regras_texto', 'sobre_texto', 'entrega_obs'];
$campos_dinheiro = ['entrega_taxa_centavos', 'parcelamento_limite_centavos'];
$campos_inteiro  = ['parcelamento_max'];
$campos_cor      = ['cor_primaria', 'cor_destaque', 'cor_acento',
                    'cor_pop', 'cor_fundo', 'cor_texto'];

// Cores padrão (caso a chave ainda não exista).
$cor_padrao = [
    'cor_primaria' => '#6B4A2C', 'cor_destaque' => '#D4A53F', 'cor_acento' => '#8C9A5E',
    'cor_pop' => '#BC5B38', 'cor_fundo' => '#F6EEDD', 'cor_texto' => '#4A3320',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/configuracoes');
    }

    $stmt = db()->prepare(
        'INSERT INTO settings (chave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = ?'
    );

    foreach ($campos_texto as $k) {
        $v = trim($_POST[$k] ?? '');
        $stmt->execute([$k, $v, $v]);
    }
    foreach ($campos_dinheiro as $k) {
        $v = (string) reais_para_centavos($_POST[$k] ?? '');
        $stmt->execute([$k, $v, $v]);
    }
    foreach ($campos_inteiro as $k) {
        $v = (string) (int) ($_POST[$k] ?? 0);
        $stmt->execute([$k, $v, $v]);
    }
    foreach ($campos_cor as $k) {
        $cor = trim($_POST[$k] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) {
            continue; // ignora cor em formato inválido
        }
        $stmt->execute([$k, $cor, $cor]);
    }

    flash('sucesso', 'Configurações salvas com sucesso.');
    redirect('admin/configuracoes');
}

ob_start();
?>
<form class="formulario" method="post" action="<?= e(url('admin/configuracoes')) ?>"
      style="max-width:640px;">
    <?= csrf_input() ?>

    <h2>Identidade</h2>
    <div class="campo">
        <label for="site_nome">Nome da loja</label>
        <input type="text" id="site_nome" name="site_nome" value="<?= e(cfg('site_nome', '')) ?>">
    </div>
    <div class="campo">
        <label for="site_descricao">Descrição</label>
        <input type="text" id="site_descricao" name="site_descricao"
               value="<?= e(cfg('site_descricao', '')) ?>">
    </div>

    <h2>Contato / venda</h2>
    <div class="campo">
        <label for="whatsapp_numero">WhatsApp (número)</label>
        <input type="text" id="whatsapp_numero" name="whatsapp_numero"
               value="<?= e(cfg('whatsapp_numero', '')) ?>" placeholder="Ex.: 5511999999999">
    </div>
    <div class="campo">
        <label for="whatsapp_msg">Mensagem padrão do WhatsApp</label>
        <input type="text" id="whatsapp_msg" name="whatsapp_msg"
               value="<?= e(cfg('whatsapp_msg', '')) ?>">
        <small>Use <code>{produto}</code> para inserir o nome do produto na mensagem.</small>
    </div>

    <h2>Textos</h2>
    <div class="campo">
        <label for="regras_texto">Regras gerais</label>
        <textarea id="regras_texto" name="regras_texto" rows="5"><?= e(cfg('regras_texto', '')) ?></textarea>
    </div>
    <div class="campo">
        <label for="sobre_texto">Sobre a loja</label>
        <textarea id="sobre_texto" name="sobre_texto" rows="5"><?= e(cfg('sobre_texto', '')) ?></textarea>
    </div>

    <h2>Entrega</h2>
    <div class="campo">
        <label for="entrega_taxa_centavos">Taxa de entrega (R$)</label>
        <input type="text" id="entrega_taxa_centavos" name="entrega_taxa_centavos" inputmode="decimal"
               value="<?= e(centavos_para_input((int) cfg('entrega_taxa_centavos', '0'))) ?>"
               placeholder="Ex.: 10,00">
    </div>
    <div class="campo">
        <label for="entrega_obs">Observação de entrega</label>
        <textarea id="entrega_obs" name="entrega_obs" rows="2"><?= e(cfg('entrega_obs', '')) ?></textarea>
    </div>

    <h2>Pagamento</h2>
    <div class="campo">
        <label for="parcelamento_limite_centavos">Valor mínimo para parcelar (R$)</label>
        <input type="text" id="parcelamento_limite_centavos" name="parcelamento_limite_centavos"
               inputmode="decimal"
               value="<?= e(centavos_para_input((int) cfg('parcelamento_limite_centavos', '0'))) ?>"
               placeholder="Ex.: 120,00">
    </div>
    <div class="campo">
        <label for="parcelamento_max">Máximo de parcelas</label>
        <input type="number" id="parcelamento_max" name="parcelamento_max" min="1"
               value="<?= (int) cfg('parcelamento_max', '1') ?>">
    </div>

    <h2>Tema (cores)</h2>
    <?php
    $rotulos_cor = [
        'cor_primaria' => 'Primária', 'cor_destaque' => 'Destaque', 'cor_acento' => 'Acento',
        'cor_pop' => 'Pop (botões)', 'cor_fundo' => 'Fundo', 'cor_texto' => 'Texto',
    ];
    ?>
    <?php foreach ($campos_cor as $k): ?>
        <div class="campo campo-inline">
            <input type="color" id="<?= e($k) ?>" name="<?= e($k) ?>"
                   value="<?= e(cfg($k, $cor_padrao[$k])) ?>" style="width:56px;height:38px;padding:2px;">
            <label for="<?= e($k) ?>"><?= e($rotulos_cor[$k]) ?> (<?= e(cfg($k, $cor_padrao[$k])) ?>)</label>
        </div>
    <?php endforeach; ?>

    <button class="btn mt-1" type="submit">Salvar configurações</button>
</form>
<?php
view('admin_layout', ['titulo' => 'Configurações', 'conteudo' => ob_get_clean()]);
