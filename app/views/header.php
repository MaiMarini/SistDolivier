<?php
/**
 * Cabeçalho em DUAS linhas:
 *   linha 1 -> nome "D'Olivier" (logo + nome) centralizado + ícones à direita
 *   linha 2 -> categorias do banco + páginas fixas, distribuídas na largura
 * No celular, a linha 2 vira o menu hambúrguer (overlay); a linha 1 permanece.
 */
$usuario = usuario_atual();

$categorias = [];
try {
    $stmt = db()->query(
        'SELECT slug, nome FROM categories WHERE ativo = 1 ORDER BY ordem ASC, id ASC'
    );
    $categorias = $stmt->fetchAll();
} catch (PDOException $e) {
    $categorias = [];
}

$qtd_carrinho = carrinho_quantidade();
$eh_admin = ($usuario !== null && ($usuario['papel'] ?? '') === 'admin');

// Ícones (SVG inline, traço na cor do texto).
$ico_carrinho = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
$ico_usuario  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
?>
<header class="cabecalho">
    <div class="container">
        <!-- Linha 1: (espaçador/hambúrguer) | marca centralizada | ícones à direita -->
        <div class="header-linha1">
            <div class="header-esq">
                <button class="menu-toggle" type="button" data-menu-toggle
                        aria-label="Abrir menu" aria-controls="menu-mobile" aria-expanded="false">&#9776;</button>
            </div>

            <a class="header-marca" href="<?= e(url()) ?>">
                <img class="header-marca-logo" src="<?= e(asset('Logo/trigo.png')) ?>" height="34" alt="D'Olivier">
                <span class="header-marca-nome">D'Olivier</span>
            </a>

            <div class="header-acoes">
                <a class="header-icone header-carrinho" href="<?= e(url('carrinho')) ?>" aria-label="Carrinho">
                    <?= $ico_carrinho ?>
                    <?php if ($qtd_carrinho > 0): ?>
                        <span class="header-badge"><?= (int) $qtd_carrinho ?></span>
                    <?php endif; ?>
                </a>

                <?php if ($usuario === null): ?>
                    <!-- Deslogado: abre o drawer de login (sem JS, vai para /entrar) -->
                    <a class="header-icone" href="<?= e(url('entrar')) ?>" data-abrir-login aria-label="Entrar">
                        <?= $ico_usuario ?>
                    </a>
                <?php else: ?>
                    <div class="header-perfil">
                        <button class="header-icone" type="button" data-perfil-toggle
                                aria-haspopup="true" aria-expanded="false" aria-label="Conta"><?= $ico_usuario ?></button>
                        <div class="perfil-menu" data-perfil-menu>
                            <?php if ($eh_admin): ?>
                                <a href="<?= e(url('admin')) ?>">Painel</a>
                            <?php else: ?>
                                <a href="<?= e(url('meus-pedidos')) ?>">Meus pedidos</a>
                            <?php endif; ?>
                            <a href="<?= e(url('sair')) ?>">Sair</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Linha 2: categorias + páginas fixas, distribuídas na largura -->
        <nav class="header-linha2">
            <a href="<?= e(url()) ?>">Início</a>
            <?php foreach ($categorias as $categoria): ?>
                <a href="<?= e(url('categoria/' . $categoria['slug'])) ?>"><?= e($categoria['nome']) ?></a>
            <?php endforeach; ?>
            <a href="<?= e(url('sobre')) ?>">Sobre</a>
            <a href="<?= e(url('regras')) ?>">Regras</a>
        </nav>
    </div>
</header>

<!-- Menu mobile em tela cheia (só as abas centrais) -->
<div class="menu-overlay" id="menu-mobile" role="dialog" aria-modal="true" aria-label="Menu">
    <div class="menu-overlay-topo">
        <span class="menu-overlay-titulo">Menu</span>
        <button class="menu-fechar" type="button" data-menu-fechar aria-label="Fechar menu">&times;</button>
    </div>
    <nav class="menu-overlay-nav">
        <a href="<?= e(url()) ?>" data-menu-link>Início</a>
        <?php foreach ($categorias as $categoria): ?>
            <a href="<?= e(url('categoria/' . $categoria['slug'])) ?>" data-menu-link><?= e($categoria['nome']) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(url('sobre')) ?>" data-menu-link>Sobre</a>
        <a href="<?= e(url('regras')) ?>" data-menu-link>Regras</a>
    </nav>
</div>

<?php if ($usuario === null): ?>
<!-- Drawer de login (aprimoramento; sem JS, o ícone vai para /entrar). Reaproveita
     o backend de /entrar: os formulários postam para lá com CSRF + campo "acao". -->
<div class="drawer-overlay" data-login-overlay>
    <aside class="drawer" role="dialog" aria-modal="true" aria-label="Acesso à conta">
        <button class="drawer-fechar" type="button" data-login-fechar aria-label="Fechar">&times;</button>

        <!-- Painel: login -->
        <div data-login-painel="login">
            <h2>Fazer login</h2>
            <form class="formulario" method="post" action="<?= e(url('entrar')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="acao" value="login">
                <div class="campo">
                    <label for="dw-login-email">E-mail</label>
                    <input type="email" id="dw-login-email" name="email" required>
                </div>
                <div class="campo">
                    <label for="dw-login-senha">Senha</label>
                    <input type="password" id="dw-login-senha" name="senha" required>
                </div>
                <button class="btn" type="submit">Fazer login</button>
            </form>
            <p class="mt-1"><a href="<?= e(url('entrar')) ?>">Esqueceu sua senha?</a></p>
            <p><button class="btn sec" type="button" data-login-ir-cadastro>Criar conta</button></p>
        </div>

        <!-- Painel: cadastro -->
        <div data-login-painel="cadastro" hidden>
            <p><button class="btn sec" type="button" data-login-voltar>&larr; Voltar</button></p>
            <h2>Criar conta</h2>
            <form class="formulario" method="post" action="<?= e(url('entrar')) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="acao" value="cadastro">
                <div class="campo">
                    <label for="dw-cad-nome">Nome completo</label>
                    <input type="text" id="dw-cad-nome" name="nome" required>
                </div>
                <div class="campo">
                    <label for="dw-cad-cpf">CPF</label>
                    <input type="text" id="dw-cad-cpf" name="cpf" inputmode="numeric"
                           placeholder="Somente números" required>
                </div>
                <div class="campo">
                    <label for="dw-cad-endereco">Endereço</label>
                    <textarea id="dw-cad-endereco" name="endereco" rows="2"></textarea>
                </div>
                <div class="campo">
                    <label for="dw-cad-email">E-mail</label>
                    <input type="email" id="dw-cad-email" name="email" required>
                </div>
                <div class="campo">
                    <label for="dw-cad-senha">Senha</label>
                    <input type="password" id="dw-cad-senha" name="senha" minlength="6" required>
                </div>
                <button class="btn" type="submit">Criar conta</button>
            </form>
        </div>
    </aside>
</div>
<?php endif; ?>
