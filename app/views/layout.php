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

    <!-- As cores do tema são fixas no theme.css (:root), não vêm mais do banco. -->
    <link rel="stylesheet" href="<?= e(url('assets/css/theme.css')) ?>">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>
<?php require __DIR__ . '/tarja.php'; ?>

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

<!-- Modal de regras com aceite (conteúdo vem das configurações) -->
<?php require __DIR__ . '/modal-regras.php'; ?>

<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
