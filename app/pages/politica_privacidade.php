<?php
/**
 * Página "Política de privacidade": /politica-de-privacidade
 * O texto vem de settings.politica_privacidade_texto (editável). Enquanto não
 * for cadastrado, mostra um texto padrão explicando o uso de cookies (LGPD).
 */
$padrao = "Levamos a sua privacidade a sério. Este site coleta apenas os dados "
    . "necessários para funcionar (como sessão de login e carrinho) e, com o seu "
    . "consentimento, cookies para melhorar a sua experiência.\n\n"
    . "Cookies essenciais são sempre usados para o funcionamento do site. "
    . "Cookies não essenciais (como conteúdos incorporados de terceiros) só são "
    . "carregados se você escolher \"Aceitar todos\" no aviso de cookies.\n\n"
    . "Você pode rever a sua escolha a qualquer momento em \"Preferências de "
    . "cookies\", no rodapé do site.";

ob_start();
?>
<h1>Política de privacidade</h1>
<div><?= nl2br(e(cfg('politica_privacidade_texto', $padrao))) ?></div>
<?php
view('layout', ['titulo' => 'Política de privacidade', 'conteudo' => ob_get_clean()]);
