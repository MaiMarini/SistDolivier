<?php
/**
 * Layout reutilizável do painel administrativo (com menu lateral).
 * Espera $titulo e $conteudo. Reaproveita o theme.css e as cores das settings.
 *
 * Itens "Pedidos" e "E-mails" aparecem como "em breve" (fases futuras).
 * O destaque do item ativo usa o helper admin_menu_ativo().
 */
$titulo   = isset($titulo) ? $titulo : 'Admin';
$conteudo = isset($conteudo) ? $conteudo : '';
$u        = usuario_atual();
$ehAdmin  = ($u !== null && !empty($u['is_admin']));

// Itens do menu: rótulo => [seção, rota]
$menu = [
    'Painel'        => ['', 'admin'],
    'Pedidos'       => ['pedidos', 'admin/pedidos'],
    'Produtos'      => ['produtos', 'admin/produtos'],
    'Tabelas nutricionais' => ['tabelas-nutricionais', 'admin/tabelas-nutricionais'],
    'Categorias'    => ['categorias', 'admin/categorias'],
    'Home'          => ['banners', 'admin/banners'],
    'Configurações' => ['configuracoes', 'admin/configuracoes'],
];
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> — Admin — <?= e(cfg('site_nome', 'Loja')) ?></title>

    <!-- Favicon (trigo D'Olivier) -->
    <link rel="icon" href="<?= e(asset('favicon/favicon.ico')) ?>" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= e(asset('favicon/favicon-16.png')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('favicon/favicon-32.png')) ?>">
    <link rel="icon" type="image/png" sizes="48x48" href="<?= e(asset('favicon/favicon-48.png')) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset('favicon/apple-touch-icon.png')) ?>">
    <link rel="icon" type="image/png" sizes="512x512" href="<?= e(asset('favicon/favicon-512.png')) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Nunito:wght@400;600;700&display=swap">
    <!-- Cores fixas no theme.css (:root). -->
    <link rel="stylesheet" href="<?= e(asset('assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/notificacoes.css')) ?>">
</head>
<body>

<header class="admin-topo">
    <div class="admin-topo-inner">
        <a class="logo" href="<?= e(url('admin')) ?>"><?= e(cfg('site_nome', 'Loja')) ?> · Admin</a>
        <?php if ($ehAdmin): ?>
            <div class="admin-user">
                <span>Olá, <?= e($u['nome']) ?></span>
                <a href="<?= e(url()) ?>" target="_blank" rel="noopener">Ver loja</a>
                <a href="<?= e(url('admin/sair')) ?>">Sair</a>
            </div>
        <?php endif; ?>
    </div>
</header>

<div class="admin-corpo">
    <?php if ($ehAdmin): ?>
        <aside class="admin-menu">
            <nav>
                <?php foreach ($menu as $rotulo => $info): ?>
                    <a class="<?= admin_menu_ativo($info[0]) ?>"
                       href="<?= e(url($info[1])) ?>"><?= e($rotulo) ?></a>
                <?php endforeach; ?>
                <span class="menu-breve">E-mails <small>em breve</small></span>
            </nav>
        </aside>
    <?php endif; ?>

    <main class="admin-conteudo">
        <?php
        $flash_sucesso = flash_consumir('sucesso');
        $flash_erro    = flash_consumir('erro');
        ?>
        <h1><?= e($titulo) ?></h1>
        <?= $conteudo ?>
    </main>
</div>

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
