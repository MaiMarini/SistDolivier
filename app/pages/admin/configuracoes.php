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
                    'email_remetente', 'email_loja',
                    'instagram_usuario', 'tiktok_usuario', 'facebook_url', 'pinterest_url'],
    ],
    'pagamento' => [
        'texto'    => ['loja_endereco', 'loja_lat', 'loja_lng', 'retirada_endereco'],
        'dinheiro' => ['parcelamento_limite_centavos', 'parcela_minima_centavos',
                       'frete_base_centavos', 'frete_por_km_centavos'],
        'inteiro'  => ['parcelamento_max', 'frete_base_km', 'entrega_raio_max_km'],
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
        <label for="email_remetente">E-mail remetente (avisos automáticos)</label>
        <input type="email" id="email_remetente" name="email_remetente"
               value="<?= e(cfg('email_remetente', '')) ?>" placeholder="no-reply@seudominio.com.br">
        <small>Aparece como "De:" nos e-mails de pedido. Use um e-mail do domínio da loja.</small>
    </div>
    <div class="campo">
        <label for="email_loja">E-mail da loja (recebe aviso de novo pedido)</label>
        <input type="email" id="email_loja" name="email_loja"
               value="<?= e(cfg('email_loja', '')) ?>" placeholder="pedidos@seudominio.com.br">
        <small>Deixe vazio para não notificar a loja a cada novo pedido.</small>
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

    <button class="btn" type="submit">Salvar</button>
</form>

<!-- Aba 2: Entrega e pagamento -->
<form method="post" action="<?= e(url('admin/configuracoes')) ?>"
      class="formulario painel<?= $aba === 'pagamento' ? ' ativo' : '' ?>" data-painel="pagamento"
      style="max-width:640px;">
    <?= csrf_input() ?>
    <input type="hidden" name="aba" value="pagamento">

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

    <h2 class="mt-1">Entrega (frete por distância)</h2>
    <p><small>O frete do motoboy é calculado pela distância da loja até o cliente:
       um valor fixo nos primeiros km e uma taxa por km extra. A retirada é sempre grátis.</small></p>

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
    <div class="campo">
        <label for="loja_endereco">Endereço da loja (origem do frete)</label>
        <input type="text" id="loja_endereco" name="loja_endereco"
               value="<?= e(cfg('loja_endereco', '')) ?>" placeholder="Rua, número, bairro, cidade">
    </div>
    <div class="campo">
        <label for="loja_lat">Latitude da loja (opcional)</label>
        <input type="text" id="loja_lat" name="loja_lat"
               value="<?= e(cfg('loja_lat', '')) ?>" placeholder="Ex.: -23.5505">
    </div>
    <div class="campo">
        <label for="loja_lng">Longitude da loja (opcional)</label>
        <input type="text" id="loja_lng" name="loja_lng"
               value="<?= e(cfg('loja_lng', '')) ?>" placeholder="Ex.: -46.6333">
        <small>Latitude/longitude ajudam no cálculo preciso da distância (preencha quando definirmos o mapa).</small>
    </div>
    <div class="campo">
        <label for="retirada_endereco">Endereço / instruções de retirada</label>
        <textarea id="retirada_endereco" name="retirada_endereco" rows="2"><?= e(cfg('retirada_endereco', '')) ?></textarea>
    </div>

    <button class="btn" type="submit">Salvar</button>
</form>

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
