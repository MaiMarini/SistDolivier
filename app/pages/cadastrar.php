<?php
/**
 * Cadastro de cliente: /cadastrar
 * Valida os dados, cria o usuário (papel cliente, senha com password_hash),
 * loga e redireciona. Em erro, mostra via flash e volta ao formulário.
 */

// Já logado não cadastra de novo.
if (usuario_atual() !== null) {
    redirect('');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('cadastrar');
    }

    $nome     = trim($_POST['nome'] ?? '');
    $cpf      = preg_replace('/\D+/', '', $_POST['cpf'] ?? ''); // só dígitos
    $email    = trim($_POST['email'] ?? '');
    $endereco = trim($_POST['endereco'] ?? '');
    $senha    = $_POST['senha'] ?? '';

    $erros = [];
    if (mb_strlen($nome) < 3) {
        $erros[] = 'Informe seu nome (mínimo 3 caracteres).';
    }
    if (strlen($cpf) !== 11) {
        $erros[] = 'O CPF deve ter 11 dígitos.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Informe um e-mail válido.';
    }
    if (strlen($senha) < 6) {
        $erros[] = 'A senha deve ter no mínimo 6 caracteres.';
    }

    // E-mail não pode repetir.
    if (empty($erros)) {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $erros[] = 'Já existe uma conta com este e-mail.';
        }
    }

    if (!empty($erros)) {
        flash('erro', implode(' ', $erros));
        redirect('cadastrar');
    }

    // Cria o usuário.
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $stmt = db()->prepare(
        'INSERT INTO users (nome, cpf, email, senha_hash, endereco, papel, aceita_email)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([$nome, $cpf, $email, $hash, $endereco, 'cliente']);
    $id = (int) db()->lastInsertId();

    // Loga (renova o id de sessão para evitar fixação).
    session_regenerate_id(true);
    $_SESSION['usuario'] = [
        'id'       => $id,
        'nome'     => $nome,
        'email'    => $email,
        'papel'    => 'cliente',
        'is_admin' => false,
    ];

    flash('sucesso', 'Cadastro realizado. Boas-vindas!');
    redirect('');
}

ob_start();
?>
<h1>Criar conta</h1>
<form class="formulario" method="post" action="<?= e(url('cadastrar')) ?>">
    <?= csrf_input() ?>
    <div class="campo">
        <label for="nome">Nome completo</label>
        <input type="text" id="nome" name="nome" required>
    </div>
    <div class="campo">
        <label for="cpf">CPF</label>
        <input type="text" id="cpf" name="cpf" inputmode="numeric"
               placeholder="Somente números" required>
    </div>
    <div class="campo">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required>
    </div>
    <div class="campo">
        <label for="endereco">Endereço</label>
        <textarea id="endereco" name="endereco" rows="2"></textarea>
    </div>
    <div class="campo">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" minlength="6" required>
    </div>
    <button class="btn" type="submit">Cadastrar</button>
</form>
<p class="mt-1">Já tem conta? <a href="<?= e(url('entrar')) ?>">Entrar</a></p>
<?php
view('layout', ['titulo' => 'Criar conta', 'conteudo' => ob_get_clean()]);
