-- ============================================================
-- E-mails automáticos do pedido (passo 4.2).
--   orders.email_recebido_em  quando o e-mail "pedido recebido" saiu
--   orders.email_pago_em      quando o e-mail "pagamento aprovado" saiu
-- Servem para cada e-mail sair uma vez só (o Mercado Pago repete os avisos).
-- Pedidos antigos ficam vazios e não recebem e-mail retroativo.
--
-- Compatível com MySQL 5.7 / MariaDB (sem window functions). IDEMPOTENTE.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'email_recebido_em');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `email_recebido_em` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'email_pago_em');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `email_pago_em` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
