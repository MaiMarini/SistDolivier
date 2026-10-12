<?php
/**
 * Envio de e-mail por SMTP autenticado (passo 4.2 do plano), sem dependências.
 *
 * Tudo vem do .env (nunca do banco):
 *   SMTP_HOST, SMTP_PORT, SMTP_SECURE (ssl | tls | nenhum), SMTP_USER, SMTP_PASS,
 *   MAIL_FROM, MAIL_FROM_NAME
 *
 * email_enviar() devolve ['ok' => bool, 'erro' => string]. O erro explica a etapa que
 * falhou (conexão, certificado, login, destinatário...) e nunca inclui a senha.
 * Mensagem em HTML com versão em texto, corpo em base64 (sem problema de linha longa).
 */

/** Configuração do .env, já normalizada. */
function email_config(): array
{
    $seg = strtolower(trim((string) env('SMTP_SECURE', '')));
    $porta = (int) env('SMTP_PORT', 0);
    if (!in_array($seg, ['ssl', 'tls', 'nenhum', 'none', ''], true)) {
        $seg = '';
    }
    if ($seg === '') {
        $seg = $porta === 587 ? 'tls' : 'ssl';
    }
    if ($seg === 'none') {
        $seg = 'nenhum';
    }
    $user = trim((string) env('SMTP_USER', ''));
    return [
        'host'  => trim((string) env('SMTP_HOST', '')),
        'porta' => $porta > 0 ? $porta : ($seg === 'tls' ? 587 : 465),
        'seg'   => $seg,
        'user'  => $user,
        'pass'  => (string) env('SMTP_PASS', ''),
        'de'    => trim((string) env('MAIL_FROM', '')) ?: $user,
        'nome'  => trim((string) env('MAIL_FROM_NAME', '')) ?: (string) cfg('site_nome', 'Loja'),
    ];
}

/** Nomes das variáveis que faltam no .env (só os nomes; nunca os valores). */
function email_faltando(): array
{
    $f = [];
    foreach (['SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'] as $k) {
        if (trim((string) env($k, '')) === '') {
            $f[] = $k;
        }
    }
    if (trim((string) env('MAIL_FROM', '')) === '' && trim((string) env('SMTP_USER', '')) === '') {
        $f[] = 'MAIL_FROM';
    }
    return $f;
}

/** Cabeçalho com acentos (RFC 2047). */
function _email_cab(string $texto): string
{
    return preg_match('/[^\x20-\x7E]/', $texto) ? '=?UTF-8?B?' . base64_encode($texto) . '?=' : $texto;
}

/**
 * Envia um e-mail. $html é o corpo; a versão em texto é gerada a partir dele se
 * $texto vier vazio.
 */
function email_enviar(string $para, string $assunto, string $html, string $texto = ''): array
{
    $c = email_config();
    $para = trim($para);
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erro' => 'Endereço de destino inválido.'];
    }
    if ($falta = email_faltando()) {
        return ['ok' => false, 'erro' => 'Faltam no .env do servidor: ' . implode(', ', $falta) . '.'];
    }
    if (!filter_var($c['de'], FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erro' => 'MAIL_FROM no .env não é um e-mail válido.'];
    }

    $alvo = ($c['seg'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['porta'];
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $c['host'], 'SNI_enabled' => true,
    ]]);
    $erroTxt = '';
    $fp = @stream_socket_client($alvo, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $ultimo = error_get_last()['message'] ?? '';
        $cert = stripos($ultimo . $errstr, 'certificate') !== false || stripos($ultimo, 'SSL') !== false;
        return ['ok' => false, 'erro' => $cert
            ? "Falha na conexão segura (SSL) com {$c['host']}:{$c['porta']}. Confira a porta (465 = ssl, 587 = tls) e use em SMTP_HOST o nome que aparece em “Connect Devices” no cPanel (às vezes é o nome do servidor, como brXXX.hostgator.com.br)."
            : "Não conectou em {$c['host']}:{$c['porta']} ({$c['seg']}): " . trim($errstr ?: $ultimo) . '. Confira host e porta; a hospedagem pode bloquear essa porta.'];
    }
    stream_set_timeout($fp, 15);

    $ler = function () use ($fp): string {
        $r = '';
        while (($l = fgets($fp, 2048)) !== false) {
            $r .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        return $r;
    };
    $cmd = function (string $linha) use ($fp, $ler): string {
        fwrite($fp, $linha . "\r\n");
        return $ler();
    };
    $cod = fn (string $r) => (int) substr($r, 0, 3);
    $curto = fn (string $r) => mb_substr(trim(preg_replace('/\s+/', ' ', $r)), 0, 200);
    $ehlo = parse_url((string) ($GLOBALS['config']['base_url'] ?? ''), PHP_URL_HOST) ?: 'localhost';

    try {
        $r = $ler();
        if ($cod($r) !== 220) {
            throw new RuntimeException('O servidor de e-mail não respondeu como esperado: ' . $curto($r));
        }
        $r = $cmd('EHLO ' . $ehlo);
        if ($cod($r) !== 250) {
            throw new RuntimeException('EHLO recusado: ' . $curto($r));
        }
        if ($c['seg'] === 'tls') {
            if ($cod($cmd('STARTTLS')) !== 220) {
                throw new RuntimeException('O servidor não aceitou STARTTLS. Tente SMTP_PORT=465 com SMTP_SECURE=ssl.');
            }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException("Certificado SSL do servidor {$c['host']} não confere (TLS). Use em SMTP_HOST o nome que aparece em “Connect Devices” no cPanel.");
            }
            $r = $cmd('EHLO ' . $ehlo);
            if ($cod($r) !== 250) {
                throw new RuntimeException('EHLO recusado depois do TLS: ' . $curto($r));
            }
        }
        // Autenticação: AUTH LOGIN e, se não der, AUTH PLAIN.
        $ok = false;
        $resp = '';
        if ($cod($cmd('AUTH LOGIN')) === 334 && $cod($cmd(base64_encode($c['user']))) === 334) {
            $resp = $cmd(base64_encode($c['pass']));
            $ok = $cod($resp) === 235;
        }
        if (!$ok) {
            $r = $cmd('AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . $c['pass']));
            $ok = $cod($r) === 235;
            $resp = $resp !== '' ? $resp : $r;
        }
        if (!$ok) {
            // Diagnóstico sem expor a senha: usuário usado, tamanho da senha lida e sinais de aspas/espaços.
            $p = $c['pass'];
            $dicas = [];
            if (!str_contains($c['user'], '@')) {
                $dicas[] = 'SMTP_USER deve ser o endereço completo (ex.: ' . $c['de'] . ')';
            }
            if (preg_match('/^["\']|["\']$/', $p)) {
                $dicas[] = 'a senha lida começa ou termina com aspas (confira se há aspas só de um lado)';
            }
            if ($p !== trim((string) $p) || preg_match('/\s/', $p)) {
                $dicas[] = 'a senha lida tem espaço';
            }
            throw new RuntimeException('Usuário ou senha do e-mail recusados pelo servidor (resposta: ' . $curto($resp) . '). '
                . 'Usuário usado: ' . $c['user'] . '; senha lida do .env com ' . mb_strlen($p) . ' caracteres.'
                . ($dicas ? ' Atenção: ' . implode('; ', $dicas) . '.' : '')
                . ' Confira se a mesma senha entra no webmail com esse usuário.');
        }
        $r = $cmd('MAIL FROM:<' . $c['de'] . '>');
        if ($cod($r) !== 250) {
            throw new RuntimeException('Remetente recusado (MAIL_FROM deve ser a mesma conta do SMTP_USER): ' . $curto($r));
        }
        $r = $cmd('RCPT TO:<' . $para . '>');
        if (!in_array($cod($r), [250, 251], true)) {
            throw new RuntimeException('Destinatário recusado: ' . $curto($r));
        }
        if ($cod($cmd('DATA')) !== 354) {
            throw new RuntimeException('O servidor recusou o envio da mensagem (DATA).');
        }

        if ($texto === '') {
            $texto = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h[1-6]|/tr|/li)[^>]*>#i', "\n", $html)), ENT_QUOTES, 'UTF-8');
            $texto = trim(preg_replace("/\n{3,}/", "\n\n", $texto));
        }
        $dominio = substr(strrchr($c['de'], '@'), 1);
        $limite = 'b' . bin2hex(random_bytes(12));
        $msg = 'Date: ' . date('r') . "\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $dominio . ">\r\n"
            . 'From: ' . _email_cab($c['nome']) . ' <' . $c['de'] . ">\r\n"
            . 'To: <' . $para . ">\r\n"
            . 'Subject: ' . _email_cab($assunto) . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"$limite\"\r\n\r\n"
            . "--$limite\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($texto)) . "\r\n"
            . "--$limite\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . "--$limite--\r\n";
        $r = $cmd($msg . '.');
        if ($cod($r) !== 250) {
            throw new RuntimeException('Mensagem recusada pelo servidor: ' . $curto($r));
        }
        $cmd('QUIT');
    } catch (Throwable $e) {
        $erroTxt = $e->getMessage();
    }
    fclose($fp);

    if ($erroTxt !== '') {
        error_log('[email] ' . $erroTxt);
        return ['ok' => false, 'erro' => $erroTxt];
    }
    return ['ok' => true, 'erro' => ''];
}

/**
 * Roda $fn depois que a resposta já foi entregue ao navegador (quando a hospedagem
 * permite): quem usa o site não espera o servidor de e-mail. Erros só vão para o log.
 */
function email_depois(callable $fn): void
{
    register_shutdown_function(function () use ($fn): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        try {
            $fn();
        } catch (Throwable $e) {
            error_log('[email] ' . $e->getMessage());
        }
    });
}

/** Moldura simples nas cores da loja (estilos inline, que é o que os leitores de e-mail aceitam). */
function email_layout(string $titulo, string $conteudo_html): string
{
    $nome = e((string) cfg('site_nome', 'Loja'));
    return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;background:#F6EEDD;font-family:Arial,Helvetica,sans-serif;color:#4A3320;">'
        . '<div style="max-width:560px;margin:0 auto;padding:24px;">'
        . '<div style="background:#ffffff;border-radius:12px;overflow:hidden;">'
        . '<div style="background:#6B4A2C;color:#ffffff;padding:16px 20px;font-size:18px;font-weight:bold;">' . $nome . '</div>'
        . '<div style="padding:20px;line-height:1.6;font-size:15px;">'
        . '<h1 style="color:#6B4A2C;font-size:20px;margin:0 0 12px;">' . e($titulo) . '</h1>'
        . $conteudo_html
        . '</div></div></div></body></html>';
}
