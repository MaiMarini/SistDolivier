<?php
/**
 * Login do administrador: /admin/entrar
 * Mesma tabela "users", mas só aceita papel = admin. Em sucesso, vai para /admin.
 */
$u = usuario_atual();
if ($u !== null && !empty($u['is_admin'])) {
    redirect('admin');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('admin/entrar');
    }

    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = db()->prepare(
        'SELECT id, nome, email, senha_hash, papel FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    // Precisa existir, ser admin e a senha conferir. Mensagem genérica.
    if (!$usuario || $usuario['papel'] !== 'admin'
        || !password_verify($senha, $usuario['senha_hash'])) {
        flash('erro', 'Credenciais inválidas ou sem permissão de administrador.');
        redirect('admin/entrar');
    }

    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'       => (int) $usuario['id'],
        'nome'     => $usuario['nome'],
        'email'    => $usuario['email'],
        'papel'    => 'admin',
        'is_admin' => true,
    ];

    flash('sucesso', 'Bem-vindo(a) ao painel.');
    redirect('admin');
}

ob_start();
?>
<h1>Acesso administrativo</h1>
<form class="formulario" method="post" action="<?= e(url('admin/entrar')) ?>">
    <?= csrf_input() ?>
    <div class="campo">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required>
    </div>
    <div class="campo">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required>
    </div>
    <button class="btn" type="submit">Entrar no painel</button>
</form>
<?php
view('admin_layout', ['titulo' => 'Entrar', 'conteudo' => ob_get_clean()]);
