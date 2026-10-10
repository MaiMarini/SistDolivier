<?php
/**
 * Admin: Configurações da loja (tabela settings) — quatro abas num formulário só.
 * Rota: /admin/configuracoes[?aba=loja|pagamento|entrega|textos]
 *
 * Salvar (fetch, JSON, CSRF) grava todas as abas de uma vez, numa transação, só as
 * chaves que mudaram. Valores em R$ são digitados em reais e gravados em centavos.
 * As chaves são as mesmas de sempre (o site já as lê com cfg()).
 * As regras de frete (app/lib/frete.php) e de parcelamento (parcelamento_parcelas em
 * helpers.php) não mudam: os simuladores da tela repetem essas regras em JS.
 */
exigir_admin();

const ENDERECO_PARTES = ['cep', 'rua', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

/** Limites dos textos (aumentam sozinhos se um texto já gravado for maior: nada é cortado). */
function _cfg_limites(): array
{
    $base = ['site_descricao' => 200, 'retirada_endereco' => 400, 'whatsapp_msg' => 300,
             'personalizar_msg_template' => 500, 'regras_texto' => 800, 'sobre_texto' => 2000];
    foreach ($base as $k => $n) {
        $base[$k] = max($n, mb_strlen((string) cfg($k, '')));
    }
    return $base;
}

/** "Rua X, 12 - Compl - Bairro - Cidade/UF - CEP 00000-000" (vazio se não houver rua). */
function _cfg_endereco_texto(array $s, string $prefixo, bool $com_complemento): string
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

/** CNPJ válido pelos dígitos verificadores. */
function _cfg_cnpj_valido(string $cnpj): bool
{
    $d = preg_replace('/\D+/', '', $cnpj);
    if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d)) {
        return false;
    }
    foreach ([12, 13] as $n) {
        $pesos = $n === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $soma = 0;
        for ($i = 0; $i < $n; $i++) {
            $soma += (int) $d[$i] * $pesos[$i];
        }
        $r = $soma % 11;
        if ((int) $d[$n] !== ($r < 2 ? 0 : 11 - $r)) {
            return false;
        }
    }
    return true;
}

// Tipos de cada chave do formulário.
$DINHEIRO = ['parcelamento_limite_centavos', 'parcela_minima_centavos', 'frete_base_centavos', 'frete_por_km_centavos'];
$INTEIRO  = ['parcelamento_max' => 48, 'frete_base_km' => 500, 'entrega_raio_max_km' => 500];   // => máximo
$TEXTO    = array_merge(
    ['site_descricao', 'whatsapp_numero', 'cnpj', 'instagram_usuario', 'tiktok_usuario', 'facebook_url', 'pinterest_url',
     'frete_provedor', 'loja_lat', 'loja_lng', 'retirada_endereco', 'whatsapp_msg', 'personalizar_msg_template',
     'regras_texto', 'sobre_texto', 'loja_endereco_igual'],
    array_map(fn ($p) => 'endereco_' . $p, ENDERECO_PARTES),
    array_map(fn ($p) => 'loja_' . $p, ENDERECO_PARTES)
);
const WHATSAPP_MSG_PADRAO = "Olá! Vim pelo site da D'Olivier e gostaria de falar com vocês.";

// =============================================================================
// POST (fetch/JSON): valida tudo e grava só o que mudou, numa transação
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $responder = function (int $http, array $d): void {
        http_response_code($http);
        echo json_encode($d, JSON_UNESCAPED_UNICODE);
    };
    if (!csrf_validar()) {
        $responder(403, ['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.']);
        return;
    }
    $in = fn ($k) => trim(str_replace("\r\n", "\n", (string) ($_POST[$k] ?? '')));
    $erros = [];
    $novo = [];

    // Textos (com normalizações e validações)
    $limites = _cfg_limites();
    foreach ($TEXTO as $k) {
        $v = $in($k);
        switch (true) {
            case $k === 'cnpj':
                if ($v !== '' && !_cfg_cnpj_valido($v)) { $erros[$k] = 'CNPJ inválido. Confira os números.'; }
                break;
            case $k === 'whatsapp_numero':
                if (mb_strlen($v) > 30) { $erros[$k] = 'Número longo demais.'; }
                break;
            case in_array($k, ['instagram_usuario', 'tiktok_usuario'], true):
                $v = ltrim($v, '@');
                if ($v !== '' && !preg_match('/^[A-Za-z0-9._]{1,60}$/', $v)) { $erros[$k] = 'Use só o usuário (letras, números, ponto e _).'; }
                break;
            case in_array($k, ['facebook_url', 'pinterest_url'], true):
                if ($v !== '' && (stripos($v, 'https://') !== 0 || !filter_var($v, FILTER_VALIDATE_URL) || mb_strlen($v) > 300)) {
                    $erros[$k] = 'Use o endereço completo, começando com https://';
                }
                break;
            case in_array($k, ['endereco_cep', 'loja_cep'], true):
                $v = preg_replace('/\D+/', '', $v);
                if ($v !== '' && strlen($v) !== 8) { $erros[$k] = 'CEP com 8 números.'; }
                break;
            case in_array($k, ['endereco_uf', 'loja_uf'], true):
                $v = mb_strtoupper($v);
                if ($v !== '' && !preg_match('/^[A-Z]{2}$/', $v)) { $erros[$k] = 'UF com 2 letras (ex.: RO).'; }
                break;
            case $k === 'loja_endereco_igual':
                $v = $v === '0' ? '0' : '1';
                break;
            case $k === 'frete_provedor':
                $v = $v === 'google' ? 'google' : 'off';
                break;
            case in_array($k, ['loja_lat', 'loja_lng'], true):
                $v = str_replace(',', '.', $v);
                $lim = $k === 'loja_lat' ? 90 : 180;
                if ($v !== '' && (!is_numeric($v) || abs((float) $v) > $lim)) {
                    $erros[$k] = $k === 'loja_lat' ? 'Latitude entre -90 e 90 (ex.: -12.7577).' : 'Longitude entre -180 e 180 (ex.: -60.1063).';
                }
                break;
            case isset($limites[$k]):
                if (mb_strlen($v) > $limites[$k]) { $erros[$k] = 'Use no máximo ' . $limites[$k] . ' caracteres.'; }
                break;
            default:
                if (mb_strlen($v) > 200) { $erros[$k] = 'Use no máximo 200 caracteres.'; }
        }
        $novo[$k] = $v;
    }
    // Dinheiro (R$ ≥ 0 -> centavos) e inteiros (≥ 0)
    foreach ($DINHEIRO as $k) {
        $v = $in($k);
        $ok = $v === '' || preg_match('/^(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$|^\d+\.\d{1,2}$/', $v);
        if (!$ok) { $erros[$k] = 'Valor inválido. Use, por exemplo, 9,00.'; }
        $novo[$k] = (string) ($v === '' ? 0 : reais_para_centavos($v));
    }
    foreach ($INTEIRO as $k => $max) {
        $v = $in($k);
        if (!preg_match('/^\d{1,4}$/', $v) || (int) $v > $max) {
            $erros[$k] = 'Use um número inteiro de 0 a ' . $max . '.';
        }
        $novo[$k] = (string) (int) $v;
    }
    if ($erros) {
        $responder(422, ['ok' => false, 'erros' => $erros, 'mensagem' => 'Confira os campos destacados.']);
        return;
    }

    // "Mesmo endereço da loja": a origem do frete copia o endereço comercial.
    if ($novo['loja_endereco_igual'] === '1') {
        foreach (ENDERECO_PARTES as $p) {
            $novo['loja_' . $p] = $novo['endereco_' . $p];
        }
    }
    // Textos completos (usados no frete sem lat/lng e em telas antigas): só quando há rua.
    foreach (['endereco' => ['endereco_', true], 'loja_endereco' => ['loja_', false]] as $k => [$prefixo, $compl]) {
        $t = _cfg_endereco_texto($novo, $prefixo, $compl);
        if ($t !== '') { $novo[$k] = $t; }
    }

    $atuais = db()->query('SELECT chave, valor FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $mudou = array_filter($novo, fn ($v, $k) => !array_key_exists($k, $atuais) || (string) $atuais[$k] !== (string) $v, ARRAY_FILTER_USE_BOTH);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('INSERT INTO settings (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
        foreach ($mudou as $k => $v) {
            $up->execute([$k, $v]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $responder(500, ['ok' => false, 'mensagem' => 'Não foi possível salvar. Tente de novo.']);
        return;
    }
    $responder(200, ['ok' => true, 'mudou' => array_keys($mudou), 'mensagem' => 'Configurações salvas. A loja já usa os novos valores.']);
    return;
}

// =============================================================================
// GET
// =============================================================================
$ABAS = ['loja' => 'Loja', 'pagamento' => 'Pagamento', 'entrega' => 'Entrega', 'textos' => 'Textos'];
$aba = isset($ABAS[$_GET['aba'] ?? '']) ? $_GET['aba'] : 'loja';
$limites = _cfg_limites();
$tem_chave = trim((string) env('GOOGLE_MAPS_API_KEY', '')) !== '';   // só se existe; a chave nunca vai para a página
$igual = cfg('loja_endereco_igual', '1') === '1';

$v = fn (string $k, string $padrao = '') => (string) cfg($k, $padrao);
$cep = function (string $k) use ($v): string {
    $c = $v($k);
    return strlen($c) === 8 ? substr($c, 0, 5) . '-' . substr($c, 5) : $c;
};
$reais = fn (string $k, string $padrao) => centavos_para_input((int) cfg($k, $padrao));
$erro = fn (string $k) => '<span class="pe-erro" id="cf-erro-' . $k . '" data-cf-erro="' . $k . '" role="alert" hidden></span>';

/** Campo de texto simples ou com prefixo; $o: rotulo, ajuda, ph, im, prefixo, extra, max. */
$campo = function (string $k, string $valor, array $o) use ($erro): string {
    $input = '<input id="cf-' . $k . '" name="' . $k . '" type="text" value="' . e($valor) . '" autocomplete="off"'
        . (!empty($o['ph']) ? ' placeholder="' . e($o['ph']) . '"' : '')
        . (!empty($o['im']) ? ' inputmode="' . $o['im'] . '"' : '')
        . (!empty($o['max']) ? ' maxlength="' . (int) $o['max'] . '"' : '')
        . ' aria-describedby="cf-erro-' . $k . (!empty($o['ajuda']) ? ' cf-ajuda-' . $k : '') . '">';
    return '<div class="pe-campo' . (!empty($o['classe']) ? ' ' . $o['classe'] : '') . '">'
        . '<label for="cf-' . $k . '">' . e($o['rotulo']) . '</label>'
        . (!empty($o['prefixo']) ? '<span class="pe-affix"><span aria-hidden="true">' . e($o['prefixo']) . '</span>' . $input . '</span>' : $input)
        . (!empty($o['ajuda']) ? '<span class="pe-ajuda" id="cf-ajuda-' . $k . '">' . e($o['ajuda']) . '</span>' : '')
        . ($o['extra'] ?? '') . $erro($k) . '</div>';
};
/** Textarea com contador. */
$area = function (string $k, string $rotulo, string $ajuda, int $linhas) use ($v, $limites, $erro): string {
    return '<div class="pe-campo"><label for="cf-' . $k . '">' . e($rotulo) . '</label>'
        . '<textarea id="cf-' . $k . '" name="' . $k . '" rows="' . $linhas . '" maxlength="' . $limites[$k] . '" data-cf-contar'
        . ' aria-describedby="cf-ajuda-' . $k . ' cf-erro-' . $k . '">' . e($v($k)) . '</textarea>'
        . '<span class="pe-contador ap-num" data-cf-contador="' . $k . '"></span>'
        . ($ajuda !== '' ? '<span class="pe-ajuda" id="cf-ajuda-' . $k . '">' . e($ajuda) . '</span>' : '')
        . $erro($k) . '</div>';
};
/** Endereço (Loja ou saída), com "Buscar" pelo CEP. */
$endereco = function (string $prefixo) use ($campo, $v, $cep, $erro): string {
    return '<div class="cf-endereco" data-cf-endereco="' . $prefixo . '">'
        . '<div class="cf-grid3">'
        . '<div class="pe-campo"><label for="cf-' . $prefixo . 'cep">CEP</label><span class="cf-cep">'
        . '<input id="cf-' . $prefixo . 'cep" name="' . $prefixo . 'cep" type="text" inputmode="numeric" maxlength="9" placeholder="00000-000" value="' . e($cep($prefixo . 'cep')) . '" autocomplete="off" aria-describedby="cf-ajuda-' . $prefixo . 'cep cf-erro-' . $prefixo . 'cep">'
        . '<button type="button" class="ap-btn ap-btn-linha" data-cf-buscar-cep="' . $prefixo . '">Buscar</button></span>'
        . '<span class="pe-ajuda" id="cf-ajuda-' . $prefixo . 'cep" data-cf-cep-msg="' . $prefixo . '">Preenche rua, bairro e cidade.</span>' . $erro($prefixo . 'cep') . '</div>'
        . $campo($prefixo . 'rua', $v($prefixo . 'rua'), ['rotulo' => 'Rua / avenida', 'classe' => 'cf-span2'])
        . '</div><div class="cf-grid3">'
        . $campo($prefixo . 'numero', $v($prefixo . 'numero'), ['rotulo' => 'Número', 'im' => 'numeric'])
        . $campo($prefixo . 'complemento', $v($prefixo . 'complemento'), ['rotulo' => 'Complemento', 'ph' => 'Opcional'])
        . $campo($prefixo . 'bairro', $v($prefixo . 'bairro'), ['rotulo' => 'Bairro'])
        . '</div><div class="cf-grid3">'
        . $campo($prefixo . 'cidade', $v($prefixo . 'cidade'), ['rotulo' => 'Cidade'])
        . $campo($prefixo . 'uf', $v($prefixo . 'uf'), ['rotulo' => 'UF', 'max' => 2, 'ph' => 'RO', 'classe' => 'cf-uf'])
        . '</div></div>';
};
$chave = fn (string $k, bool $on, string $titulo, string $ajuda) =>
    '<input type="hidden" name="' . $k . '" value="' . ($on ? 1 : 0) . '" data-cf-sw-campo="' . $k . '">'
    . '<button type="button" class="pe-tg" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '" data-cf-sw="' . $k . '" aria-describedby="cf-sw-' . $k . '">'
    . '<span class="pe-tg-txt"><b>' . e($titulo) . '</b><span id="cf-sw-' . $k . '">' . e($ajuda) . '</span></span><span class="pe-tg-trilho" aria-hidden="true"></span></button>';

$dados = ['temChave' => $tem_chave, 'csrf' => csrf_token(), 'url' => url('admin/configuracoes')];

ob_start();
?>
<form class="cf" method="post" action="<?= e(url('admin/configuracoes')) ?>" novalidate data-cf data-aba="<?= e($aba) ?>">
    <?= csrf_input() ?>
    <nav class="cf-abas" role="tablist" aria-label="Seções">
        <?php foreach ($ABAS as $k => $rot): ?>
            <button type="button" class="cf-aba" role="tab" id="cf-tab-<?= $k ?>" aria-controls="cf-painel-<?= $k ?>"
                    aria-selected="<?= $aba === $k ? 'true' : 'false' ?>" data-cf-aba="<?= $k ?>"><?= e($rot) ?><i class="cf-bolinha" aria-hidden="true" hidden></i><span class="ap-sr" data-cf-aba-sr></span></button>
        <?php endforeach; ?>
    </nav>

    <!-- LOJA -->
    <section class="pe-card cf-painel" role="tabpanel" id="cf-painel-loja" aria-labelledby="cf-tab-loja" data-cf-painel="loja" <?= $aba === 'loja' ? '' : 'hidden' ?>>
        <h2>Loja e contato</h2>
        <p class="cf-onde">O WhatsApp e as redes sociais aparecem no rodapé; a descrição, na página inicial.</p>
        <?= $campo('site_descricao', $v('site_descricao'), ['rotulo' => 'Descrição curta da loja', 'max' => $limites['site_descricao'],
            'ajuda' => 'Uma frase. Aparece na página inicial, abaixo do nome da loja.']) ?>
        <div class="pe-grid2">
            <?= $campo('whatsapp_numero', $v('whatsapp_numero'), ['rotulo' => 'Telefone / WhatsApp', 'im' => 'tel', 'ph' => '+55 69 99999-9999',
                'extra' => '<a class="pe-link cf-testar" href="#" target="_blank" rel="noopener" data-cf-testar>Testar no WhatsApp ↗</a>']) ?>
            <?= $campo('cnpj', $v('cnpj'), ['rotulo' => 'CNPJ', 'im' => 'numeric', 'ph' => '00.000.000/0000-00',
                'extra' => '<span class="cf-cnpj" data-cf-cnpj></span>']) ?>
        </div>
        <div class="cf-avisos" data-cf-avisos="loja"></div>

        <h3>Redes sociais</h3>
        <p class="pe-ajuda cf-sub">Deixe vazio o que não usa: o ícone some do site.</p>
        <div class="pe-grid2">
            <?= $campo('instagram_usuario', $v('instagram_usuario'), ['rotulo' => 'Instagram', 'prefixo' => 'instagram.com/', 'ph' => 'usuario', 'classe' => 'cf-sem-arroba']) ?>
            <?= $campo('tiktok_usuario', $v('tiktok_usuario'), ['rotulo' => 'TikTok', 'prefixo' => 'tiktok.com/@', 'ph' => 'usuario', 'classe' => 'cf-sem-arroba']) ?>
            <?= $campo('facebook_url', $v('facebook_url'), ['rotulo' => 'Facebook', 'ph' => 'https://facebook.com/...', 'im' => 'url']) ?>
            <?= $campo('pinterest_url', $v('pinterest_url'), ['rotulo' => 'Pinterest', 'ph' => 'https://br.pinterest.com/...', 'im' => 'url']) ?>
        </div>

        <h3>Endereço</h3>
        <?php if ($v('endereco_rua') === '' && $v('endereco') !== ''): ?>
            <p class="pe-ajuda cf-sub">Endereço salvo antes: <strong><?= e($v('endereco')) ?></strong>. Preencha os campos e salve.</p>
        <?php endif; ?>
        <?= $endereco('endereco_') ?>
    </section>

    <!-- PAGAMENTO -->
    <section class="pe-card cf-painel" role="tabpanel" id="cf-painel-pagamento" aria-labelledby="cf-tab-pagamento" data-cf-painel="pagamento" <?= $aba === 'pagamento' ? '' : 'hidden' ?>>
        <h2>Parcelamento</h2>
        <p class="cf-onde">Regras de parcelas no cartão, no checkout do Mercado Pago.</p>
        <div class="cf-grid3">
            <?= $campo('parcelamento_limite_centavos', $reais('parcelamento_limite_centavos', '0'), ['rotulo' => 'Parcela a partir de', 'prefixo' => 'R$', 'im' => 'decimal', 'ajuda' => 'Abaixo disso, só à vista.']) ?>
            <?= $campo('parcela_minima_centavos', $reais('parcela_minima_centavos', '4000'), ['rotulo' => 'Parcela mínima', 'prefixo' => 'R$', 'im' => 'decimal', 'ajuda' => 'Nenhuma parcela fica menor que isso.']) ?>
            <?= $campo('parcelamento_max', (string) (int) cfg('parcelamento_max', '3'), ['rotulo' => 'Máximo de parcelas', 'prefixo' => 'até', 'im' => 'numeric', 'ajuda' => '0 (ou 1) = sem limite.']) ?>
        </div>
        <div class="cf-sim" data-cf-sim="parcelas">
            <b>Simulador</b>
            <div class="cf-sim-linha"><span>Pedido de <b class="ap-num" data-cf-sim-valor></b></span><span class="cf-sim-grande" data-cf-sim-res></span></div>
            <input type="range" min="30" max="500" step="10" value="200" aria-label="Valor do pedido" data-cf-sim-range>
            <div class="cf-sim-ex" data-cf-sim-ex></div>
        </div>
    </section>

    <!-- ENTREGA -->
    <section class="pe-card cf-painel" role="tabpanel" id="cf-painel-entrega" aria-labelledby="cf-tab-entrega" data-cf-painel="entrega" <?= $aba === 'entrega' ? '' : 'hidden' ?>>
        <h2>Entrega e retirada</h2>
        <p class="cf-onde">Frete do motoboy pela distância da loja até o cliente. Retirada é sempre grátis.</p>
        <p class="cf-status" data-cf-status role="status"></p>
        <div class="pe-grid2">
            <?= $campo('frete_base_km', (string) (int) cfg('frete_base_km', '5'), ['rotulo' => 'Valor fixo até', 'prefixo' => 'km', 'im' => 'numeric']) ?>
            <?= $campo('frete_base_centavos', $reais('frete_base_centavos', '900'), ['rotulo' => 'Valor fixo', 'prefixo' => 'R$', 'im' => 'decimal']) ?>
            <?= $campo('frete_por_km_centavos', $reais('frete_por_km_centavos', '100'), ['rotulo' => 'Cada km a mais', 'prefixo' => 'R$', 'im' => 'decimal',
                'ajuda' => 'Arredondado para cima: com valor fixo até 5 km, 6,2 km conta como 2 km a mais.']) ?>
            <?= $campo('entrega_raio_max_km', (string) (int) cfg('entrega_raio_max_km', '15'), ['rotulo' => 'Entrega até', 'prefixo' => 'km', 'im' => 'numeric',
                'ajuda' => 'Mais longe que isso, só retirada. 0 = sem limite.']) ?>
        </div>
        <div class="cf-sim" data-cf-sim="frete">
            <b>Simulador</b>
            <div class="cf-sim-linha"><span>Cliente a <b class="ap-num" data-cf-sim-valor></b></span><span class="cf-sim-grande" data-cf-sim-res></span></div>
            <input type="range" min="0" max="25" step="0.5" value="8" aria-label="Distância até o cliente" data-cf-sim-range>
            <div class="cf-sim-ex" data-cf-sim-ex></div>
        </div>

        <h3>Endereço de saída e retirada</h3>
        <?= $chave('loja_endereco_igual', $igual, 'Mesmo endereço da loja', 'Desligue se o motoboy sai ou a retirada é em outro lugar.') ?>
        <p class="cf-resumo" data-cf-resumo <?= $igual ? '' : 'hidden' ?>></p>
        <div data-cf-saida <?= $igual ? 'hidden' : '' ?>><?= $endereco('loja_') ?></div>
        <?= $area('retirada_endereco', 'Instruções de retirada', 'Aparecem para o cliente no pedido (em “Meus pedidos”) quando ele escolhe retirar. Ex.: horário, portão, quem procurar.', 3) ?>
        <div class="cf-avisos" data-cf-avisos="entrega"></div>

        <details class="pe-avancado">
            <summary>Avançado</summary>
            <div class="cf-avancado">
                <div class="pe-grid2">
                    <?= $campo('loja_lat', $v('loja_lat'), ['rotulo' => 'Latitude', 'im' => 'decimal', 'ph' => '-12.7577']) ?>
                    <?= $campo('loja_lng', $v('loja_lng'), ['rotulo' => 'Longitude', 'im' => 'decimal', 'ph' => '-60.1063']) ?>
                </div>
                <span class="pe-ajuda">É o ponto exato de onde a distância é medida. Para trocar: no Google Maps, clique com o botão direito na porta da loja e copie os números. Sem eles, a distância parte do endereço de saída.</span>
                <a class="pe-link" href="#" target="_blank" rel="noopener" data-cf-mapa>Conferir o ponto no Google Maps ↗</a>
                <div class="pe-campo">
                    <label for="cf-frete_provedor">Cálculo da distância</label>
                    <select id="cf-frete_provedor" name="frete_provedor">
                        <option value="google" <?= cfg('frete_provedor', 'off') === 'google' ? 'selected' : '' ?>>Google Maps (pelas ruas)</option>
                        <option value="off" <?= cfg('frete_provedor', 'off') !== 'google' ? 'selected' : '' ?>>Desligado (só retirada)</option>
                    </select>
                </div>
            </div>
        </details>
    </section>

    <!-- TEXTOS -->
    <section class="pe-card cf-painel" role="tabpanel" id="cf-painel-textos" aria-labelledby="cf-tab-textos" data-cf-painel="textos" <?= $aba === 'textos' ? '' : 'hidden' ?>>
        <h2>Textos e mensagens</h2>
        <p class="cf-onde">Mensagens prontas do WhatsApp e textos fixos das páginas.</p>

        <h3>Botão do WhatsApp no rodapé</h3>
        <div class="cf-msg">
            <div class="pe-campo">
                <label class="ap-sr" for="cf-whatsapp_msg">Mensagem do botão do rodapé</label>
                <textarea id="cf-whatsapp_msg" name="whatsapp_msg" rows="3" maxlength="<?= $limites['whatsapp_msg'] ?>" aria-describedby="cf-ajuda-whatsapp_msg cf-erro-whatsapp_msg"><?= e($v('whatsapp_msg', WHATSAPP_MSG_PADRAO)) ?></textarea>
                <span class="pe-ajuda" id="cf-ajuda-whatsapp_msg">Mensagem geral de contato: o botão do rodapé aparece em todas as páginas.</span>
                <?= $erro('whatsapp_msg') ?>
            </div>
            <div class="cf-wa"><div class="cf-wa-balao" data-cf-wa="whatsapp_msg"></div><small>Como chega no seu WhatsApp</small></div>
        </div>

        <h3>Botão “Personalizar”</h3>
        <div class="cf-msg">
            <div class="pe-campo">
                <label class="ap-sr" for="cf-personalizar_msg_template">Mensagem do botão Personalizar</label>
                <textarea id="cf-personalizar_msg_template" name="personalizar_msg_template" rows="4" maxlength="<?= $limites['personalizar_msg_template'] ?>" aria-describedby="cf-ajuda-personalizar_msg_template cf-erro-personalizar_msg_template"><?= e($v('personalizar_msg_template')) ?></textarea>
                <div class="cf-tokens">Inserir:
                    <button type="button" class="cf-token" data-cf-token="{produto}">{produto}</button>
                    <button type="button" class="cf-token" data-cf-token="{link}">{link}</button>
                </div>
                <span class="pe-ajuda" id="cf-ajuda-personalizar_msg_template">{produto} e {link} são trocados sozinhos e o cliente não consegue mudá-los.</span>
                <?= $erro('personalizar_msg_template') ?>
            </div>
            <div class="cf-wa"><div class="cf-wa-balao" data-cf-wa="personalizar_msg_template"></div><small>Como chega no seu WhatsApp</small></div>
        </div>

        <?= $area('regras_texto', 'Regras gerais', 'Aparecem em “Regras e prazos” na página de cada produto, depois das regras do próprio produto, e na página Regras.', 4) ?>
        <?= $area('sobre_texto', 'Sobre nós', 'Texto da página Sobre da loja.', 7) ?>
        <div class="cf-avisos" data-cf-avisos="textos"></div>
    </section>

    <div class="pe-barra" role="region" aria-label="Salvar configurações">
        <span class="pe-estado" data-cf-estado><i aria-hidden="true"></i><span data-cf-estado-txt>Tudo salvo</span></span>
        <button type="button" class="ap-btn ap-btn-linha" data-cf-descartar hidden>Descartar</button>
        <button type="submit" class="ap-btn ap-btn-primario" data-cf-salvar disabled>Salvar alterações</button>
    </div>
    <div class="pe-toast" data-cf-toast role="status" aria-live="polite" hidden></div>
</form>
<script type="application/json" id="cf-dados"><?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(asset('assets/js/admin-configuracoes.js')) ?>"></script>
<?php
view('admin_layout', [
    'titulo'       => 'Configurações',
    'subtitulo'    => 'Dados da loja, pagamento, entrega e textos do site.',
    'conteudo'     => ob_get_clean(),
    'layout_largo' => true,
]);
