<?php
/**
 * Admin: configurações da loja (settings) em TRÊS ABAS. Rota: /admin/configuracoes
 *
 * As cores e o nome da loja NÃO são editáveis aqui (cores são fixas no theme.css).
 * Cada chave é gravada com INSERT ... ON DUPLICATE KEY UPDATE. Valores monetários
 * são digitados em reais e gravados em centavos.
 */
exigir_admin();

// Partes de um endereço separado. Comercial grava "endereco_<parte>"; a loja
// (origem do frete) grava "loja_<parte>". Os textos completos "endereco" e
// "loja_endereco" são montados a partir delas ao salvar.
const ENDERECO_PARTES = ['cep', 'rua', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

function _endereco_chaves(string $prefixo): array
{
    return array_map(fn ($p) => $prefixo . $p, ENDERECO_PARTES);
}

/** "Rua X, 12 - Compl - Bairro - Cidade/UF - CEP 00000-000" (vazio se não houver rua). */
function _endereco_texto(array $s, string $prefixo, bool $com_complemento): string
{
    $g = fn ($p) => trim((string) ($s[$prefixo . $p] ?? ''));
    if ($g('rua') === '') {
        return '';
    }
    $cep = $g('cep');
    return $g('rua')
        . ($g('numero') !== '' ? ', ' . $g('numero') : '')
        . ($com_complemento && $g('complemento') !== '' ? ' - ' . $g('complemento') : '')
        . ($g('bairro') !== '' ? ' - ' . $g('bairro') : '')
        . ($g('cidade') !== '' ? ' - ' . $g('cidade') . ($g('uf') !== '' ? '/' . $g('uf') : '') : '')
        . (strlen($cep) === 8 ? ' - CEP ' . substr($cep, 0, 5) . '-' . substr($cep, 5) : '');
}

/** Campos do endereço separado (CEP preenche o resto via ViaCEP, no app.js). */
function _endereco_campos(string $prefixo): void
{
    $v = fn ($p) => e((string) cfg($prefixo . $p, ''));
    $cep = (string) cfg($prefixo . 'cep', '');
    $cep = strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : $cep;
    ?>
    <div class="endereco-grid" data-endereco="<?= e($prefixo) ?>">
        <div class="campo campo-largo">
            <label for="<?= $prefixo ?>cep">CEP</label>
            <input type="text" id="<?= $prefixo ?>cep" name="<?= $prefixo ?>cep" value="<?= e($cep) ?>"
                   inputmode="numeric" maxlength="9" placeholder="00000-000" style="max-width:12rem;" data-cep>
            <small>Ao sair do campo, rua, bairro, cidade e UF são preenchidos automaticamente.</small>
        </div>
        <div class="campo campo-largo">
            <label for="<?= $prefixo ?>rua">Rua / avenida</label>
            <input type="text" id="<?= $prefixo ?>rua" name="<?= $prefixo ?>rua" value="<?= $v('rua') ?>" data-cep-rua>
        </div>
        <div class="campo campo-largo">
            <label for="<?= $prefixo ?>bairro">Bairro</label>
            <input type="text" id="<?= $prefixo ?>bairro" name="<?= $prefixo ?>bairro" value="<?= $v('bairro') ?>" data-cep-bairro>
        </div>
        <div class="campo">
            <label for="<?= $prefixo ?>numero">Número</label>
            <input type="text" id="<?= $prefixo ?>numero" name="<?= $prefixo ?>numero" value="<?= $v('numero') ?>">
        </div>
        <div class="campo">
            <label for="<?= $prefixo ?>complemento">Complemento</label>
            <input type="text" id="<?= $prefixo ?>complemento" name="<?= $prefixo ?>complemento" value="<?= $v('complemento') ?>">
        </div>
        <div class="campo">
            <label for="<?= $prefixo ?>uf">UF</label>
            <input type="text" id="<?= $prefixo ?>uf" name="<?= $prefixo ?>uf" value="<?= $v('uf') ?>"
                   maxlength="2" placeholder="SP" style="text-transform:uppercase;" data-cep-uf>
        </div>
        <div class="campo">
            <label for="<?= $prefixo ?>cidade">Cidade</label>
            <input type="text" id="<?= $prefixo ?>cidade" name="<?= $prefixo ?>cidade" value="<?= $v('cidade') ?>" data-cep-cidade>
        </div>
    </div>
    <?php
}

// Chaves por aba e por tipo de tratamento.
$abas_campos = [
    'comercial' => [
        'texto' => array_merge(['site_descricao', 'whatsapp_numero', 'cnpj',
                    'instagram_usuario', 'tiktok_usuario', 'facebook_url', 'pinterest_url'],
                    _endereco_chaves('endereco_')),
    ],
    'pagamento' => [
        'texto'    => array_merge(['frete_provedor', 'loja_lat', 'loja_lng', 'retirada_endereco'],
                    _endereco_chaves('loja_')),
        'dinheiro' => ['parcelamento_limite_centavos', 'parcela_minima_centavos',
                       'frete_base_centavos', 'frete_por_km_centavos'],
        'inteiro'  => ['parcelamento_max', 'frete_base_km', 'entrega_raio_max_km'],
        'checkbox' => ['loja_endereco_igual'],
    ],
    'config' => [
        'texto' => ['regras_texto', 'whatsapp_msg', 'personalizar_msg_template', 'sobre_texto'],
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
        // Garante que a mensagem de personalização nunca perca {produto} e {link}.
        if ($k === 'personalizar_msg_template') {
            if (strpos($v, '{produto}') === false) {
                $v = trim($v . ' {produto}');
            }
            if (strpos($v, '{link}') === false) {
                $v = trim($v . ' {link}');
            }
        }
        if ($k === 'frete_provedor' && !in_array($v, ['off', 'google'], true)) {
            $v = 'off';
        }
        if (in_array($k, ['loja_lat', 'loja_lng'], true)) {
            $v = str_replace(',', '.', $v); // aceita vírgula digitada
        }
        if (substr($k, -4) === '_cep') {
            $v = substr(preg_replace('/\D+/', '', $v), 0, 8);
        }
        if (substr($k, -3) === '_uf') {
            $v = strtoupper($v);
            $v = preg_match('/^[A-Z]{2}$/', $v) ? $v : '';
        }
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
    foreach (($grupo['checkbox'] ?? []) as $k) {
        $v = isset($_POST[$k]) ? '1' : '0';
        $stmt->execute([$k, $v, $v]);
    }

    // Endereço da loja "igual ao comercial": copia as partes (vale ao salvar
    // qualquer uma das abas, para manter as duas em sincronia). Depois remonta
    // os textos completos — só quando há rua, para não apagar um texto antigo.
    $s = db()->query('SELECT chave, valor FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($s['loja_endereco_igual'] ?? '1') === '1') {
        foreach (ENDERECO_PARTES as $p) {
            $v = (string) ($s['endereco_' . $p] ?? '');
            $stmt->execute(['loja_' . $p, $v, $v]);
            $s['loja_' . $p] = $v;
        }
    }
    foreach (['endereco' => ['endereco_', true], 'loja_endereco' => ['loja_', false]] as $k => [$prefixo, $compl]) {
        $v = _endereco_texto($s, $prefixo, $compl);
        if ($v !== '') {
            $stmt->execute([$k, $v, $v]);
        }
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
        <label for="cnpj">CNPJ</label>
        <input type="text" id="cnpj" name="cnpj" value="<?= e(cfg('cnpj', '')) ?>">
    </div>
    <div class="campo">
        <label for="instagram_usuario">Instagram (usuário, sem @)</label>
        <input type="text" id="instagram_usuario" name="instagram_usuario"
               value="<?= e(cfg('instagram_usuario', '')) ?>" placeholder="ex.: minhaloja">
    </div>
    <div class="campo">
        <label for="tiktok_usuario">TikTok (usuário, sem @)</label>
        <input type="text" id="tiktok_usuario" name="tiktok_usuario"
               value="<?= e(cfg('tiktok_usuario', '')) ?>" placeholder="ex.: minhaloja">
    </div>
    <div class="campo">
        <label for="facebook_url">Facebook (URL completa)</label>
        <input type="text" id="facebook_url" name="facebook_url"
               value="<?= e(cfg('facebook_url', '')) ?>" placeholder="https://facebook.com/...">
    </div>
    <div class="campo">
        <label for="pinterest_url">Pinterest (URL completa)</label>
        <input type="text" id="pinterest_url" name="pinterest_url"
               value="<?= e(cfg('pinterest_url', '')) ?>" placeholder="https://br.pinterest.com/seu-perfil">
    </div>

    <h2 class="mt-1">Endereço</h2>
    <?php if (cfg('endereco_rua', '') === '' && cfg('endereco', '') !== ''): ?>
        <p><small>Endereço salvo anteriormente: <strong><?= e(cfg('endereco', '')) ?></strong>.
           Preencha os campos abaixo e salve.</small></p>
    <?php endif; ?>
    <?php _endereco_campos('endereco_'); ?>

    <button class="btn" type="submit">Salvar</button>
</form>

<!-- Aba 2: Entrega e pagamento -->
<form method="post" action="<?= e(url('admin/configuracoes')) ?>"
      class="formulario painel<?= $aba === 'pagamento' ? ' ativo' : '' ?>" data-painel="pagamento"
      style="max-width:640px;">
    <?= csrf_input() ?>
    <input type="hidden" name="aba" value="pagamento">

    <div class="card-bloco">
    <h2>Pagamento</h2>
    <div class="campo">
        <label for="parcelamento_limite_centavos">Valor mínimo do pedido para parcelar (R$)</label>
        <input type="text" id="parcelamento_limite_centavos" name="parcelamento_limite_centavos"
               inputmode="decimal"
               value="<?= e(centavos_para_input((int) cfg('parcelamento_limite_centavos', '0'))) ?>"
               placeholder="Ex.: 120,00">
        <small>Abaixo deste total, a compra é só à vista.</small>
    </div>
    <div class="campo">
        <label for="parcela_minima_centavos">Valor mínimo de cada parcela (R$)</label>
        <input type="text" id="parcela_minima_centavos" name="parcela_minima_centavos"
               inputmode="decimal"
               value="<?= e(centavos_para_input((int) cfg('parcela_minima_centavos', '4000'))) ?>"
               placeholder="Ex.: 40,00">
        <small>O nº de parcelas é ajustado para que nenhuma fique abaixo deste valor.</small>
    </div>
    <div class="campo">
        <label for="parcelamento_max">Máximo de parcelas (0 = sem limite)</label>
        <input type="number" id="parcelamento_max" name="parcelamento_max" min="0"
               value="<?= (int) cfg('parcelamento_max', '3') ?>">
        <small>Teto de parcelas (padrão 3). Use 0 para deixar só a regra da parcela mínima.</small>
    </div>
    </div>

    <div class="card-bloco">
    <h2>Entrega</h2>
    <p><small>O frete do motoboy é calculado pela distância da loja até o cliente:
       um valor fixo nos primeiros km e uma taxa por km extra. A retirada é sempre grátis.</small></p>

    <?php $provedor = cfg('frete_provedor', 'off'); ?>
    <div class="campo">
        <label for="frete_provedor">Cálculo da distância</label>
        <select id="frete_provedor" name="frete_provedor">
            <option value="off" <?= $provedor !== 'google' ? 'selected' : '' ?>>Desligado (só retirada)</option>
            <option value="google" <?= $provedor === 'google' ? 'selected' : '' ?>>Google Maps</option>
        </select>
        <small>Chave do Google no .env (GOOGLE_MAPS_API_KEY):
            <?= env('GOOGLE_MAPS_API_KEY', '') !== '' ? 'configurada.' : '<strong>não encontrada</strong> — o frete por motoboy não vai calcular.' ?></small>
    </div>
    <div class="campo">
        <label for="frete_base_km">Primeiros km (com valor fixo)</label>
        <input type="number" id="frete_base_km" name="frete_base_km" min="0"
               value="<?= (int) cfg('frete_base_km', '5') ?>">
        <small>Até esta distância, cobra o valor fixo abaixo.</small>
    </div>
    <div class="campo">
        <label for="frete_base_centavos">Valor fixo dos primeiros km (R$)</label>
        <input type="text" id="frete_base_centavos" name="frete_base_centavos" inputmode="decimal"
               value="<?= e(centavos_para_input((int) cfg('frete_base_centavos', '900'))) ?>" placeholder="Ex.: 9,00">
    </div>
    <div class="campo">
        <label for="frete_por_km_centavos">Valor por km extra (R$)</label>
        <input type="text" id="frete_por_km_centavos" name="frete_por_km_centavos" inputmode="decimal"
               value="<?= e(centavos_para_input((int) cfg('frete_por_km_centavos', '100'))) ?>" placeholder="Ex.: 1,00">
        <small>Cada km acima do limite (arredondado para cima) soma este valor.</small>
    </div>
    <div class="campo">
        <label for="entrega_raio_max_km">Raio máximo de entrega (km)</label>
        <input type="number" id="entrega_raio_max_km" name="entrega_raio_max_km" min="0"
               value="<?= (int) cfg('entrega_raio_max_km', '15') ?>">
        <small>Acima desta distância, só retirada. Use 0 para não limitar.</small>
    </div>
    <?php
    $igual = cfg('loja_endereco_igual', '1') === '1';
    $comercial = [];
    foreach (ENDERECO_PARTES as $p) {
        $comercial[$p] = (string) cfg('endereco_' . $p, '');
    }
    if (strlen($comercial['cep']) === 8) {
        $comercial['cep'] = substr($comercial['cep'], 0, 5) . '-' . substr($comercial['cep'], 5);
    }
    ?>
    <h3 class="mt-1">Endereço da loja (origem do frete)</h3>
    <div class="campo campo-inline">
        <input type="checkbox" id="loja_endereco_igual" name="loja_endereco_igual" value="1"
               <?= $igual ? 'checked' : '' ?> data-endereco-igual='<?= e(json_encode($comercial)) ?>'>
        <label for="loja_endereco_igual">Usar o mesmo endereço das Informações comerciais</label>
    </div>
    <?php if ($comercial['rua'] === ''): ?>
        <p data-endereco-igual-aviso <?= $igual ? '' : 'hidden' ?>><small>O endereço das Informações comerciais
           ainda não foi preenchido. Preencha lá ou desmarque a opção para digitar aqui.</small></p>
    <?php endif; ?>
    <?php if (cfg('loja_rua', '') === '' && cfg('loja_endereco', '') !== ''): ?>
        <p><small>Endereço salvo anteriormente: <strong><?= e(cfg('loja_endereco', '')) ?></strong>.</small></p>
    <?php endif; ?>
    <?php _endereco_campos('loja_'); ?>
    <div class="campo">
        <label for="loja_lat">Latitude da loja (opcional)</label>
        <input type="text" id="loja_lat" name="loja_lat"
               value="<?= e(cfg('loja_lat', '')) ?>" placeholder="Ex.: -23.5505">
    </div>
    <div class="campo">
        <label for="loja_lng">Longitude da loja (opcional)</label>
        <input type="text" id="loja_lng" name="loja_lng"
               value="<?= e(cfg('loja_lng', '')) ?>" placeholder="Ex.: -46.6333">
        <small>No Google Maps, clique com o botão direito na porta da loja e copie os números.
            Sem latitude/longitude, a distância parte do endereço acima (menos preciso).</small>
    </div>
    <div class="campo">
        <label for="retirada_endereco">Instruções de retirada</label>
        <textarea id="retirada_endereco" name="retirada_endereco" rows="2"><?= e(cfg('retirada_endereco', '')) ?></textarea>
    </div>
    </div>

    <button class="btn" type="submit">Salvar</button>
</form>

<script>
(function () {
    // "Usar o mesmo endereço": copia o endereço comercial (salvo) para os campos
    // da loja e os trava; desmarcado, libera para digitar.
    var chk = document.querySelector('[data-endereco-igual]');
    var grid = document.querySelector('[data-endereco="loja_"]');
    if (!chk || !grid) { return; }
    var comercial = JSON.parse(chk.getAttribute('data-endereco-igual') || '{}');
    var aviso = document.querySelector('[data-endereco-igual-aviso]');
    function aplicar() {
        Object.keys(comercial).forEach(function (p) {
            var el = grid.querySelector('[name="loja_' + p + '"]');
            if (!el) { return; }
            if (chk.checked) { el.value = comercial[p]; }
            el.readOnly = chk.checked;
        });
        if (aviso) { aviso.hidden = !chk.checked; }
    }
    chk.addEventListener('change', aplicar);
    aplicar();
})();
</script>

<!-- Aba 3: Configurações (textos + bloco editorial) -->
<form method="post" action="<?= e(url('admin/configuracoes')) ?>"
      class="formulario painel<?= $aba === 'config' ? ' ativo' : '' ?>" data-painel="config"
      style="max-width:640px;">
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
        <label for="personalizar_msg_template">Mensagem de personalização (WhatsApp)</label>
        <small>Escreva a mensagem que o cliente enviará. Use <code>{produto}</code> onde deve
               aparecer o nome do produto e <code>{link}</code> onde deve aparecer o link — eles
               serão preenchidos automaticamente e não podem ser alterados pelo cliente.</small>
        <textarea id="personalizar_msg_template" name="personalizar_msg_template" rows="4"><?= e(cfg('personalizar_msg_template', '')) ?></textarea>
    </div>
    <div class="campo">
        <label for="sobre_texto">Sobre nós</label>
        <textarea id="sobre_texto" name="sobre_texto" rows="5"><?= e(cfg('sobre_texto', '')) ?></textarea>
    </div>

    <button class="btn" type="submit">Salvar</button>
</form>
<?php
view('admin_layout', ['titulo' => 'Configurações', 'conteudo' => ob_get_clean()]);
