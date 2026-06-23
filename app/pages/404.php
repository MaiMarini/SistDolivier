<?php
/**
 * Página amigável de "não encontrado". É usada pelo roteador (index.php) para
 * rotas desconhecidas, que já define o código HTTP 404 antes de incluí-la.
 */
if (!headers_sent()) {
    http_response_code(404);
}
ob_start();
?>
<h1>Página não encontrada</h1>
<p>O endereço que você tentou acessar não existe ou pode ter sido movido.</p>
<p class="mt-1"><a class="btn" href="<?= e(url()) ?>">Voltar ao início</a></p>
<?php
view('layout', ['titulo' => 'Página não encontrada', 'conteudo' => ob_get_clean()]);
