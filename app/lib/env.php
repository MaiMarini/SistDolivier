<?php

/**
 * Leitor simples de arquivo .env (KEY=VALUE), sem dependências.
 * Carregado no bootstrap ANTES do config.php, para que o config leia segredos
 * do .env (que NÃO é versionado). Linhas em branco e começadas por # são
 * ignoradas; aspas envolventes são removidas.
 */

/** Lê o arquivo .env informado para $GLOBALS['_env'] (silencioso se não existir). */
function env_carregar(string $arquivo): void
{
    if (!is_file($arquivo) || !is_readable($arquivo)) {
        return;
    }
    if (!isset($GLOBALS['_env'])) {
        $GLOBALS['_env'] = [];
    }
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#') {
            continue;
        }
        $pos = strpos($linha, '=');
        if ($pos === false) {
            continue;
        }
        $chave = trim(substr($linha, 0, $pos));
        $valor = trim(substr($linha, $pos + 1));
        if ($chave === '') {
            continue;
        }
        // Remove aspas envolventes ("valor" ou 'valor').
        $len = strlen($valor);
        if ($len >= 2
            && (($valor[0] === '"' && $valor[$len - 1] === '"')
                || ($valor[0] === "'" && $valor[$len - 1] === "'"))) {
            $valor = substr($valor, 1, -1);
        }
        $GLOBALS['_env'][$chave] = $valor;
    }
}

/** Retorna uma variável do .env (ou do ambiente), com valor padrão. */
function env(string $chave, $padrao = null)
{
    if (isset($GLOBALS['_env'][$chave])) {
        return $GLOBALS['_env'][$chave];
    }
    $v = getenv($chave);
    return $v === false ? $padrao : $v;
}
