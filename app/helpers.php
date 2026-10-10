<?php
/**
 * Funções utilitárias da aplicação (carregadas pelo bootstrap.php).
 * Comentários e textos em português; compatível com PHP 7.4+.
 */

// Biblioteca de upload/otimização de imagens (GD).
require_once __DIR__ . '/lib/imagem.php';

// Cálculo de frete por distância (valor final + distância plugável).
require_once __DIR__ . '/lib/frete.php';
require_once __DIR__ . '/lib/mercadopago.php';
require_once __DIR__ . '/lib/pagamento.php';
require_once __DIR__ . '/lib/pedido_status.php';
require_once __DIR__ . '/lib/nutricao.php';
require_once __DIR__ . '/lib/categorias.php';

// =============================================================================
// Acesso ao banco
// =============================================================================

/** Retorna a conexão PDO aberta no bootstrap. */
function db(): PDO
{
    return $GLOBALS['pdo'];
}

// =============================================================================
// Saída segura e configurações
// =============================================================================

/** Escapa uma string para exibição segura em HTML. */
function e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

/**
 * Lê uma configuração da loja (tabela settings), com valor padrão opcional.
 * Ex.: cfg('cor_primaria', '#000000').
 */
function cfg(string $chave, $padrao = null)
{
    if (isset($GLOBALS['settings'][$chave]) && $GLOBALS['settings'][$chave] !== '') {
        return $GLOBALS['settings'][$chave];
    }
    return $padrao;
}

// =============================================================================
// URLs e redirecionamento
// =============================================================================

/**
 * Monta uma URL absoluta a partir da base_url do config.
 * Ex.: url('produto/vela') -> https://loja.com/produto/vela
 */
function url(string $caminho = ''): string
{
    $base = rtrim($GLOBALS['config']['base_url'] ?? '', '/');
    $caminho = ltrim($caminho, '/');
    return $caminho === '' ? $base . '/' : $base . '/' . $caminho;
}

/**
 * URL de um arquivo estático com "cache-busting": acrescenta ?v=<mtime> para o
 * navegador buscar a versão nova sempre que o arquivo mudar (ex.: CSS/JS).
 */
function asset(string $caminho): string
{
    $rel  = ltrim($caminho, '/');
    $full = ROOT_PATH . '/' . $rel;
    $u    = url($rel);
    if (is_file($full)) {
        $u .= (strpos($u, '?') !== false ? '&' : '?') . 'v=' . filemtime($full);
    }
    return $u;
}

/** Redireciona para uma URL (relativa à base ou absoluta) e encerra. */
function redirect(string $destino): void
{
    // Se não for absoluta (http...), monta a partir da base_url.
    if (!preg_match('#^https?://#i', $destino)) {
        $destino = url($destino);
    }
    header('Location: ' . $destino);
    exit;
}

// =============================================================================
// CPF
// =============================================================================

/** CPF (só dígitos) com 11 números e dígitos verificadores corretos. */
function cpf_valido(string $cpf): bool
{
    if (!preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false; // tamanho errado ou todos iguais (111.111.111-11)
    }
    for ($t = 9; $t < 11; $t++) {
        $soma = 0;
        for ($i = 0; $i < $t; $i++) {
            $soma += (int) $cpf[$i] * (($t + 1) - $i);
        }
        $dv = ((10 * $soma) % 11) % 10;
        if ((int) $cpf[$t] !== $dv) {
            return false;
        }
    }
    return true;
}

// =============================================================================
// Endereço
// =============================================================================

/**
 * Texto completo de um endereço separado (chaves: cep, rua, numero, complemento,
 * bairro, cidade, uf): "Rua X, 12 - Apto 3 - Centro - Cidade/UF - CEP 00000-000".
 */
function endereco_formatar(array $e): string
{
    $g = fn ($k) => trim((string) ($e[$k] ?? ''));
    $cep = preg_replace('/\D+/', '', $g('cep'));
    $partes = [];
    $partes[] = $g('rua') . ($g('numero') !== '' ? ', ' . $g('numero') : '');
    $partes[] = $g('complemento');
    $partes[] = $g('bairro');
    $partes[] = trim($g('cidade') . ($g('uf') !== '' ? '/' . $g('uf') : ''), '/');
    $partes[] = strlen($cep) === 8 ? 'CEP ' . substr($cep, 0, 5) . '-' . substr($cep, 5) : '';
    return implode(' - ', array_filter($partes, fn ($p) => trim($p, ' ,') !== ''));
}

// =============================================================================
// Dinheiro (sempre armazenado em CENTAVOS)
// =============================================================================

/** Converte centavos (inteiro) para "R$ 0,00". */
function money(int $centavos): string
{
    return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
}

/**
 * Converte um valor digitado em reais (ex.: "1.234,56" ou "35.00" ou "35,9")
 * para centavos (inteiro). Aceita ponto e/ou vírgula.
 */
function reais_para_centavos(string $valor): int
{
    $valor = preg_replace('/[^0-9,.\-]/', '', trim($valor));
    if ($valor === '' || $valor === '-') {
        return 0;
    }
    if (strpos($valor, ',') !== false && strpos($valor, '.') !== false) {
        // Tem os dois: ponto = milhar, vírgula = decimal.
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    } elseif (strpos($valor, ',') !== false) {
        // Só vírgula: decimal brasileiro.
        $valor = str_replace(',', '.', $valor);
    }
    return (int) round(((float) $valor) * 100);
}

/** Formata centavos para preencher um input em reais (ex.: 3590 -> "35,90"). */
function centavos_para_input(int $centavos): string
{
    return number_format($centavos / 100, 2, ',', '');
}

/**
 * Texto de parcelamento a partir do total (centavos), conforme as settings:
 *   parcelamento_limite_centavos -> valor mínimo do pedido para poder parcelar
 *   parcela_minima_centavos      -> cada parcela não pode ficar abaixo disso
 *   parcelamento_max             -> teto de parcelas (0/1 = sem teto)
 * O nº de parcelas é o maior possível mantendo a parcela >= mínimo (e <= teto).
 * Abaixo do limite -> "à vista".
 */
/** Número máximo de parcelas para um total (1 = só à vista). Regras em Configurações. */
function parcelamento_parcelas(int $total_centavos): int
{
    $limite = (int) cfg('parcelamento_limite_centavos', 0);
    $minima = (int) cfg('parcela_minima_centavos', 0);
    $max    = (int) cfg('parcelamento_max', 0);

    if ($total_centavos <= 0 || $total_centavos < $limite) {
        return 1;
    }

    // Parcelas: cada uma >= parcela mínima (se definida); senão, usa o teto.
    if ($minima > 0) {
        $parcelas = (int) floor($total_centavos / $minima);
    } else {
        $parcelas = $max >= 2 ? $max : 1;
    }
    if ($max >= 2) {
        $parcelas = min($parcelas, $max); // aplica teto só quando >= 2
    }
    return max(1, $parcelas);
}

function parcelamento_texto(int $total_centavos): string
{
    $parcelas = parcelamento_parcelas($total_centavos);
    if ($parcelas < 2) {
        return 'à vista';
    }

    // Arredonda para BAIXO (ex.: R$ 154,00 / 3 = R$ 51,33).
    $valor_parcela = (int) floor($total_centavos / $parcelas);
    return 'em até ' . $parcelas . 'x de ' . money($valor_parcela) . ' sem juros';
}

// =============================================================================
// CSRF
// =============================================================================

/** Retorna o token CSRF da sessão, criando-o se necessário. */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Retorna o campo hidden com o token CSRF para usar em formulários. */
function csrf_input(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Valida o token CSRF enviado em $_POST['_csrf'].
 * Retorna true se válido. Use em todo POST.
 */
function csrf_validar(): bool
{
    $enviado = $_POST['_csrf'] ?? '';
    $sessao  = $_SESSION['_csrf'] ?? '';
    return $enviado !== '' && $sessao !== '' && hash_equals($sessao, $enviado);
}

// =============================================================================
// Mensagens flash (mostradas na próxima requisição)
// =============================================================================

/** Grava uma mensagem flash (ex.: flash('sucesso', 'Salvo!')). */
function flash(string $chave, $valor): void
{
    $_SESSION['_flash'][$chave] = $valor;
}

/** Lê e remove uma mensagem flash. Retorna null se não existir. */
function flash_consumir(string $chave)
{
    if (!isset($_SESSION['_flash'][$chave])) {
        return null;
    }
    $valor = $_SESSION['_flash'][$chave];
    unset($_SESSION['_flash'][$chave]);
    return $valor;
}

// =============================================================================
// Autenticação
// =============================================================================

/** Retorna o usuário logado (array) ou null. */
function usuario_atual()
{
    return $_SESSION['usuario'] ?? null;
}

/**
 * Exige usuário logado. Sem login: vai para a home com o painel lateral de login
 * aberto e, depois de entrar, volta à página que tentou abrir (ex.: o checkout).
 */
function exigir_login(): void
{
    if (usuario_atual() !== null) {
        return;
    }
    lembrar_destino();
    abrir_login('Faça login para continuar.');
}

/** Guarda a página atual (só GET) para voltar a ela depois do login. */
function lembrar_destino(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        return;
    }
    $caminho = (string) ($GLOBALS['caminho'] ?? '');
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $_SESSION['voltar'] = $caminho . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Exige usuário administrador. Sem login: vai para a home com o painel lateral de
 * login aberto e, depois de entrar, volta ao endereço do admin que tentou abrir.
 */
function exigir_admin(): void
{
    $usuario = usuario_atual();
    if ($usuario !== null && !empty($usuario['is_admin'])) {
        return;
    }
    if ($usuario === null) {
        lembrar_destino();
        abrir_login('Entre com sua conta de administrador para acessar o painel.');
    }
    flash('erro', 'Acesso restrito.');
    redirect('');
}

/** Vai para a home com o painel lateral de login aberto (o login antigo não é mais usado). */
function abrir_login(string $mensagem = ''): void
{
    if ($mensagem !== '') {
        flash('erro', $mensagem);
    }
    flash('abrir_login', '1');
    redirect('');
}

/**
 * Para onde ir depois do login: a página guardada por lembrar_destino(), se for
 * um caminho interno válido. Sem destino: admin -> "admin"; cliente -> null
 * (fica na página em que está). Cliente nunca é mandada para o admin.
 */
function destino_pos_login(bool $is_admin): ?string
{
    $v = (string) ($_SESSION['voltar'] ?? '');
    unset($_SESSION['voltar']);
    // Só caminhos internos do site (nada de URL externa, "//" ou de volta ao login).
    $valido = $v !== ''
        && preg_match('#^[A-Za-z0-9][A-Za-z0-9_\-/]*(\?[A-Za-z0-9_\-=&%.]*)?$#', $v)
        && strpos($v, '//') === false
        && !preg_match('#^(entrar|sair|admin/entrar|admin/sair)(/|\?|$)#', $v);
    if ($valido && ($is_admin || !preg_match('#^admin(/|\?|$)#', $v))) {
        return $v;
    }
    return $is_admin ? 'admin' : null;
}

// =============================================================================
// WhatsApp
// =============================================================================

/**
 * Link de WhatsApp (wa.me) com a mensagem geral de contato (settings.whatsapp_msg),
 * enviada exatamente como está escrita (sem trocar {produto}/{link}).
 * A mensagem com produto é a do botão "Personalizar" (personalizar_msg_template).
 */
function whatsapp_link(): string
{
    $numero = preg_replace('/\D+/', '', (string) cfg('whatsapp_numero', ''));
    $texto = trim((string) cfg('whatsapp_msg', ''));
    $texto = $texto !== '' ? $texto : "Olá! Vim pelo site da D'Olivier e gostaria de falar com vocês.";
    return 'https://wa.me/' . $numero . '?text=' . rawurlencode($texto);
}

// =============================================================================
// Views
// =============================================================================

/**
 * Renderiza uma view de app/views. $dados vira variáveis dentro do arquivo.
 * Ex.: view('layout', ['titulo' => 'Home', 'conteudo' => $html]);
 */
function view(string $nome, array $dados = []): void
{
    $arquivo = APP_PATH . '/views/' . $nome . '.php';
    if (!is_file($arquivo)) {
        throw new RuntimeException('View não encontrada: ' . $nome);
    }
    extract($dados, EXTR_SKIP);
    require $arquivo;
}

/**
 * Renderiza uma view e retorna o HTML como string (sem imprimir).
 * Útil para montar o "conteudo" que será injetado no layout.
 */
function view_render(string $nome, array $dados = []): string
{
    ob_start();
    view($nome, $dados);
    return ob_get_clean();
}

// =============================================================================
// Carrinho (armazenado na sessão como [produto_id => quantidade])
// =============================================================================

/** Retorna o carrinho atual: array [produto_id => quantidade]. */
function carrinho(): array
{
    return $_SESSION['carrinho'] ?? [];
}

/** Adiciona (ou soma) uma quantidade de um produto ao carrinho. */
function carrinho_adicionar(int $produto_id, int $qtd = 1): void
{
    if ($qtd < 1) {
        $qtd = 1;
    }
    $atual = carrinho();
    $atual[$produto_id] = min(99, ($atual[$produto_id] ?? 0) + $qtd); // teto por item
    $_SESSION['carrinho'] = $atual;
}

/** Define a quantidade exata de um produto (remove se <= 0). */
function carrinho_atualizar(int $produto_id, int $qtd): void
{
    $atual = carrinho();
    if ($qtd <= 0) {
        unset($atual[$produto_id]);
    } else {
        $atual[$produto_id] = min(99, $qtd); // teto por item
    }
    $_SESSION['carrinho'] = $atual;
}

/** Remove um produto do carrinho. */
function carrinho_remover(int $produto_id): void
{
    $atual = carrinho();
    unset($atual[$produto_id]);
    $_SESSION['carrinho'] = $atual;
}

/** Esvazia o carrinho. */
function carrinho_limpar(): void
{
    $_SESSION['carrinho'] = [];
}

/** Quantidade total de itens no carrinho. */
function carrinho_quantidade(): int
{
    return array_sum(carrinho());
}

// =============================================================================
// Admin: destaque do item de menu
// =============================================================================

/**
 * Seção atual da área administrativa, derivada da URL.
 * Ex.: /admin -> '' ; /admin/produtos -> 'produtos'.
 */
function admin_secao_atual(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uri = $uri === null ? '' : rawurldecode($uri);

    $base = parse_url($GLOBALS['config']['base_url'] ?? '', PHP_URL_PATH);
    $base = $base === null ? '' : rtrim($base, '/');
    if ($base !== '' && strpos($uri, $base) === 0) {
        $uri = substr($uri, strlen($base));
    }

    $segmentos = array_values(array_filter(explode('/', trim($uri, '/')), 'strlen'));
    if (isset($segmentos[0]) && $segmentos[0] === 'admin') {
        return $segmentos[1] ?? '';
    }
    return '';
}

/** Retorna 'ativo' quando a seção informada é a página atual do admin. */
function admin_menu_ativo(string $secao): string
{
    return admin_secao_atual() === $secao ? 'ativo' : '';
}

// =============================================================================
// Slugs
// =============================================================================

/**
 * Gera um slug a partir de um texto: minúsculo, sem acentos, espaços -> hífen,
 * só [a-z0-9-], hífens colapsados e sem hífen nas pontas.
 * Remoção de acentos em PHP puro (não depende de iconv//TRANSLIT nem Normalizer,
 * que podem faltar em hospedagem compartilhada).
 */
function gerar_slug(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');

    // Mapa de acentos/caracteres comuns -> ASCII.
    $mapa = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
    ];
    $texto = strtr($texto, $mapa);

    // Troca tudo que não for a-z/0-9 por hífen (já colapsa repetições) e limpa as pontas.
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    return trim($texto, '-');
}

/**
 * Garante um slug único numa tabela (que tenha colunas id e slug). Acrescenta
 * -2, -3... se necessário. $ignorar_id permite editar sem colidir consigo mesmo.
 */
function slug_unico(string $tabela, string $base, ?int $ignorar_id = null): string
{
    // Segurança: só nomes de tabela simples (não vêm do usuário, mas reforça).
    if (!preg_match('/^[a-z_]+$/', $tabela)) {
        $tabela = 'categories';
    }
    if ($base === '') {
        $base = 'item';
    }

    $slug = $base;
    $contador = 2;
    do {
        $sql = "SELECT id FROM {$tabela} WHERE slug = ?"
             . ($ignorar_id !== null ? ' AND id <> ?' : '') . ' LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute($ignorar_id !== null ? [$slug, $ignorar_id] : [$slug]);
        $existe = (bool) $stmt->fetch();
        if ($existe) {
            $slug = $base . '-' . $contador;
            $contador++;
        }
    } while ($existe);

    return $slug;
}
