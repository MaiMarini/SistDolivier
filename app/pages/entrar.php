<?php
/**
 * Login de cliente/admin: /entrar
 * Verifica a senha com password_verify. Admin vai para /admin; cliente, à home.
 */

// Já logado não precisa entrar de novo.
if (usuario_atual() !== null) {
    redirect('');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('entrar');
    }

    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = db()->prepare(
        'SELECT id, nome, email, senha_hash, papel FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    // Mensagem genérica para não revelar se o e-mail existe.
    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        flash('erro', 'E-mail ou senha incorretos.');
        redirect('entrar');
    }

    $is_admin = ($usuario['papel'] === 'admin');

    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'       => (int) $usuario['id'],
        'nome'     => $usuario['nome'],
        'email'    => $usuario['email'],
        'papel'    => $usuario['papel'],
        'is_admin' => $is_admin,
    ];

    flash('sucesso', 'Login efetuado. Olá, ' . $usuario['nome'] . '!');
    redirect($is_admin ? 'admin' : '');
}

ob_start();
?>
<h1>Entrar</h1>
<form class="formulario" method="post" action="<?= e(url('entrar')) ?>">
    <?= csrf_input() ?>
    <div class="campo">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required>
    </div>
    <div class="campo">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required>
    </div>
    <button class="btn" type="submit">Entrar</button>
</form>
<p class="mt-1">Não tem conta? <a href="<?= e(url('cadastrar')) ?>">Criar conta</a></p>
<?php
view('layout', ['titulo' => 'Entrar', 'conteudo' => ob_get_clean()]);
