<?php
/**
 * Admin: configurações da loja (settings) em TRÊS ABAS. Rota: /admin/configuracoes
 *
 * As cores e o nome da loja NÃO são editáveis aqui (cores são fixas no theme.css).
 * Cada chave é gravada com INSERT ... ON DUPLICATE KEY UPDATE. Valores monetários
 * são digitados em reais e gravados em centavos.
 */
exigir_admin();

// Chaves por aba e por tipo de tratamento.
$abas_campos = [
    'comercial' => [
        'texto' => ['site_descricao', 'whatsapp_numero', 'endereco', 'cnpj',
                    'rede_instagram', 'rede_facebook', 'rede_tiktok'],
    ],
    'pagamento' => [
        'dinheiro' => ['parcelamento_limite_centavos'],
        'inteiro'  => ['parcelamento_max'],
    ],
    'config' => [
        'texto' => ['regras_texto', 'whatsapp_msg', 'sobre_texto',
                    'bloco_editorial_titulo', 'bloco_editorial_subtitulo',
                    'bloco_editorial_botao_texto', 'bloco_editorial_botao_link'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/configuracoes');
    }

    $aba = $_POST['aba'] ?? '';
    if (!isset($abas_campos[$aba])) {
        redirect('admin/configuracoes');
    }

    $stmt = db()->prepare(
        'INSERT INTO settings (chave, valor) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE valor = ?'
    );

    $grupo = $abas_campos[$aba];
    foreach (($grupo['texto'] ?? []) as $k) {
        $v = trim($_POST[$k] ?? '');
        $stmt->execute([$k, $v, $v]);
    }
    foreach (($grupo['dinheiro'] ?? []) as $k) {
        $v = (string) reais_para_centavos($_POST[$k] ?? '');
        $stmt->execute([$k, $v, $v]);
    }
    foreach (($grupo['inteiro'] ?? []) as $k) {
        $v = (string) (int) ($_POST[$k] ?? 0);
        $stmt->execute([$k, $v, $v]);
    }

    // Imagem do bloco editorial (aba "config"): só troca se uma nova for enviada.
    if ($aba === 'config' && !empty($_FILES['bloco_editorial_imagem']['name'])) {
        $res = processar_upload_imagem($_FILES['bloco_editorial_imagem'], ['gerar_miniatura' => false]);
        if (empty($res['ok'])) {
            flash('erro', $res['erro'] ?? 'Falha ao enviar a imagem do bloco.');
            flash('aba', 'config');
            redirect('admin/configuracoes');
        }
        $antiga = cfg('bloco_editorial_imagem', '');
        if ($antiga !== '') {
            imagem_apagar($antiga);
        }
        $stmt->execute(['bloco_editorial_imagem', $res['arquivo'], $res['arquivo']]);
    }

    flash('sucesso', 'Configurações salvas com sucesso.');
    flash('aba', $aba);
    redirect('admin/configuracoes');
}

// Aba ativa: a que foi salva (flash) ou a primeira.
$aba = flash_consumir('aba');
$aba = isset($abas_campos[$aba]) ? $aba : 'comercial';

ob_start();
?>
<div class="abas">
    <button type="button" class="aba<?= $aba === 'comercial' ? ' ativa' : '' ?>"
            data-aba="comercial">Informações comerciais</button>
    <button type="button" class="aba<?= $aba === 'pagamento' ? ' ativa' : '' ?>"
            data-aba="pagamento">Entrega e pagamento</button>
    <button type="button" class="aba<?= $aba === 'config' ? ' ativa' : '' ?>"
            data-aba="config">Configurações</button>
</div>

<!-- Aba 1: Informações comerciais -->
<form method="post" action="<?= e(url('admin/configuracoes')) ?>"
      class="formulario painel<?= $aba === 'comercial' ? ' ativo' : '' ?>" data-painel="comercial"
      style="max-width:640px;">
    <?= csrf_input() ?>
    <input type="hidden" name="aba" value="comercial">

    <div class="campo">
        <label for="site_descricao">Descrição da loja</label>
        <input type="text" id="site_descricao" name="site_descricao"
               value="<?= e(cfg('site_descricao', '')) ?>">
    </div>
    <div class="campo">
        <label for="whatsapp_numero">Telefone / WhatsApp</label>
        <input type="text" id="whatsapp_numero" name="whatsapp_numero"
               value="<?= e(cfg('whatsapp_numero', '')) ?>" placeholder="Ex.: 5511999999999">
    </div>
    <div class="campo">
        <label for="endereco">Endereço</label>
        <input type="text" id="endereco" name="endereco" value="<?= e(cfg('endereco', '')) ?>">
    </div>
    <div class="campo">
        <label for="cnpj">CNPJ</label>
        <input type="text" id="cnpj" name="cnpj" value="<?= e(cfg('cnpj', '')) ?>">
    </div>
    <div class="campo">
        <label for="rede_instagram">Instagram (URL)</label>
        <input type="url" id="rede_instagram" name="rede_instagram"
               value="<?= e(cfg('rede_instagram', '')) ?>" placeholder="https://instagram.com/...">
    </div>
    <div class="campo">
        <label for="rede_facebook">Facebook (URL)</label>
        <input type="url" id="rede_facebook" name="rede_facebook"
               value="<?= e(cfg('rede_facebook', '')) ?>" placeholder="https://facebook.com/...">
    </div>
    <div class="campo">
        <label for="rede_tiktok">TikTok (URL)</label>
        <input type="url" id="rede_tiktok" name="rede_tiktok"
               value="<?= e(cfg('rede_tiktok', '')) ?>" placeholder="https://tiktok.com/@...">
    </div>

    <button class="btn" type="submit">Salvar</button>
</form>

<!-- Aba 2: Entrega e pagamento -->
<form method="post" action="<?= e(url('admin/configuracoes')) ?>"
      class="formulario painel<?= $aba === 'pagamento' ? ' ativo' : '' ?>" data-painel="pagamento"
      style="max-width:640px;">
    <?= csrf_input() ?>
    <input type="hidden" name="aba" value="pagamento">

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

    <button class="btn" type="submit">Salvar</button>
</form>

<!-- Aba 3: Configurações (textos + bloco editorial) -->
<form method="post" action="<?= e(url('admin/configuracoes')) ?>"
      class="formulario painel<?= $aba === 'config' ? ' ativo' : '' ?>" data-painel="config"
      enctype="multipart/form-data" style="max-width:640px;">
    <?= csrf_input() ?>
    <input type="hidden" name="aba" value="config">

    <div class="campo">
        <label for="regras_texto">Regras gerais</label>
        <textarea id="regras_texto" name="regras_texto" rows="5"><?= e(cfg('regras_texto', '')) ?></textarea>
    </div>
    <div class="campo">
        <label for="whatsapp_msg">Mensagem padrão do WhatsApp</label>
        <input type="text" id="whatsapp_msg" name="whatsapp_msg" value="<?= e(cfg('whatsapp_msg', '')) ?>">
        <small>Use <code>{produto}</code> para inserir o nome do produto na mensagem.</small>
    </div>
    <div class="campo">
        <label for="sobre_texto">Sobre nós</label>
        <textarea id="sobre_texto" name="sobre_texto" rows="5"><?= e(cfg('sobre_texto', '')) ?></textarea>
    </div>

    <h2 class="mt-1">Bloco editorial da home</h2>
    <?php $bloco_img = cfg('bloco_editorial_imagem', ''); ?>
    <?php if ($bloco_img !== '' && is_file(ROOT_PATH . '/assets/uploads/' . $bloco_img)): ?>
        <div class="campo">
            <label>Imagem atual</label>
            <img src="<?= e(asset('assets/uploads/' . $bloco_img)) ?>" alt=""
                 style="max-width:100%;border-radius:var(--raio);">
        </div>
    <?php endif; ?>
    <div class="campo">
        <label for="bloco_editorial_imagem">Imagem (deixe vazio para manter a atual)</label>
        <input type="file" id="bloco_editorial_imagem" name="bloco_editorial_imagem"
               accept="image/jpeg,image/png,image/webp">
        <small>A imagem é otimizada automaticamente (máx. 1200px, JPEG).</small>
    </div>
    <div class="campo">
        <label for="bloco_editorial_titulo">Título</label>
        <input type="text" id="bloco_editorial_titulo" name="bloco_editorial_titulo"
               value="<?= e(cfg('bloco_editorial_titulo', '')) ?>">
    </div>
    <div class="campo">
        <label for="bloco_editorial_subtitulo">Subtítulo</label>
        <textarea id="bloco_editorial_subtitulo" name="bloco_editorial_subtitulo" rows="3"><?= e(cfg('bloco_editorial_subtitulo', '')) ?></textarea>
    </div>
    <div class="campo">
        <label for="bloco_editorial_botao_texto">Texto do botão</label>
        <input type="text" id="bloco_editorial_botao_texto" name="bloco_editorial_botao_texto"
               value="<?= e(cfg('bloco_editorial_botao_texto', '')) ?>">
    </div>
    <div class="campo">
        <label for="bloco_editorial_botao_link">Link do botão</label>
        <input type="text" id="bloco_editorial_botao_link" name="bloco_editorial_botao_link"
               value="<?= e(cfg('bloco_editorial_botao_link', '')) ?>"
               placeholder="Ex.: /categoria/velas ou https://...">
    </div>

    <button class="btn" type="submit">Salvar</button>
</form>
<?php
view('admin_layout', ['titulo' => 'Configurações', 'conteudo' => ob_get_clean()]);
