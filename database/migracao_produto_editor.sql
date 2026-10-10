-- ============================================================
-- Editor de produto (admin › Novo/Editar produto).
--   1) products.atualizado_em: "última alteração" no topo do formulário.
--   2) product_upload_temp: fotos enviadas na hora e ainda não salvas no produto
--      (ligadas a um token de rascunho do formulário). Ao salvar, viram
--      product_images; as abandonadas há mais de 24 h são apagadas.
--
-- Compatível com MySQL 5.7 / MariaDB. IDEMPOTENTE: pode rodar mais de uma vez.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'atualizado_em');
SET @s := IF(@c = 0, 'ALTER TABLE `products` ADD COLUMN `atualizado_em` DATETIME NULL AFTER `criado_em`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS `product_upload_temp` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`     CHAR(32)     NOT NULL,
  `arquivo`   VARCHAR(255) NOT NULL,
  `criado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_upload_temp_token` (`token`),
  KEY `idx_upload_temp_criado` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
