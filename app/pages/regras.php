<?php
/**
 * Página "Regras": mostra o texto vindo das configurações (settings.regras_texto).
 */
ob_start();
?>
<h1>Regras e prazos</h1>
<div><?= nl2br(e(cfg('regras_texto', 'Em breve.'))) ?></div>
<?php
view('layout', ['titulo' => 'Regras', 'conteudo' => ob_get_clean()]);
