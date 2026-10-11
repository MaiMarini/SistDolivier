-- ============================================================
-- Passos 4.3 e 4.5 do plano (segurança).
--   login_tentativas   falhas de login para o limite de tentativas
--                      (5 por e-mail ou 20 por IP em 15 minutos).
--                      Guarda só e-mail, IP e horário; linhas com mais
--                      de 1 dia são apagadas pelo próprio site.
--   settings.maps_api_key  removida: a chave do Google fica só no .env
--                      (GOOGLE_MAPS_API_KEY) e nada no código a lê.
--
-- Compatível com MySQL 5.7 / MariaDB (sem window functions). IDEMPOTENTE.
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_tentativas` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`     VARCHAR(190) NOT NULL,
    `ip`        VARCHAR(45)  NOT NULL,
    `criado_em` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_login_email` (`email`, `criado_em`),
    KEY `idx_login_ip` (`ip`, `criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE FROM `settings` WHERE `chave` = 'maps_api_key';
