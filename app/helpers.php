<?php
/**
 * Funções utilitárias da aplicação (carregadas pelo bootstrap.php).
 * Comentários e textos em português; compatível com PHP 7.4+.
 */

// Biblioteca de upload/otimização de imagens (GD).
require_once __DIR__ . '/lib/imagem.php';

// Cálculo de frete por distância (valor final + distância plugável).
require_once __DIR__ . '/lib/frete.php';

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
function parcelamento_texto(int $total_centavos): string
{
    $limite = (int) cfg('parcelamento_limite_centavos', 0);
    $minima = (int) cfg('parcela_minima_centavos', 0);
    $max    = (int) cfg('parcelamento_max', 0);

    if ($total_centavos <= 0 || $total_centavos < $limite) {
        return 'à vista';
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
    if ($parcelas < 2) {
        return 'à vista';
    }

    $valor_parcela = (int) ceil($total_centavos / $parcelas);
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

/** Exige usuário logado; caso contrário, redireciona para o login. */
function exigir_login(): void
{
    if (usuario_atual() === null) {
        flash('erro', 'Faça login para continuar.');
        redirect('entrar');
    }
}

/** Exige usuário administrador; caso contrário, vai para o login do admin. */
function exigir_admin(): void
{
    $usuario = usuario_atual();
    if ($usuario === null || empty($usuario['is_admin'])) {
        flash('erro', 'Acesso restrito.');
        redirect('admin/entrar');
    }
}

// =============================================================================
// WhatsApp
// =============================================================================

/**
 * Monta um link de WhatsApp (wa.me) com mensagem pré-preenchida.
 * O número vem das configurações (settings.whatsapp). Se um produto for
 * informado (array com 'nome'), a mensagem cita o produto.
 */
function whatsapp_link(?array $produto = null): string
{
    $numero = preg_replace('/\D+/', '', (string) cfg('whatsapp_numero', ''));

    if ($produto !== null && !empty($produto['nome'])) {
        // Usa o modelo das configurações, trocando o marcador {produto}.
        $modelo = (string) cfg('whatsapp_msg', 'Olá! Tenho interesse no produto: {produto}');
        $texto  = str_replace('{produto}', $produto['nome'], $modelo);
    } else {
        $texto = 'Olá! Gostaria de mais informações.';
    }

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
