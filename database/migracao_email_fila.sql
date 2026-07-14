-- ============================================================
-- Fila de e-mails (envio assíncrono, processado por worker/cron).
-- O request web só ENFILEIRA; o envio real ocorre no CLI (cron), que
-- costuma ter saída SMTP liberada na hospedagem compartilhada.
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_fila` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `para`        VARCHAR(190) NOT NULL,
  `assunto`     VARCHAR(255) NOT NULL,
  `corpo_html`  LONGTEXT NOT NULL,
  `status`      ENUM('pendente','enviado','erro') NOT NULL DEFAULT 'pendente',
  `tentativas`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `erro`        VARCHAR(255) NULL,
  `criado_em`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `enviado_em`  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_fila_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
