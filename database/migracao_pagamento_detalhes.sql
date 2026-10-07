-- ============================================================
-- Detalhes do pagamento para a tela "Meus pedidos".
--  - orders.pagamento_tipo:       payment_type_id do Mercado Pago
--                                 (credit_card, debit_card, bank_transfer = Pix, ticket = boleto)
--  - orders.pagamento_ticket_url: link do QR/código do Pix ou do boleto pendente
-- IDEMPOTENTE: pode rodar mais de uma vez (só cria o que ainda não existe).
-- Rode ANTES de publicar o código desta etapa.
-- ============================================================

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pagamento_tipo');
SET @sql := IF(@tem = 0,
    'ALTER TABLE `orders` ADD COLUMN `pagamento_tipo` VARCHAR(30) NULL AFTER `pagamento`',
    'DO 0');   -- já existe: não faz nada
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @tem := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pagamento_ticket_url');
SET @sql := IF(@tem = 0,
    'ALTER TABLE `orders` ADD COLUMN `pagamento_ticket_url` VARCHAR(500) NULL AFTER `pagamento_tipo`',
    'DO 0');   -- já existe: não faz nada
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
