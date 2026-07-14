<?php

/**
 * Diagnóstico SMTP por linha de comando (NÃO é usado pelo site).
 * Mostra a conversa completa com o servidor para identificar a falha.
 *
 * Uso (Terminal/SSH):
 *   php /caminho/do/site/cron/teste_smtp.php [destinatario@exemplo.com]
 * Se o destinatário for omitido, usa o próprio SMTP_USUARIO.
 *
 * Depois de diagnosticar, pode apagar este arquivo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

require __DIR__ . '/../app/bootstrap.php';

$host = (string) _email_conf('smtp_host', '');
$port = (int) _email_conf('smtp_porta', 465);
$user = (string) _email_conf('smtp_usuario', '');
$pass = (string) _email_conf('smtp_senha', '');
$seg  = _email_conf('smtp_seguranca', 'ssl') === 'tls' ? 'tls' : 'ssl';
$para = $argv[1] ?? $user;

$mascara = strlen($pass)
    ? substr($pass, 0, 2) . str_repeat('*', max(0, strlen($pass) - 4)) . substr($pass, -2)
    : '(vazia)';

echo "==== Configuração lida do .env ====\n";
echo "host   = {$host}\n";
echo "porta  = {$port}\n";
echo "seg    = {$seg}\n";
echo "user   = {$user}\n";
echo "senha  = {$mascara} (" . strlen($pass) . " chars)\n";
echo "destino= {$para}\n\n";

if ($host === '' || $user === '' || $pass === '') {
    exit("ERRO: host/usuário/senha vazios no .env.\n");
}

$dest = ($seg === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
$ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
$fp   = @stream_socket_client($dest, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
if (!$fp) {
    exit("CONEXÃO FALHOU em {$dest}: {$errstr} ({$errno})\n");
}
stream_set_timeout($fp, 20);

$ler = static function ($fp) {
    $r = '';
    while (($l = fgets($fp, 1024)) !== false) {
        $r .= $l;
        if (strlen($l) >= 4 && $l[3] === ' ') {
            break;
        }
    }
    return rtrim($r);
};
$cmd = static function ($fp, $c, $rotulo = null) use ($ler) {
    fwrite($fp, $c . "\r\n");
    $r = $ler($fp);
    echo '>>> ' . ($rotulo ?? $c) . "\n<<< " . $r . "\n";
    return $r;
};
$cod = static function ($r) { return substr($r, 0, 3); };

echo "<<< " . $ler($fp) . "\n"; // saudação 220
$cmd($fp, 'EHLO teste.local');
if ($seg === 'tls') {
    $cmd($fp, 'STARTTLS');
    @stream_socket_enable_crypto(
        $fp,
        true,
        STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
    );
    $cmd($fp, 'EHLO teste.local');
}

$cmd($fp, 'AUTH LOGIN');
$cmd($fp, base64_encode($user), '[usuário em base64]');
$r = $cmd($fp, base64_encode($pass), '[senha em base64]');

if ($cod($r) !== '235') {
    echo "\n>>> RESULTADO: AUTENTICAÇÃO RECUSADA.\n";
    $cmd($fp, 'QUIT');
    fclose($fp);
    exit(1);
}

echo "\n>>> RESULTADO: AUTENTICAÇÃO OK. Enviando mensagem de teste...\n";
$cmd($fp, 'MAIL FROM:<' . $user . '>');
$cmd($fp, 'RCPT TO:<' . $para . '>');
if ($cod($cmd($fp, 'DATA')) === '354') {
    $msg = "From: {$user}\r\nTo: {$para}\r\nSubject: Teste SMTP CLI\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
        . "Funcionou! Envio SMTP pelo CLI OK.\r\n.";
    $r = $cmd($fp, $msg, '[corpo da mensagem]');
    echo "\n>>> " . ($cod($r) === '250' ? 'ENVIADO com sucesso.' : 'Servidor recusou a mensagem.') . "\n";
}
$cmd($fp, 'QUIT');
fclose($fp);
echo "\n==== FIM ====\n";
