<?php
/**
 * Configuração da aplicação.
 *
 * COMO USAR: copie este arquivo para "config.php" (na mesma pasta). Os SEGREDOS
 * (banco, SMTP, tokens) ficam no arquivo ".env" na raiz do projeto — NÃO
 * versionado. Copie ".env.example" para ".env" e preencha lá.
 *
 * Este config.php lê do .env via env(); os valores após a vírgula são apenas
 * o "padrão" caso a variável não exista no .env (você pode fixar valores aqui
 * também, se preferir não usar .env).
 */

return [
    // --- Banco de dados (MySQL) ---
    'db_host' => env('DB_HOST', 'localhost'),
    'db_name' => env('DB_NAME', 'nome_do_banco'),
    'db_user' => env('DB_USER', 'usuario_do_banco'),
    'db_pass' => env('DB_PASS', 'senha_do_banco'),

    // URL base do site, SEM barra no final.
    'base_url' => env('BASE_URL', 'http://localhost'),

    // --- Mercado Pago (usado nas próximas fases) ---
    'mp_access_token' => env('MP_ACCESS_TOKEN', ''),
    'mp_public_key'   => env('MP_PUBLIC_KEY', ''),

    // Ambiente: 'dev' mostra erros na tela; 'prod' esconde.
    'env' => env('APP_ENV', 'dev'),
];
