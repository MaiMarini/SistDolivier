-- ============================================================
-- Índices do Painel do admin: vendas por data de pagamento (pago_em) e
-- contagem por etapa (status). Só cria o que falta.
--
-- IDEMPOTENTE: pode rodar mais de uma vez.
-- ============================================================

-- orders(pago_em): vendas por dia e mais vendidos.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pago_em' AND SEQ_IN_INDEX = 1);
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD INDEX `idx_orders_pago_em` (`pago_em`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- orders(status): atalhos do dia. O schema original já cria idx_orders_status.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'status' AND SEQ_IN_INDEX = 1);
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD INDEX `idx_orders_status` (`status`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
