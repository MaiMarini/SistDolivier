<?php
/**
 * Utilitário de upload/otimização de imagens usando a extensão GD.
 *
 * Funções públicas:
 *   - processar_upload_imagem(array $arquivo, array $opcoes): valida um item de
 *     $_FILES e gera a imagem otimizada (+ miniatura opcional).
 *   - imagem_processar(string $caminho, array $opcoes): núcleo reutilizável que
 *     trabalha sobre um caminho de arquivo (útil para testes via CLI).
 *
 * Retorno (ambas):
 *   sucesso -> ['ok' => true,  'arquivo' => 'img_xxx.jpg', 'miniatura' => 'img_xxx-thumb.jpg'|null]
 *   erro    -> ['ok' => false, 'erro' => 'mensagem amigável']
 */

/**
 * Valida um item de $_FILES e processa a imagem.
 */
function processar_upload_imagem(array $arquivo, array $opcoes = []): array
{
    $max_bytes = $opcoes['max_bytes'] ?? (5 * 1024 * 1024); // ~5 MB

    if (!isset($arquivo['error']) || is_array($arquivo['error'])) {
        return ['ok' => false, 'erro' => 'Envio de arquivo inválido.'];
    }

    switch ($arquivo['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => false, 'erro' => 'Nenhum arquivo foi enviado.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'erro' => 'O arquivo enviado é grande demais.'];
        default:
            return ['ok' => false, 'erro' => 'Falha no envio do arquivo. Tente novamente.'];
    }

    if (($arquivo['size'] ?? 0) > $max_bytes) {
        return ['ok' => false, 'erro' => 'A imagem excede o tamanho máximo de 5 MB.'];
    }

    $tmp = $arquivo['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'erro' => 'Arquivo de upload inválido.'];
    }

    return imagem_processar($tmp, $opcoes);
}

/**
 * Núcleo: recebe o caminho de uma imagem, redimensiona/otimiza e salva como JPEG.
 */
function imagem_processar(string $caminho_origem, array $opcoes = []): array
{
    $max_lado      = (int) ($opcoes['max_lado'] ?? 1200);
    $qualidade     = (int) ($opcoes['qualidade'] ?? 82);
    $gerar_min     = $opcoes['gerar_miniatura'] ?? true;
    $lado_min      = (int) ($opcoes['lado_miniatura'] ?? 400);
    $max_bytes     = $opcoes['max_bytes'] ?? (5 * 1024 * 1024);
    $destino       = $opcoes['destino']
        ?? (defined('ROOT_PATH') ? ROOT_PATH . '/assets/uploads' : __DIR__ . '/../../assets/uploads');

    if (!function_exists('imagecreatetruecolor')) {
        return ['ok' => false, 'erro' => 'A extensão GD do PHP não está disponível no servidor.'];
    }

    if (!is_file($caminho_origem) || !is_readable($caminho_origem)) {
        return ['ok' => false, 'erro' => 'Não foi possível ler o arquivo enviado.'];
    }

    if (filesize($caminho_origem) > $max_bytes) {
        return ['ok' => false, 'erro' => 'A imagem excede o tamanho máximo de 5 MB.'];
    }

    // Confirma que é uma imagem real e descobre o tipo.
    $info = @getimagesize($caminho_origem);
    if ($info === false) {
        return ['ok' => false, 'erro' => 'O arquivo enviado não é uma imagem válida.'];
    }
    [$larg_orig, $alt_orig] = $info;
    $tipo = $info[2];

    if ($larg_orig < 1 || $alt_orig < 1) {
        return ['ok' => false, 'erro' => 'Imagem com dimensões inválidas.'];
    }

    // Carrega de acordo com o tipo (apenas jpg/png/webp).
    switch ($tipo) {
        case IMAGETYPE_JPEG:
            $origem = @imagecreatefromjpeg($caminho_origem);
            break;
        case IMAGETYPE_PNG:
            $origem = @imagecreatefrompng($caminho_origem);
            break;
        case IMAGETYPE_WEBP:
            $origem = function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($caminho_origem) : false;
            break;
        default:
            return ['ok' => false, 'erro' => 'Formato não suportado. Use JPG, PNG ou WebP.'];
    }

    if (!$origem) {
        return ['ok' => false, 'erro' => 'Não foi possível processar a imagem enviada.'];
    }

    // Pasta de destino precisa existir e ser gravável.
    if (!is_dir($destino) || !is_writable($destino)) {
        imagedestroy($origem);
        return [
            'ok'   => false,
            'erro' => 'A pasta de uploads (assets/uploads) não existe ou não tem permissão de escrita.',
        ];
    }

    $base = uniqid('img_', false);

    // Imagem principal (até max_lado no maior lado).
    $principal = imagem_redimensionar($origem, $larg_orig, $alt_orig, $max_lado);
    $nome_arquivo = $base . '.jpg';
    $ok_principal = imagejpeg($principal, $destino . '/' . $nome_arquivo, $qualidade);
    imagedestroy($principal);

    if (!$ok_principal) {
        imagedestroy($origem);
        return ['ok' => false, 'erro' => 'Não foi possível salvar a imagem otimizada.'];
    }

    // Miniatura opcional.
    $miniatura = null;
    if ($gerar_min) {
        $thumb = imagem_redimensionar($origem, $larg_orig, $alt_orig, $lado_min);
        $nome_thumb = $base . '-thumb.jpg';
        if (imagejpeg($thumb, $destino . '/' . $nome_thumb, $qualidade)) {
            $miniatura = $nome_thumb;
        }
        imagedestroy($thumb);
    }

    imagedestroy($origem);

    return ['ok' => true, 'arquivo' => $nome_arquivo, 'miniatura' => $miniatura];
}

/**
 * Cria uma nova imagem GD redimensionada mantendo a proporção, com no máximo
 * $max_lado pixels no maior lado. Fundo branco (saída será JPEG, sem alpha).
 */
function imagem_redimensionar($origem, int $larg_orig, int $alt_orig, int $max_lado)
{
    $maior = max($larg_orig, $alt_orig);
    if ($maior <= $max_lado) {
        $nova_l = $larg_orig;
        $nova_a = $alt_orig;
    } else {
        $escala = $max_lado / $maior;
        $nova_l = max(1, (int) round($larg_orig * $escala));
        $nova_a = max(1, (int) round($alt_orig * $escala));
    }

    $destino = imagecreatetruecolor($nova_l, $nova_a);
    $branco = imagecolorallocate($destino, 255, 255, 255);
    imagefilledrectangle($destino, 0, 0, $nova_l, $nova_a, $branco);
    imagecopyresampled(
        $destino, $origem,
        0, 0, 0, 0,
        $nova_l, $nova_a, $larg_orig, $alt_orig
    );

    return $destino;
}
