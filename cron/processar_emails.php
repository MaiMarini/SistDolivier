<?php

/**
 * Worker de e-mails (CLI/cron): envia os e-mails pendentes da fila.
 *
 * Uso no cron da HostGator (ex.: a cada 5 minutos):
 *   php /home1/patr1679/public_html/cron/processar_emails.php >> /home1/patr1679/logs/emails.log 2>&1
 * (ajuste o caminho conforme onde o site está publicado).
 *
 * Roda no CLI, que normalmente TEM permissão de saída SMTP na hospedagem
 * compartilhada — ao contrário do processo web.
 */

// Só via linha de comando (evita execução pela web).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}

require __DIR__ . '/../app/bootstrap.php';

$r = email_processar_fila(50);
echo date('c') . " enviados={$r['enviados']} falhas={$r['falhas']}\n";
