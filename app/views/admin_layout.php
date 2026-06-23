<?php
/**
 * Layout do painel administrativo (enxuto, sem a vitrine da loja).
 * Espera $titulo e $conteudo. Reaproveita o theme.css e as cores das settings.
 */
$titulo   = isset($titulo) ? $titulo : 'Admin';
$conteudo = isset($conteudo) ? $conteudo : '';
$u        = usuario_atual();
$ehAdmin  = ($u !== null && !empty($u['is_admin']));
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> — Admin — <?= e(cfg('site_nome', 'Loja')) ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Nunito:wght@400;600;700&display=swap">
    <link rel="stylesheet" href="<?= e(url('assets/css/theme.css')) ?>">

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

<header class="cabecalho">
    <div class="container cabecalho-inner">
        <a class="logo" href="<?= e(url('admin')) ?>"><?= e(cfg('site_nome', 'Loja')) ?> · Admin</a>
        <?php if ($ehAdmin): ?>
            <nav class="nav" id="menu">
                <a href="<?= e(url('admin')) ?>">Painel</a>
                <a href="<?= e(url()) ?>" target="_blank" rel="noopener">Ver loja</a>
                <a href="<?= e(url('admin/sair')) ?>">Sair</a>
            </nav>
        <?php endif; ?>
    </div>
</header>

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

        <?= $conteudo ?>
    </div>
</main>

<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
