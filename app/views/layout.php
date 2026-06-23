<?php
/**
 * Layout base do site. Espera:
 *   $titulo   (string) — título da aba/página
 *   $conteudo (string) — HTML já renderizado da página
 *
 * Injeta as cores do banco (settings) como variáveis CSS no <head>, de modo
 * que o theme.css seja totalmente genérico e a "marca" venha da configuração.
 */
$titulo   = isset($titulo) ? $titulo : cfg('site_nome', 'Loja');
$conteudo = isset($conteudo) ? $conteudo : '';
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> — <?= e(cfg('site_nome', 'Loja')) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Nunito:wght@400;600;700&display=swap">

    <link rel="stylesheet" href="<?= e(url('assets/css/theme.css')) ?>">

    <!-- Cores do tema vindas das configurações da loja -->
    <style>
        :root {
            --cor-primaria:   <?= e(cfg('cor_primaria',   '#6B4A2C')) ?>;
            --cor-destaque:   <?= e(cfg('cor_destaque',   '#D4A53F')) ?>;
            --cor-acento:     <?= e(cfg('cor_acento',     '#8C9A5E')) ?>;
            --cor-pop:        <?= e(cfg('cor_pop',        '#BC5B38')) ?>;
            --cor-fundo:      <?= e(cfg('cor_fundo',      '#F6EEDD')) ?>;
            --cor-texto:      <?= e(cfg('cor_texto',      '#4A3320')) ?>;
            --cor-superficie: <?= e(cfg('cor_superficie', '#FFFFFF')) ?>;
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main>
    <div class="container">
        <?php
        $flash_sucesso = flash_consumir('sucesso');
        $flash_erro    = flash_consumir('erro');
        ?>
        <?php if ($flash_sucesso !== null): ?>
            <div class="flash sucesso"><?= e($flash_sucesso) ?></div>
        <?php endif; ?>
        <?php if ($flash_erro !== null): ?>
            <div class="flash erro"><?= e($flash_erro) ?></div>
        <?php endif; ?>

        <?= $conteudo /* HTML já escapado/renderizado pela página */ ?>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>

<!-- Modal de regras (conteúdo vem das configurações) -->
<div class="modal" id="modal-regras" role="dialog" aria-modal="true" aria-labelledby="modal-regras-titulo">
    <div class="modal-conteudo">
        <button class="modal-fechar" type="button" data-fechar-modal aria-label="Fechar">&times;</button>
        <h2 id="modal-regras-titulo">Regras e informações</h2>
        <div><?= nl2br(e(cfg('regras_texto', 'Em breve.'))) ?></div>
        <p class="mt-1">
            <button class="btn sec" type="button" data-fechar-modal>Fechar</button>
        </p>
    </div>
</div>

<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
