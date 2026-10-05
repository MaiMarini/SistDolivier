<?php
/**
 * Estimativa de frete (JSON): POST /frete — usada na página do produto.
 * Logado com endereço no cadastro ("cadastro=1") -> usa esse endereço;
 * senão, o CEP informado (+ rua/bairro/cidade/UF vindos do ViaCEP, opcionais).
 * É só uma ESTIMATIVA: o valor final é recalculado no checkout.
 *
 * Proteção de custo: cada sessão guarda as respostas por destino (repetir não
 * consulta de novo) e pode fazer no máximo FRETE_LIMITE_HORA destinos novos/hora.
 */
const FRETE_LIMITE_HORA = 15;

function _frete_json(array $r, int $http = 200): void
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_validar()) {
    _frete_json(['ok' => false, 'mensagem' => 'Sua sessão expirou. Recarregue a página.'], 400);
}
if (cfg('frete_provedor', 'off') === 'off') {
    _frete_json(['ok' => false, 'mensagem' => 'Cálculo de frete indisponível no momento.']);
}

// --- Destino ----------------------------------------------------------------
$destino = '';
$chave   = null;
$rotulo  = '';

$usuario = usuario_atual();
if (!empty($_POST['cadastro']) && $usuario !== null) {
    $st = db()->prepare('SELECT endereco FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int) $usuario['id']]);
    $end = trim((string) $st->fetchColumn());
    if ($end !== '') {
        $destino = $end;
        $rotulo  = $end;
        // Mesma chave de cache do checkout (cep-numero), quando dá para extrair.
        if (preg_match('/CEP\s*(\d{5})-?(\d{3})/i', $end, $mc)
            && preg_match('/^[^,]+,\s*(.+?)\s*(?: - |$)/', $end, $mn)) {
            $chave = $mc[1] . $mc[2] . '-' . preg_replace('/\s+/', '', mb_strtolower($mn[1]));
        }
    }
}

if ($destino === '') {
    $cep = preg_replace('/\D+/', '', $_POST['cep'] ?? '');
    if (strlen($cep) !== 8) {
        _frete_json(['ok' => false, 'mensagem' => 'Informe um CEP válido (8 números).']);
    }
    $campo = fn ($k) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 120);
    $rua    = $campo('rua');
    $bairro = $campo('bairro');
    $cidade = $campo('cidade');
    $uf     = strtoupper($campo('uf'));
    $uf     = preg_match('/^[A-Z]{2}$/', $uf) ? $uf : '';

    $cep_fmt = substr($cep, 0, 5) . '-' . substr($cep, 5);
    $local   = $cidade !== '' ? $cidade . ($uf !== '' ? ' - ' . $uf : '') : '';
    $destino = implode(', ', array_filter([$rua, $bairro, $local, $cep_fmt], fn ($p) => $p !== ''));
    $chave   = 'cep:' . $cep;
    $rotulo  = 'CEP ' . $cep_fmt . ($bairro !== '' || $local !== ''
             ? ' (' . implode(', ', array_filter([$bairro, $local], fn ($p) => $p !== '')) . ')' : '');
}

// --- Cache da sessão + limite de destinos novos -----------------------------
$id_sessao = $chave ?? mb_strtolower($destino);
if (isset($_SESSION['_frete_estimativas'][$id_sessao])) {
    _frete_json($_SESSION['_frete_estimativas'][$id_sessao]);
}

$agora = time();
$recentes = array_values(array_filter(
    $_SESSION['_frete_consultas'] ?? [],
    fn ($t) => $t > $agora - 3600
));
if (count($recentes) >= FRETE_LIMITE_HORA) {
    _frete_json(['ok' => false, 'mensagem' => 'Muitas consultas seguidas. Tente novamente mais tarde.'], 429);
}
$recentes[] = $agora;
$_SESSION['_frete_consultas'] = $recentes;

// --- Cálculo ----------------------------------------------------------------
$r = frete_calcular('motoboy', $destino, $chave);
$resposta = [
    'ok'           => (bool) $r['ok'],
    'frete'        => $r['ok'] ? money((int) $r['frete_centavos']) : null,
    'distancia_km' => $r['distancia_km'],
    'destino'      => $rotulo,
    'motivo'       => $r['motivo'],
    'mensagem'     => $r['ok'] ? null : ($r['motivo'] === 'fora_raio'
        ? 'Fora da área de entrega por motoboy. Disponível para retirada.'
        : 'Não foi possível calcular o frete agora. Tente novamente mais tarde.'),
];
// Guarda só resultados definitivos (falha temporária pode dar certo depois).
if ($r['ok'] || $r['motivo'] === 'fora_raio') {
    $_SESSION['_frete_estimativas'][$id_sessao] = $resposta;
}
_frete_json($resposta);
