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
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/notificacoes.css')) ?>">
</head>
<body>

<?php require __DIR__ . '/header.php'; ?>

<main>
    <div class="container">
        <?php
        $flash_sucesso = flash_consumir('sucesso');
        $flash_erro    = flash_consumir('erro');
        ?>
        <?= $conteudo /* HTML já escapado/renderizado pela página */ ?>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>

<!-- Modal de regras com aceite (conteúdo vem das configurações) -->
<?php require __DIR__ . '/modal-regras.php'; ?>

<script src="<?= e(asset('assets/js/notificacoes.js')) ?>"></script>
<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<?php if ($flash_sucesso !== null || $flash_erro !== null): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($flash_sucesso !== null): ?>notificar('sucesso', <?= json_encode($flash_sucesso) ?>);<?php endif; ?>
    <?php if ($flash_erro !== null): ?>notificar('erro', <?= json_encode($flash_erro) ?>);<?php endif; ?>
});
</script>
<?php endif; ?>
</body>
</html>
