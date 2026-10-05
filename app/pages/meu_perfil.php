<?php
/**
 * "Meu perfil": o cliente vê e edita os próprios dados, em três abas:
 *   - "Meus dados" (acao=dados)    -> nome, e-mail, telefone, aceita e-mails
 *   - "Endereço"   (acao=endereco) -> campos separados (CEP preenche via ViaCEP)
 *   - "Senha"      (acao=senha)    -> senha atual + nova
 * Trocar o e-mail (login) exige a senha atual. CPF só é exibido.
 * Rota: /meu-perfil
 */
exigir_login();

$usuario = usuario_atual();
$id      = (int) $usuario['id'];

$stmt = db()->prepare(
    'SELECT nome, cpf, email, senha_hash, telefone, cep, rua, numero, complemento,
            bairro, cidade, uf, endereco, aceita_email
       FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$id]);
$dados = $stmt->fetch();
if (!$dados) {
    redirect('sair');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validar()) {
        flash('erro', 'Sua sessão expirou. Tente novamente.');
        redirect('meu-perfil');
    }

    $acao = $_POST['acao'] ?? '';
    $abas = ['dados', 'endereco', 'senha'];
    $acao = in_array($acao, $abas, true) ? $acao : 'dados';
    flash('aba', $acao);

    // ------------------------------------------------------------ MEUS DADOS
    if ($acao === 'dados') {
        $nome     = mb_substr(trim($_POST['nome'] ?? ''), 0, 150);
        $email    = trim($_POST['email'] ?? '');
        $telefone = substr(preg_replace('/\D+/', '', $_POST['telefone'] ?? ''), 0, 11);
        $aceita   = isset($_POST['aceita_email']) ? 1 : 0;

        $erros = [];
        if (mb_strlen($nome) < 3) {
            $erros[] = 'Informe seu nome (mínimo 3 caracteres).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'Informe um e-mail válido.';
        }
        if ($telefone !== '' && strlen($telefone) < 10) {
            $erros[] = 'Informe o telefone com DDD.';
        }
        $trocou_email = mb_strtolower($email) !== mb_strtolower($dados['email']);
        if (empty($erros) && $trocou_email) {
            if (!password_verify($_POST['senha_atual'] ?? '', $dados['senha_hash'])) {
                $erros[] = 'Para trocar o e-mail, informe sua senha atual corretamente.';
            } else {
                $st = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
                $st->execute([$email, $id]);
                if ($st->fetch()) {
                    $erros[] = 'Já existe uma conta com este e-mail.';
                }
            }
        }
        if (!empty($erros)) {
            flash('erro', implode(' ', $erros));
            redirect('meu-perfil');
        }

        db()->prepare('UPDATE users SET nome = ?, email = ?, telefone = ?, aceita_email = ? WHERE id = ?')
            ->execute([$nome, $email, $telefone !== '' ? $telefone : null, $aceita, $id]);
        $_SESSION['usuario']['nome']  = $nome;
        $_SESSION['usuario']['email'] = $email;

        flash('sucesso', 'Seus dados foram atualizados.');
        redirect('meu-perfil');
    }

    // --------------------------------------------------------------- ENDEREÇO
    if ($acao === 'endereco') {
        $end = [
            'cep'         => substr(preg_replace('/\D+/', '', $_POST['cep'] ?? ''), 0, 8),
            'rua'         => mb_substr(trim($_POST['rua'] ?? ''), 0, 150),
            'numero'      => mb_substr(trim($_POST['numero'] ?? ''), 0, 20),
            'complemento' => mb_substr(trim($_POST['complemento'] ?? ''), 0, 100),
            'bairro'      => mb_substr(trim($_POST['bairro'] ?? ''), 0, 100),
            'cidade'      => mb_substr(trim($_POST['cidade'] ?? ''), 0, 100),
            'uf'          => strtoupper(trim($_POST['uf'] ?? '')),
        ];
        if (strlen($end['cep']) !== 8) {
            flash('erro', 'Informe um CEP válido (8 números).');
            redirect('meu-perfil');
        }
        if ($end['rua'] === '' || $end['numero'] === '' || $end['cidade'] === ''
            || !preg_match('/^[A-Z]{2}$/', $end['uf'])) {
            flash('erro', 'Preencha rua, número, cidade e UF.');
            redirect('meu-perfil');
        }

        db()->prepare(
            'UPDATE users SET cep = ?, rua = ?, numero = ?, complemento = ?, bairro = ?,
                              cidade = ?, uf = ?, endereco = ?
              WHERE id = ?'
        )->execute([
            $end['cep'], $end['rua'], $end['numero'],
            $end['complemento'] !== '' ? $end['complemento'] : null,
            $end['bairro'] !== '' ? $end['bairro'] : null,
            $end['cidade'], $end['uf'], endereco_formatar($end), $id,
        ]);
        // A estimativa de frete da sessão pode ter sido feita para o endereço antigo.
        unset($_SESSION['_frete_estimativas']);

        flash('sucesso', 'Endereço atualizado.');
        redirect('meu-perfil');
    }

    // ------------------------------------------------------------------ SENHA
    $atual     = $_POST['senha_atual'] ?? '';
    $nova      = $_POST['senha_nova'] ?? '';
    $confirmar = $_POST['senha_confirmar'] ?? '';
    if (!password_verify($atual, $dados['senha_hash'])) {
        flash('erro', 'A senha atual está incorreta.');
        redirect('meu-perfil');
    }
    if (strlen($nova) < 6) {
        flash('erro', 'A nova senha deve ter no mínimo 6 caracteres.');
        redirect('meu-perfil');
    }
    if ($nova !== $confirmar) {
        flash('erro', 'A confirmação não confere com a nova senha.');
        redirect('meu-perfil');
    }
    db()->prepare('UPDATE users SET senha_hash = ? WHERE id = ?')
        ->execute([password_hash($nova, PASSWORD_DEFAULT), $id]);
    session_regenerate_id(true);

    flash('sucesso', 'Senha alterada.');
    redirect('meu-perfil');
}

// --- Exibição (GET) ----------------------------------------------------------
$aba = flash_consumir('aba');
$aba = in_array($aba, ['dados', 'endereco', 'senha'], true) ? $aba : 'dados';

$cpf = preg_replace('/\D+/', '', (string) $dados['cpf']);
$cpf = strlen($cpf) === 11
    ? substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9)
    : $cpf;
$cep = (string) $dados['cep'];
$cep = strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : $cep;

ob_start();
?>
<h1>Meu perfil</h1>

<div class="formulario perfil">
    <div class="abas">
        <button type="button" class="aba<?= $aba === 'dados' ? ' ativa' : '' ?>" data-aba="dados">Meus dados</button>
        <button type="button" class="aba<?= $aba === 'endereco' ? ' ativa' : '' ?>" data-aba="endereco">Endereço</button>
        <button type="button" class="aba<?= $aba === 'senha' ? ' ativa' : '' ?>" data-aba="senha">Senha</button>
    </div>

    <!-- Aba: Meus dados -->
    <form method="post" action="<?= e(url('meu-perfil')) ?>"
          class="painel<?= $aba === 'dados' ? ' ativo' : '' ?>" data-painel="dados">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="dados">
        <div class="campo">
            <label for="perfil-nome">Nome completo</label>
            <input type="text" id="perfil-nome" name="nome" value="<?= e($dados['nome']) ?>" required>
        </div>
        <div class="campo">
            <label for="perfil-cpf">CPF</label>
            <input type="text" id="perfil-cpf" value="<?= e($cpf) ?>" readonly>
            <small>Para corrigir o CPF, fale com a gente pelo WhatsApp.</small>
        </div>
        <div class="campo">
            <label for="perfil-email">E-mail</label>
            <input type="email" id="perfil-email" name="email" value="<?= e($dados['email']) ?>" required
                   data-perfil-email data-original="<?= e($dados['email']) ?>">
        </div>
        <div class="campo" data-perfil-senha-email hidden>
            <label for="perfil-senha-email">Senha atual</label>
            <input type="password" id="perfil-senha-email" name="senha_atual" autocomplete="current-password">
            <small>Como o e-mail é usado para entrar na conta, confirme sua senha para trocá-lo.</small>
        </div>
        <div class="campo">
            <label for="perfil-telefone">Telefone / WhatsApp</label>
            <input type="tel" id="perfil-telefone" name="telefone" inputmode="numeric"
                   value="<?= e((string) $dados['telefone']) ?>" placeholder="(11) 91234-5678" data-mask-tel>
        </div>
        <div class="campo campo-inline">
            <input type="checkbox" id="perfil-aceita" name="aceita_email" value="1"
                   <?= (int) $dados['aceita_email'] === 1 ? 'checked' : '' ?>>
            <label for="perfil-aceita" style="font-weight:400;">Quero receber novidades e promoções por e-mail</label>
        </div>
        <button class="btn" type="submit">Salvar dados</button>
    </form>

    <!-- Aba: Endereço -->
    <form method="post" action="<?= e(url('meu-perfil')) ?>"
          class="painel<?= $aba === 'endereco' ? ' ativo' : '' ?>" data-painel="endereco">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="endereco">
        <?php if ((string) $dados['rua'] === '' && (string) $dados['endereco'] !== ''): ?>
            <p><small>Endereço do seu cadastro: <strong><?= e($dados['endereco']) ?></strong>.
               Confira e preencha os campos abaixo.</small></p>
        <?php endif; ?>
        <div class="endereco-grid">
            <div class="campo campo-largo">
                <label for="perfil-cep">CEP</label>
                <input type="text" id="perfil-cep" name="cep" value="<?= e($cep) ?>" inputmode="numeric"
                       maxlength="9" placeholder="00000-000" style="max-width:12rem;" required data-cep>
            </div>
            <div class="campo campo-largo">
                <label for="perfil-rua">Rua / avenida</label>
                <input type="text" id="perfil-rua" name="rua" value="<?= e((string) $dados['rua']) ?>" required data-cep-rua>
            </div>
            <div class="campo campo-largo">
                <label for="perfil-bairro">Bairro</label>
                <input type="text" id="perfil-bairro" name="bairro" value="<?= e((string) $dados['bairro']) ?>" data-cep-bairro>
            </div>
            <div class="campo">
                <label for="perfil-numero">Número</label>
                <input type="text" id="perfil-numero" name="numero" value="<?= e((string) $dados['numero']) ?>" required>
            </div>
            <div class="campo">
                <label for="perfil-complemento">Complemento</label>
                <input type="text" id="perfil-complemento" name="complemento" value="<?= e((string) $dados['complemento']) ?>">
            </div>
            <div class="campo">
                <label for="perfil-uf">UF</label>
                <input type="text" id="perfil-uf" name="uf" value="<?= e((string) $dados['uf']) ?>" maxlength="2"
                       placeholder="SP" style="text-transform:uppercase;" required data-cep-uf>
            </div>
            <div class="campo">
                <label for="perfil-cidade">Cidade</label>
                <input type="text" id="perfil-cidade" name="cidade" value="<?= e((string) $dados['cidade']) ?>" required data-cep-cidade>
            </div>
        </div>
        <p><small>Este endereço é usado para calcular o frete e já vem preenchido no checkout.</small></p>
        <button class="btn" type="submit">Salvar endereço</button>
    </form>

    <!-- Aba: Senha -->
    <form method="post" action="<?= e(url('meu-perfil')) ?>"
          class="painel<?= $aba === 'senha' ? ' ativo' : '' ?>" data-painel="senha">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="senha">
        <div class="campo">
            <label for="perfil-senha-atual">Senha atual</label>
            <input type="password" id="perfil-senha-atual" name="senha_atual" autocomplete="current-password" required>
        </div>
        <div class="campo">
            <label for="perfil-senha-nova">Nova senha</label>
            <input type="password" id="perfil-senha-nova" name="senha_nova" minlength="6"
                   autocomplete="new-password" required>
            <small>Mínimo de 6 caracteres.</small>
        </div>
        <div class="campo">
            <label for="perfil-senha-confirmar">Confirme a nova senha</label>
            <input type="password" id="perfil-senha-confirmar" name="senha_confirmar" minlength="6"
                   autocomplete="new-password" required>
        </div>
        <button class="btn" type="submit">Alterar senha</button>
    </form>
</div>

<script>
(function () {
    // Pede a senha atual só quando o e-mail for alterado.
    var email = document.querySelector('[data-perfil-email]');
    var bloco = document.querySelector('[data-perfil-senha-email]');
    if (!email || !bloco) { return; }
    var original = (email.getAttribute('data-original') || '').toLowerCase();
    var senha = bloco.querySelector('input');
    function aplicar() {
        var mudou = email.value.trim().toLowerCase() !== original;
        bloco.hidden = !mudou;
        senha.required = mudou;
    }
    email.addEventListener('input', aplicar);
    aplicar();
})();
</script>
<?php
view('layout', ['titulo' => 'Meu perfil', 'conteudo' => ob_get_clean()]);
