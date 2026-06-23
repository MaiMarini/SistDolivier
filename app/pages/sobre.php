<?php
/**
 * Página "Sobre": mostra o texto vindo das configurações (settings.sobre_texto).
 */
ob_start();
?>
<h1>Sobre</h1>
<div><?= nl2br(e(cfg('sobre_texto', 'Em breve.'))) ?></div>
<?php
view('layout', ['titulo' => 'Sobre', 'conteudo' => ob_get_clean()]);
