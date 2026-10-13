-- ============================================================
-- Aceite das regras e prazos no checkout
--   orders.termos_texto       texto exato das regras e prazos que o cliente
--                             aceitou (prazo de cada item, regras de cada
--                             produto e regras gerais), gravado ao fechar o pedido
--   orders.termos_aceitos_em  data e hora do aceite
--
-- Compatível com MySQL 5.7 / MariaDB (sem window functions). IDEMPOTENTE.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'termos_texto');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `termos_texto` MEDIUMTEXT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'termos_aceitos_em');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `termos_aceitos_em` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
