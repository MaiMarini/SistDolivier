<?php
/**
 * Acesso do cliente em uma única página /entrar, com duas abas:
 *   - "Entrar"      (acao=login)    -> e-mail + senha
 *   - "Criar conta" (acao=cadastro) -> nome, CPF, e-mail, endereço, senha
 *
 * A troca de abas é só visual (app.js). O campo escondido "acao" diz ao PHP qual
 * formulário foi enviado. Em erro, usa-se um flash "aba" para reabrir na aba que
 * falhou. Sem nenhum código de verificação.
 */

// Já logado vai para a home.
if (usuario_atual() !== null) {
    redirect('');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('entrar');
    }

    $acao = $_POST['acao'] ?? '';

    // ----------------------------------------------------------------- CADASTRO
    if ($acao === 'cadastro') {
        $nome     = trim($_POST['nome'] ?? '');
        $cpf      = preg_replace('/\D+/', '', $_POST['cpf'] ?? ''); // só dígitos
        $email    = trim($_POST['email'] ?? '');
        $senha    = $_POST['senha'] ?? '';

        // Endereço: campos separados + o texto completo em users.endereco.
        $end = [
            'cep'         => substr(preg_replace('/\D+/', '', $_POST['cep'] ?? ''), 0, 8),
            'rua'         => mb_substr(trim($_POST['rua'] ?? ''), 0, 150),
            'numero'      => mb_substr(trim($_POST['numero'] ?? ''), 0, 20),
            'complemento' => mb_substr(trim($_POST['complemento'] ?? ''), 0, 100),
            'bairro'      => mb_substr(trim($_POST['bairro'] ?? ''), 0, 100),
            'cidade'      => mb_substr(trim($_POST['cidade'] ?? ''), 0, 100),
            'uf'          => strtoupper(trim($_POST['estado'] ?? '')),
        ];
        $end['uf'] = preg_match('/^[A-Z]{2}$/', $end['uf']) ? $end['uf'] : '';
        $endereco = endereco_formatar($end);

        $erros = [];
        if (mb_strlen($nome) < 3) {
            $erros[] = 'Informe seu nome (mínimo 3 caracteres).';
        }
        if (!cpf_valido($cpf)) {
            $erros[] = 'Informe um CPF válido.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'Informe um e-mail válido.';
        }
        if (strlen($senha) < 6) {
            $erros[] = 'A senha deve ter no mínimo 6 caracteres.';
        }
        if (empty($erros)) {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $erros[] = 'Já existe uma conta com este e-mail.';
            }
            $stmt = db()->prepare('SELECT id FROM users WHERE cpf = ? LIMIT 1');
            $stmt->execute([$cpf]);
            if ($stmt->fetch()) {
                $erros[] = 'Já existe uma conta com este CPF. Se for sua, entre com o e-mail cadastrado.';
            }
        }

        if (!empty($erros)) {
            flash('erro', implode(' ', $erros));
            flash('aba', 'cadastro');
            redirect('entrar');
        }

        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = db()->prepare(
            'INSERT INTO users (nome, cpf, email, senha_hash, cep, rua, numero, complemento,
                                bairro, cidade, uf, endereco, papel, aceita_email)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $nulo = fn ($v) => $v !== '' ? $v : null;
        try {
            $stmt->execute([
                $nome, $cpf, $email, $hash,
                $nulo($end['cep']), $nulo($end['rua']), $nulo($end['numero']), $nulo($end['complemento']),
                $nulo($end['bairro']), $nulo($end['cidade']), $nulo($end['uf']),
                $endereco, 'cliente',
            ]);
        } catch (PDOException $ex) {
            // Índice único (e-mail ou CPF): dois cadastros iguais enviados ao mesmo tempo.
            if ($ex->getCode() !== '23000') {
                throw $ex;
            }
            flash('erro', 'Já existe uma conta com este e-mail ou CPF.');
            flash('aba', 'cadastro');
            redirect('entrar');
        }
        $id = (int) db()->lastInsertId();

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

    // -------------------------------------------------------------------- LOGIN
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = db()->prepare(
        'SELECT id, nome, email, senha_hash, papel FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        flash('erro', 'E-mail ou senha incorretos.');
        flash('aba', 'login');
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

// --- Exibição (GET) ----------------------------------------------------------
// Aba ativa: a que falhou (flash) ou "login" por padrão.
$aba = flash_consumir('aba');
$aba = ($aba === 'cadastro') ? 'cadastro' : 'login';

ob_start();
?>
<div class="formulario">
    <div class="abas">
        <button type="button" class="aba<?= $aba === 'login' ? ' ativa' : '' ?>"
                data-aba="login">Entrar</button>
        <button type="button" class="aba<?= $aba === 'cadastro' ? ' ativa' : '' ?>"
                data-aba="cadastro">Criar conta</button>
    </div>

    <!-- Aba: Entrar -->
    <form method="post" action="<?= e(url('entrar')) ?>"
          class="painel<?= $aba === 'login' ? ' ativo' : '' ?>" data-painel="login">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="login">
        <div class="campo">
            <label for="login-email">E-mail</label>
            <input type="email" id="login-email" name="email" required>
        </div>
        <div class="campo">
            <label for="login-senha">Senha</label>
            <input type="password" id="login-senha" name="senha" required>
        </div>
        <button class="btn" type="submit">Entrar</button>
    </form>

    <!-- Aba: Criar conta -->
    <form method="post" action="<?= e(url('entrar')) ?>"
          class="painel<?= $aba === 'cadastro' ? ' ativo' : '' ?>" data-painel="cadastro">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="cadastro">
        <div class="campo">
            <label for="cad-nome">Nome completo</label>
            <input type="text" id="cad-nome" name="nome" required minlength="3">
        </div>
        <div class="campo">
            <label for="cad-cpf">CPF</label>
            <input type="text" id="cad-cpf" name="cpf" inputmode="numeric"
                   placeholder="Somente números" required>
        </div>
        <div class="campo">
            <label for="cad-email">E-mail</label>
            <input type="email" id="cad-email" name="email" required>
        </div>

        <div class="campo">
            <label for="cad-cep">CEP</label>
            <input type="text" id="cad-cep" name="cep" inputmode="numeric"
                   placeholder="Somente números" data-cep>
            <small>Preencha o CEP para completar o endereço automaticamente.</small>
        </div>
        <div class="campo">
            <label for="cad-rua">Rua</label>
            <input type="text" id="cad-rua" name="rua" data-cep-rua>
        </div>
        <div class="campo">
            <label for="cad-numero">Número</label>
            <input type="text" id="cad-numero" name="numero">
        </div>
        <div class="campo">
            <label for="cad-complemento">Complemento (opcional)</label>
            <input type="text" id="cad-complemento" name="complemento">
        </div>
        <div class="campo">
            <label for="cad-bairro">Bairro</label>
            <input type="text" id="cad-bairro" name="bairro" data-cep-bairro>
        </div>
        <div class="campo">
            <label for="cad-cidade">Cidade</label>
            <input type="text" id="cad-cidade" name="cidade" data-cep-cidade>
        </div>
        <div class="campo">
            <label for="cad-estado">Estado (UF)</label>
            <input type="text" id="cad-estado" name="estado" maxlength="2"
                   placeholder="Ex.: SP" data-cep-uf>
        </div>

        <div class="campo">
            <label for="cad-senha">Senha</label>
            <input type="password" id="cad-senha" name="senha" minlength="6" required>
        </div>
        <button class="btn" type="submit">Criar conta</button>
    </form>
</div>
<?php
view('layout', ['titulo' => 'Entrar', 'conteudo' => ob_get_clean()]);
