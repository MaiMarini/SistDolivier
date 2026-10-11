<?php
/**
 * Sessão e login mais seguros (passo 4.3 do plano).
 *
 *  - sessao_iniciar(): cookie Secure (em HTTPS) + HttpOnly + SameSite=Lax, modo
 *    estrito, pasta própria de sessões (a limpeza da hospedagem apagaria em ~24 min)
 *    e cookie renovado a cada dia de uso.
 *  - sessao_verificar_expiracao(): admin sai depois de 2 h sem uso; cliente, 30 dias.
 *  - sessao_encerrar(): logout de verdade (apaga a sessão e o cookie).
 *  - login_bloqueado() / login_falha() / login_sucesso(): 5 falhas por e-mail ou
 *    20 por IP em 15 minutos bloqueiam por 15 minutos (tabela login_tentativas).
 *  - ip_cliente(): o site passa pelo Cloudflare; o IP real vem de CF-Connecting-IP,
 *    aceito só quando a conexão chega de um endereço do Cloudflare.
 */

const SESSAO_CLIENTE_SEG = 30 * 86400;   // 30 dias sem uso
const LOGIN_JANELA_SEG   = 15 * 60;      // 15 minutos
const LOGIN_MAX_EMAIL    = 5;
const LOGIN_MAX_IP       = 20;

/** Inatividade do admin, em segundos (2 h; SESSAO_ADMIN_MINUTOS no .env só para testes). */
function sessao_admin_seg(): int
{
    $min = (int) env('SESSAO_ADMIN_MINUTOS', 0);
    return ($min > 0 ? $min : 120) * 60;
}

/** A requisição chegou por HTTPS (direto ou pelo Cloudflare/proxy)? */
function requisicao_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"https"');
}

/** O IP está dentro da faixa CIDR (IPv4 ou IPv6)? */
function ip_na_faixa(string $ip, string $cidr): bool
{
    [$rede, $bits] = explode('/', $cidr) + [1 => null];
    $a = @inet_pton($ip);
    $b = @inet_pton($rede);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
        return false;
    }
    $resto = $bits % 8;
    if ($resto === 0) {
        return true;
    }
    $mascara = chr((0xFF << (8 - $resto)) & 0xFF);
    return (($a[$bytes] & $mascara) === ($b[$bytes] & $mascara));
}

/** Faixas publicadas pelo Cloudflare (cloudflare.com/ips). */
function cloudflare_faixas(): array
{
    return [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}

/** IP do cliente: REMOTE_ADDR, ou CF-Connecting-IP quando quem conecta é o Cloudflare. */
function ip_cliente(): string
{
    $remoto = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $cf = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        foreach (cloudflare_faixas() as $faixa) {
            if (ip_na_faixa($remoto, $faixa)) {
                return $cf;
            }
        }
    }
    return $remoto;
}

/** Pasta das sessões: fora do public_html se der; senão app/sessoes (bloqueada na web). */
function sessao_pasta(): ?string
{
    foreach ([dirname(ROOT_PATH) . '/sessoes_loja', APP_PATH . '/sessoes'] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }
    return null;
}

/** Configura e abre a sessão. Mantém o nome do cookie (PHPSESSID). */
function sessao_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) SESSAO_CLIENTE_SEG);
    $pasta = sessao_pasta();
    if ($pasta !== null) {
        session_save_path($pasta);
        // Pasta própria: a limpeza fica com o PHP (1% das requisições), com o prazo de 30 dias.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    $parametros = [
        'lifetime' => SESSAO_CLIENTE_SEG,
        'path'     => '/',
        'secure'   => requisicao_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($parametros);
    session_start();

    // Renova a validade do cookie uma vez por dia de uso (expira por inatividade, não
    // por data fixa). Só quando o cookie veio na requisição com esta mesma sessão: na
    // primeira visita, e quando a sessão é trocada (login, expiração) ou encerrada
    // (sair), o próprio PHP envia o cookie. A decisão fica para o último momento antes
    // dos cabeçalhos saírem, para nunca sair um Set-Cookie em dobro.
    $veio = $_COOKIE[session_name()] ?? null;
    if ($veio === null || $veio !== session_id()) {
        $_SESSION['_cookie_renovado'] = time();   // sessão nova: o PHP já envia o cookie
        return;
    }
    if (($_SESSION['_cookie_renovado'] ?? 0) >= time() - 86400) {
        return;
    }
    header_register_callback(function () use ($veio, $parametros): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;   // encerrada nesta requisição
        }
        if (session_id() !== $veio) {
            $_SESSION['_cookie_renovado'] = time();   // trocada (login, expiração): o PHP já mandou o cookie novo
            return;
        }
        foreach (headers_list() as $h) {
            if (stripos($h, 'Set-Cookie: ' . session_name() . '=') === 0) {
                return;
            }
        }
        $_SESSION['_cookie_renovado'] = time();
        setcookie(session_name(), $veio, [
            'expires'  => time() + SESSAO_CLIENTE_SEG,
            'path'     => '/',
            'secure'   => $parametros['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    });
}

/**
 * Sessão logada parada há tempo demais: sai da conta. Numa página (GET), volta para
 * a home com o login aberto e "Sua sessão expirou. Entre de novo."; em envios e
 * chamadas de fundo, só tira o login (a página pede para entrar de novo).
 */
function sessao_verificar_expiracao(): void
{
    $agora = time();
    $usuario = $_SESSION['usuario'] ?? null;
    $ultimo = (int) ($_SESSION['_ultimo_uso'] ?? 0);
    $_SESSION['_ultimo_uso'] = $agora;
    if ($usuario === null || $ultimo === 0) {
        return;
    }
    $limite = !empty($usuario['is_admin']) ? sessao_admin_seg() : SESSAO_CLIENTE_SEG;
    if ($agora - $ultimo <= $limite) {
        return;
    }
    unset($_SESSION['usuario']);
    session_regenerate_id(true);
    $pagina = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === '';
    if ($pagina) {
        lembrar_destino();
        abrir_login('Sua sessão expirou. Entre de novo.');
    }
    flash('erro', 'Sua sessão expirou. Entre de novo.');
    flash('abrir_login', '1');
}

/**
 * Logout: apaga os dados e o arquivo da sessão; abre uma sessão nova (outro id) só
 * para o aviso e o carrinho. O cookie novo, de mesmo nome e caminho, substitui o
 * antigo na mesma resposta (um único Set-Cookie); o id antigo deixa de existir.
 */
function sessao_encerrar(string $aviso): void
{
    $carrinho = $_SESSION['carrinho'] ?? null;
    $_SESSION = [];
    session_destroy();

    session_id(session_create_id());
    session_start();
    $_SESSION['_cookie_renovado'] = time();
    if ($carrinho) {
        $_SESSION['carrinho'] = $carrinho;
    }
    flash('sucesso', $aviso);
}

// --- Limite de tentativas de login ------------------------------------------------

/** E-mail como chave de contagem (minúsculo, sem espaços). */
function login_email_chave(string $email): string
{
    return mb_substr(mb_strtolower(trim($email)), 0, 190);
}

/**
 * Bloqueado? Há N falhas (5 do e-mail ou 20 do IP) num intervalo de 15 minutos e a
 * última delas tem menos de 15 minutos: o bloqueio dura 15 minutos depois da última falha.
 * Tentativas bloqueadas não contam (não estendem o prazo).
 */
function login_bloqueado(string $email, string $ip): bool
{
    try {
        foreach ([['email', login_email_chave($email), LOGIN_MAX_EMAIL], ['ip', $ip, LOGIN_MAX_IP]] as [$col, $valor, $max]) {
            if ($valor === '') {
                continue;
            }
            $st = db()->prepare("SELECT UNIX_TIMESTAMP(criado_em) FROM login_tentativas
                                  WHERE $col = ? AND criado_em > NOW() - INTERVAL 30 MINUTE
                                  ORDER BY criado_em DESC LIMIT $max");
            $st->execute([$valor]);
            $ts = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (count($ts) < $max) {
                continue;
            }
            $agora = (int) db()->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn();
            if ($ts[0] - $ts[$max - 1] <= LOGIN_JANELA_SEG && $agora - $ts[0] < LOGIN_JANELA_SEG) {
                return true;
            }
        }
    } catch (PDOException $e) {
        // Migração ainda não rodada: não bloqueia ninguém (o login continua funcionando).
    }
    return false;
}

/** De vez em quando (1% das tentativas), apaga as linhas com mais de 1 dia. */
function login_limpar_antigas(): void
{
    if (random_int(1, 100) === 1) {
        db()->exec('DELETE FROM login_tentativas WHERE criado_em < NOW() - INTERVAL 1 DAY');
    }
}

/** Registra uma falha (sempre, exista ou não o e-mail). */
function login_falha(string $email, string $ip): void
{
    try {
        db()->prepare('INSERT INTO login_tentativas (email, ip, criado_em) VALUES (?, ?, NOW())')
            ->execute([login_email_chave($email), mb_substr($ip, 0, 45)]);
        login_limpar_antigas();
    } catch (PDOException $e) {
    }
}

/** Login certo: apaga as falhas daquele e-mail. */
function login_sucesso(string $email): void
{
    try {
        db()->prepare('DELETE FROM login_tentativas WHERE email = ?')->execute([login_email_chave($email)]);
        login_limpar_antigas();
    } catch (PDOException $e) {
    }
}
