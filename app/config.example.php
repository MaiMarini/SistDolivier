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

    // --- E-mail (avisos de pedido) — técnico, fica fora do admin ---
    // modo: 'smtp' (recomendado; entrega pelo servidor do domínio, ex.: Titan)
    //       ou 'mail' (função mail() do servidor).
    'email' => [
        'modo'           => 'smtp',
        'smtp_host'      => 'smtp.titan.email',
        'smtp_porta'     => 465,               // 465 = SSL · 587 = TLS
        'smtp_seguranca' => 'ssl',             // 'ssl' | 'tls'
        'smtp_usuario'   => 'suporte@dolivier.com.br',
        'smtp_senha'     => '',                // senha da caixa (preencher no servidor)
        'remetente'      => 'suporte@dolivier.com.br', // usado no modo 'mail'
        'loja'           => 'suporte@dolivier.com.br', // recebe aviso de novo pedido ('' = não avisa)
    ],

    // Ambiente: 'dev' mostra erros na tela; 'prod' esconde.
    'env' => 'dev',
];
