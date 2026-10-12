<?php
/**
 * Recuperação de senha (passo 4.3): /recuperar-senha
 *   POST acao=pedir  (painel lateral, por AJAX) -> manda o link por e-mail.
 *                    A resposta é sempre a mesma, exista ou não a conta, e o
 *                    e-mail sai depois da resposta (nem o tempo denuncia a conta).
 *                    Limite: 3 pedidos por e-mail e 10 por IP a cada hora.
 *   GET  ?token=...  -> tela "Criar senha nova" (ou "Este link não vale mais").
 *   POST acao=nova   -> grava a senha, entra na conta e encerra as outras sessões.
 *   GET sem token    -> home com o painel lateral aberto em "Esqueceu sua senha?".
 * O link vale 1 hora e uma vez só; no banco fica só o hash (SHA-256) dele.
 */

const RECUPERAR_MAX_EMAIL = 3;
const RECUPERAR_MAX_IP    = 10;
const RECUPERAR_AVISO     = 'Se houver uma conta com esse e-mail, enviamos um link para criar uma senha nova. '
                          . 'Ele vale por 1 hora. Confira também a caixa de spam.';

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';

/** Resposta do pedido do link: JSON no painel; sem JavaScript, aviso na home. */
function _recuperar_responder(bool $ok, string $mensagem): void
{
    global $ajax;
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'mensagem' => $mensagem, 'fica' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash($ok ? 'sucesso' : 'erro', $mensagem);
    flash('abrir_login', 'recuperar');
    redirect('');
}

/** Pedido válido para o token (ainda não usado e dentro da hora), ou null. */
function _recuperar_pedido(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    try {
        $st = db()->prepare('SELECT r.id, r.user_id, u.email, u.nome, u.papel
                               FROM senha_recuperacao r JOIN users u ON u.id = r.user_id
                              WHERE r.token_hash = ? AND r.usado_em IS NULL AND r.expira_em > NOW()');
        $st->execute([hash('sha256', $token)]);
        return $st->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/** "co***@gmail.com" para a pessoa confirmar a conta sem expor o endereço inteiro. */
function _recuperar_mascara(string $email): string
{
    [$u, $d] = explode('@', $email, 2) + [1 => ''];
    return mb_substr($u, 0, 2) . '***@' . $d;
}

// =============================================================================
// POST
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    // --- Pedir o link -------------------------------------------------------------------
    if ($acao === 'pedir') {
        if (!csrf_validar()) {
            _recuperar_responder(false, 'Sua sessão expirou. Recarregue a página e tente de novo.');
        }
        $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            _recuperar_responder(false, 'Informe um e-mail válido.');
        }
        $ip = ip_cliente();
        $chave = mb_strtolower($email);
        try {
            $st = db()->prepare('SELECT
                    SUM(email = ? AND criado_em > NOW() - INTERVAL 1 HOUR),
                    SUM(ip = ? AND criado_em > NOW() - INTERVAL 1 HOUR)
                  FROM senha_recuperacao WHERE (email = ? OR ip = ?) AND criado_em > NOW() - INTERVAL 1 HOUR');
            $st->execute([$chave, $ip, $chave, $ip]);
            [$porEmail, $porIp] = array_map('intval', $st->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        } catch (PDOException $e) {
            _recuperar_responder(false, 'A recuperação de senha ainda não está disponível. Fale com a loja pelo WhatsApp.');
        }
        if ($porEmail >= RECUPERAR_MAX_EMAIL || $porIp >= RECUPERAR_MAX_IP) {
            _recuperar_responder(false, 'Muitos pedidos seguidos. Tente de novo mais tarde.');
        }

        $st = db()->prepare('SELECT id, nome, email FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
        $conta = $st->fetch();

        $token = $conta ? bin2hex(random_bytes(32)) : null;
        db()->prepare('INSERT INTO senha_recuperacao (user_id, email, ip, token_hash, criado_em, expira_em)
                       VALUES (?, ?, ?, ?, NOW(), IF(? IS NULL, NULL, NOW() + INTERVAL 1 HOUR))')
            ->execute([$conta['id'] ?? null, $chave, mb_substr($ip, 0, 45), $token ? hash('sha256', $token) : null, $token]);
        if ($conta) {
            // Só o link mais novo vale.
            db()->prepare('UPDATE senha_recuperacao SET usado_em = NOW()
                            WHERE user_id = ? AND usado_em IS NULL AND id <> ?')
                ->execute([(int) $conta['id'], (int) db()->lastInsertId()]);
            $link = url('recuperar-senha?token=' . $token);
            $nome = trim(explode(' ', (string) $conta['nome'])[0]);
            $para = (string) $conta['email'];
            email_depois(function () use ($para, $nome, $link): void {
                $corpo = '<p>Olá' . ($nome !== '' ? ', ' . e($nome) : '') . '!</p>'
                    . '<p>Recebemos um pedido para criar uma senha nova na sua conta da loja.</p>'
                    . '<p style="margin:20px 0;"><a href="' . e($link) . '" style="background:#BC5B38;color:#ffffff;'
                    . 'text-decoration:none;padding:12px 20px;border-radius:8px;display:inline-block;font-weight:bold;">Criar senha nova</a></p>'
                    . '<p>O link vale por <strong>1 hora</strong> e só funciona uma vez.</p>'
                    . '<p style="color:#6e5848;font-size:13px;">Se não foi você que pediu, ignore este e-mail: a sua senha continua a mesma.</p>';
                $r = email_enviar($para, 'Criar senha nova — ' . cfg('site_nome', 'Loja'), email_layout('Criar senha nova', $corpo));
                if (!$r['ok']) {
                    error_log('[recuperar senha] e-mail não enviado: ' . $r['erro']);
                }
            });
        }
        if (random_int(1, 100) === 1) {
            db()->exec('DELETE FROM senha_recuperacao WHERE criado_em < NOW() - INTERVAL 2 DAY');
        }
        _recuperar_responder(true, RECUPERAR_AVISO);
    }

    // --- Gravar a senha nova ------------------------------------------------------------
    if ($acao === 'nova') {
        $token = (string) ($_POST['token'] ?? '');
        if (!csrf_validar()) {
            flash('erro', 'Sua sessão expirou. Tente de novo.');
            redirect('recuperar-senha?token=' . rawurlencode($token));
        }
        $pedido = _recuperar_pedido($token);
        if ($pedido === null) {
            redirect('recuperar-senha?token=' . rawurlencode($token));   // mostra "Este link não vale mais"
        }
        $nova = (string) ($_POST['senha'] ?? '');
        $repetir = (string) ($_POST['senha_repetir'] ?? '');
        if (strlen($nova) < 6) {
            flash('erro', 'A senha nova precisa ter pelo menos 6 caracteres.');
            redirect('recuperar-senha?token=' . $token);
        }
        if ($nova !== $repetir) {
            flash('erro', 'As duas senhas não são iguais. Digite de novo.');
            redirect('recuperar-senha?token=' . $token);
        }
        // Marca o link como usado antes (só um envio consegue) e grava a senha.
        $st = db()->prepare('UPDATE senha_recuperacao SET usado_em = NOW() WHERE id = ? AND usado_em IS NULL');
        $st->execute([(int) $pedido['id']]);
        if ($st->rowCount() !== 1) {
            redirect('recuperar-senha?token=' . $token);
        }
        $id = (int) $pedido['user_id'];
        db()->prepare('UPDATE senha_recuperacao SET usado_em = NOW() WHERE user_id = ? AND usado_em IS NULL')->execute([$id]);
        senha_gravar($id, $nova);
        login_sucesso((string) $pedido['email']);   // zera o bloqueio por tentativas desse e-mail

        // Entra na conta (sessão nova). As outras sessões da conta saem na próxima página.
        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id'       => $id,
            'nome'     => $pedido['nome'],
            'email'    => $pedido['email'],
            'papel'    => $pedido['papel'],
            'is_admin' => $pedido['papel'] === 'admin',
        ];
        $v = senha_versao($id);
        if (is_int($v)) {
            $_SESSION['usuario']['senha_versao'] = $v;
        }
        flash('sucesso', 'Senha nova criada. Você já entrou na sua conta.');
        redirect($pedido['papel'] === 'admin' ? 'admin' : '');
    }
    redirect('');
}

// =============================================================================
// GET
// =============================================================================
$token = (string) ($_GET['token'] ?? '');
if ($token === '') {
    flash('abrir_login', 'recuperar');
    redirect('');
}
$pedido = _recuperar_pedido($token);

ob_start();
?>
<div class="rs">
    <?php if ($pedido === null): ?>
        <h1>Este link não vale mais</h1>
        <p>O link para criar uma senha nova vale por 1 hora e só pode ser usado uma vez. Se você pediu mais de um,
            só o último funciona.</p>
        <p><a class="btn" href="<?= e(url('recuperar-senha')) ?>">Pedir um link novo</a></p>
    <?php else: ?>
        <h1>Criar senha nova</h1>
        <p class="rs-conta">Conta: <strong><?= e(_recuperar_mascara((string) $pedido['email'])) ?></strong></p>
        <?php if (($erro = flash_consumir('erro')) !== null): ?>
            <p class="rs-erro" role="alert"><?= e($erro) ?></p>
        <?php endif; ?>
        <form class="formulario" method="post" action="<?= e(url('recuperar-senha')) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="acao" value="nova">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="campo">
                <label for="rs-senha">Senha nova</label>
                <input type="password" id="rs-senha" name="senha" minlength="6" required autocomplete="new-password" aria-describedby="rs-ajuda">
                <small id="rs-ajuda">Pelo menos 6 caracteres.</small>
            </div>
            <div class="campo">
                <label for="rs-repetir">Repita a senha nova</label>
                <input type="password" id="rs-repetir" name="senha_repetir" minlength="6" required autocomplete="new-password">
            </div>
            <button class="btn" type="submit">Salvar senha nova</button>
        </form>
    <?php endif; ?>
</div>
<?php
view('layout', ['titulo' => 'Criar senha nova', 'conteudo' => ob_get_clean()]);
