<?php
/**
 * Front controller / roteador da loja.
 *
 * Lê o caminho da URL, remove o prefixo da base_url e direciona para o arquivo
 * de página correspondente em app/pages (ou app/pages/admin para /admin/...).
 * Caminho desconhecido -> página 404.
 */

// Apenas no servidor embutido do PHP (php -S): se a URL aponta para um arquivo
// real (CSS, JS, imagem...), deixa o próprio servidor entregá-lo. Em produção
// (Apache) isto é ignorado, pois quem trata arquivos reais é o .htaccess.
if (php_sapi_name() === 'cli-server') {
    $arquivo_real = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($arquivo_real)) {
        return false;
    }
}

require __DIR__ . '/app/bootstrap.php';

// --- 1. Descobrir o caminho solicitado --------------------------------------
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = $uri === null ? '/' : rawurldecode($uri);

// Remove o caminho-base (caso o site rode em subpasta, ex.: /loja).
$base_path = parse_url($GLOBALS['config']['base_url'] ?? '', PHP_URL_PATH);
$base_path = $base_path === null ? '' : rtrim($base_path, '/');
if ($base_path !== '' && strpos($uri, $base_path) === 0) {
    $uri = substr($uri, strlen($base_path));
}

// Normaliza: sem barras nas pontas; quebra em segmentos.
$caminho   = trim($uri, '/');
$segmentos = $caminho === '' ? [] : explode('/', $caminho);

// --- 2. Tabela de rotas ------------------------------------------------------
// rota (1º segmento) => arquivo em app/pages/
$rotas = [
    ''             => 'home',
    'home'         => 'home',
    'categoria'    => 'categoria',
    'produto'      => 'produto',
    'carrinho'     => 'carrinho',
    'sobre'        => 'sobre',
    'regras'       => 'regras',
    'entrar'       => 'entrar',
    'cadastrar'    => 'cadastrar',
    'sair'         => 'sair',
    'meus-pedidos' => 'meus_pedidos',
];

// rota admin (2º segmento) => arquivo em app/pages/admin/
$rotas_admin = [
    ''           => 'index',       // /admin            -> painel
    'entrar'     => 'entrar',      // /admin/entrar     -> login do admin
    'sair'       => 'sair',        // /admin/sair       -> logout
    'categorias'    => 'categorias',    // /admin/categorias    -> CRUD de categorias
    'produtos'      => 'produtos',      // /admin/produtos      -> CRUD de produtos + galeria
    'tabelas-nutricionais' => 'tabelas_nutricionais', // /admin/tabelas-nutricionais -> CRUD
    'banners'       => 'banners',       // /admin/banners       -> banners da home
    'configuracoes' => 'configuracoes', // /admin/configuracoes -> editar settings
];

// --- 3. Resolver a rota ------------------------------------------------------
$arquivo = null;       // caminho do arquivo de página a incluir
$params  = [];         // segmentos extras (ex.: slug do produto)

if (isset($segmentos[0]) && $segmentos[0] === 'admin') {
    // Área administrativa: /admin/<rota>/...
    $chave = $segmentos[1] ?? '';
    if (array_key_exists($chave, $rotas_admin)) {
        $arquivo = APP_PATH . '/pages/admin/' . $rotas_admin[$chave] . '.php';
        $params  = array_slice($segmentos, 2);
    }
} else {
    // Área do cliente: /<rota>/...
    $chave = $segmentos[0] ?? '';
    if (array_key_exists($chave, $rotas)) {
        $arquivo = APP_PATH . '/pages/' . $rotas[$chave] . '.php';
        $params  = array_slice($segmentos, 1);
    }
}

// --- 4. Despachar ------------------------------------------------------------
if ($arquivo !== null && is_file($arquivo)) {
    require $arquivo;
    return;
}

// Caminho desconhecido (ou página ainda não criada) -> 404.
http_response_code(404);
$pagina_404 = APP_PATH . '/pages/404.php';
if (is_file($pagina_404)) {
    require $pagina_404;
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
        . '<title>404</title></head><body '
        . 'style="font-family:sans-serif;max-width:640px;margin:60px auto;line-height:1.5;color:#333">'
        . '<h1>404 — Página não encontrada</h1>'
        . '<p>O endereço solicitado não existe.</p></body></html>';
}
