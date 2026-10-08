-- ============================================================
-- Contador de tentativas de estorno (chave de idempotência "estorno-{pedido}-{tentativa}").
-- Cada clique em "Estornar" usa uma chave nova; antes a chave era fixa e, depois da
-- primeira falha, o Mercado Pago devolvia sempre a mesma resposta guardada.
--
-- IDEMPOTENTE: pode rodar mais de uma vez.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'estorno_tentativa');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `estorno_tentativa` INT UNSIGNED NOT NULL DEFAULT 0', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
