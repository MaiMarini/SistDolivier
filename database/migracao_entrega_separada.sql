-- ============================================================
-- Checkout com datas de entrega diferentes
--   orders.entrega_a_partir  data a partir da qual o pedido é entregue ou
--                            retirado (coleção: data da coleção; outros
--                            produtos: hoje + prazo de produção em dias úteis)
--   orders.grupo_pagamento   pedidos separados por data e pagos juntos: todos
--                            levam o id do primeiro pedido do grupo (NULL =
--                            pedido pago sozinho). Um pagamento só no Mercado
--                            Pago; cancelar um deles estorna só o valor dele.
--
-- Compatível com MySQL 5.7 / MariaDB (sem window functions). IDEMPOTENTE.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'entrega_a_partir');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `entrega_a_partir` DATE NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'grupo_pagamento');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `grupo_pagamento` INT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_grupo_pagamento');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD INDEX `idx_orders_grupo_pagamento` (`grupo_pagamento`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
