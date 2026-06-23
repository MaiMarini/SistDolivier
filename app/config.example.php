<?php
/**
 * Configuração da aplicação.
 *
 * COMO USAR: copie este arquivo para "config.php" (na mesma pasta) e preencha
 * os valores reais. O config.php fica fora do controle de versão (.gitignore)
 * e NÃO deve ser commitado nem enviado ao repositório.
 */

return [
    // --- Banco de dados (MySQL) ---
    'db_host' => 'localhost',
    'db_name' => 'nome_do_banco',
    'db_user' => 'usuario_do_banco',
    'db_pass' => 'senha_do_banco',

    // URL base do site, SEM barra no final.
    // Ex.: 'https://minhaloja.com.br' ou 'http://localhost/loja' (subpasta).
    'base_url' => 'http://localhost',

    // --- Mercado Pago (usado nas próximas fases) ---
    'mp_access_token' => '',
    'mp_public_key'   => '',

    // Ambiente: 'dev' mostra erros na tela; 'prod' esconde.
    'env' => 'dev',
];
