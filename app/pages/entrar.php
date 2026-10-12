<?php
/**
 * /entrar recebe o login e o cadastro (painel lateral do header.php), com duas ações:
 *   - "Entrar"      (acao=login)    -> e-mail + senha
 *   - "Criar conta" (acao=cadastro) -> nome, CPF, e-mail, endereço, senha
 *
 * O campo escondido "acao" diz ao PHP qual formulário foi enviado. Abrir /entrar
 * direto (GET) leva para a home com o painel lateral aberto: a página antiga saiu.
 *
 * O painel lateral (header.php) envia para cá por AJAX: aí a resposta é JSON
 * ({ok, mensagem} no erro; {ok, redirect} no sucesso) e o cliente não sai da
 * página em que estava.
 */

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';

/** Erro: no painel, devolve a mensagem; na página, volta para a aba que falhou. */
function _entrar_falha(string $mensagem, string $aba): void
{
    global $ajax;
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('erro', $mensagem);
    flash('aba', $aba);
    redirect('entrar');
}

/** Sucesso: no painel, recarrega a página atual (ou vai para $destino); na página, redireciona. */
function _entrar_ok(string $mensagem, ?string $destino): void
{
    global $ajax;
    flash('sucesso', $mensagem);
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'redirect' => $destino !== null ? url($destino) : null]);
        exit;
    }
    redirect($destino ?? '');
}

// Já logado vai para a home.
if (usuario_atual() !== null) {
    if ($ajax) {
        _entrar_ok('Você já está conectado.', null);
    }
    redirect('');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        _entrar_falha('Sua sessão expirou. Recarregue a página e tente novamente.', ($_POST['acao'] ?? '') === 'cadastro' ? 'cadastro' : 'login');
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
            _entrar_falha(implode(' ', $erros), 'cadastro');
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
            _entrar_falha('Já existe uma conta com este e-mail ou CPF.', 'cadastro');
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
        if (is_int($v = senha_versao($id))) {
            $_SESSION['usuario']['senha_versao'] = $v;
        }

        $destino = destino_pos_login(false);   // ex.: voltar ao checkout
        _entrar_ok('Cadastro realizado. Boas-vindas!', $destino ?? ($ajax ? null : ''));
    }

    // -------------------------------------------------------------------- LOGIN
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $ip    = ip_cliente();

    // 5 falhas do e-mail ou 20 do IP em 15 min: recusa por 15 min. A mensagem é a
    // mesma exista ou não a conta.
    if (login_bloqueado($email, $ip)) {
        _entrar_falha('Muitas tentativas. Tente de novo em alguns minutos.', 'login');
    }

    $stmt = db()->prepare(
        'SELECT id, nome, email, senha_hash, papel FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
        login_falha($email, $ip);
        _entrar_falha('E-mail ou senha incorretos.', 'login');
    }
    login_sucesso($email);

    // Hash antigo (algoritmo ou custo mudou): regrava com o padrão atual.
    if (password_needs_rehash($usuario['senha_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET senha_hash = ? WHERE id = ?')
            ->execute([password_hash($senha, PASSWORD_DEFAULT), (int) $usuario['id']]);
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
    // Versão da senha desta sessão: se a senha mudar depois, esta sessão sai da conta.
    if (is_int($v = senha_versao((int) $usuario['id']))) {
        $_SESSION['usuario']['senha_versao'] = $v;
    }

    // Pelo painel, o cliente continua na página em que estava; o admin vai ao painel.
    // Volta à página que exigiu o login (ex.: checkout); sem ela, o cliente fica
    // na página atual (painel) ou vai à home (página /entrar); o admin vai ao painel.
    $destino = destino_pos_login($is_admin);
    _entrar_ok('Login efetuado. Olá, ' . $usuario['nome'] . '!', $destino ?? ($ajax ? null : ''));
}

// --- GET: a página antiga não é mais usada --------------------------------------
// Vai para a home com o painel lateral de login aberto (na aba "Criar conta" se o
// cadastro falhou num envio sem JavaScript; a mensagem de erro já está no flash).
$aba = flash_consumir('aba');
flash('abrir_login', $aba === 'cadastro' ? 'cadastro' : '1');
redirect('');
