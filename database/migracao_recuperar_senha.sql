-- ============================================================
-- Recuperação de senha (passo 4.3).
--   senha_recuperacao   cada pedido de "Esqueci a senha": e-mail, IP e, quando
--                       a conta existe, o hash (SHA-256) do link enviado.
--                       O link vale 1 hora e uma vez só (usado_em). Também
--                       serve para limitar os pedidos: 3 por e-mail e 10 por
--                       IP a cada hora. Linhas com mais de 2 dias são apagadas
--                       pelo próprio site.
--   users.senha_versao  sobe a cada troca de senha; as sessões abertas com a
--                       senha antiga deixam de valer na próxima página.
--
-- Compatível com MySQL 5.7 / MariaDB (sem window functions). IDEMPOTENTE.
-- ============================================================

CREATE TABLE IF NOT EXISTS `senha_recuperacao` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NULL,
    `email`      VARCHAR(190) NOT NULL,
    `ip`         VARCHAR(45)  NOT NULL,
    `token_hash` CHAR(64)     NULL,
    `criado_em`  DATETIME     NOT NULL,
    `expira_em`  DATETIME     NULL,
    `usado_em`   DATETIME     NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_senha_token` (`token_hash`),
    KEY `idx_senha_email` (`email`, `criado_em`),
    KEY `idx_senha_ip` (`ip`, `criado_em`),
    KEY `idx_senha_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'senha_versao');
SET @s := IF(@c = 0, 'ALTER TABLE `users` ADD COLUMN `senha_versao` INT UNSIGNED NOT NULL DEFAULT 0', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
