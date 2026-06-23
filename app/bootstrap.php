<?php
/**
 * Bootstrap da aplicação: ponto de partida carregado pelo index.php.
 *
 * Responsabilidades:
 *  - Carregar a configuração (config.php) com erro claro se faltar.
 *  - Ligar/desligar a exibição de erros conforme o ambiente.
 *  - Abrir a conexão PDO com o MySQL.
 *  - Iniciar a sessão.
 *  - Carregar os helpers.
 *  - Carregar as configurações da tabela "settings" para $GLOBALS['settings'].
 */

// Caminhos base da aplicação (sem barra no final).
define('APP_PATH', __DIR__);
define('ROOT_PATH', dirname(__DIR__));

// --- 1. Configuração ---------------------------------------------------------
$config_file = APP_PATH . '/config.php';
if (!is_file($config_file)) {
    // Mensagem clara para quem está instalando a loja.
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
        . '<title>Configuração necessária</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:60px auto;line-height:1.5;color:#333">'
        . '<h1>Configuração necessária</h1>'
        . '<p>O arquivo <code>app/config.php</code> não foi encontrado.</p>'
        . '<p>Copie <code>app/config.example.php</code> para <code>app/config.php</code> '
        . 'e preencha os dados do banco e do site.</p>'
        . '</body></html>';
    exit;
}

$config = require $config_file;
$GLOBALS['config'] = $config;

// --- 2. Exibição de erros conforme ambiente ----------------------------------
$is_dev = (isset($config['env']) && $config['env'] === 'dev');
if ($is_dev) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL); // continua logando, mas não exibe na tela.
}

// --- 3. Conexão PDO ----------------------------------------------------------
$dsn = 'mysql:host=' . $config['db_host']
     . ';dbname=' . $config['db_name']
     . ';charset=utf8mb4';

try {
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    if ($is_dev) {
        echo '<h1>Erro ao conectar ao banco de dados</h1><pre>'
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<h1>Serviço temporariamente indisponível</h1>';
    }
    exit;
}
$GLOBALS['pdo'] = $pdo;

// --- 4. Sessão ---------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// --- 5. Helpers --------------------------------------------------------------
require APP_PATH . '/helpers.php';

// --- 6. Configurações da loja (tabela settings) ------------------------------
// Carregadas em $GLOBALS['settings'] e lidas via cfg(). Se a tabela ainda não
// existir (instalação inicial), seguimos com um array vazio.
$GLOBALS['settings'] = [];
try {
    $stmt = $pdo->query('SELECT chave, valor FROM settings');
    foreach ($stmt->fetchAll() as $linha) {
        $GLOBALS['settings'][$linha['chave']] = $linha['valor'];
    }
} catch (PDOException $e) {
    // Tabela ainda não criada: mantém settings vazio (cfg() usa os padrões).
}
