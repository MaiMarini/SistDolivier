-- ============================================================
-- Ordem dos produtos dentro da categoria (admin › Produtos e página da categoria).
-- Cria products.ordem e, SÓ quando a coluna é criada agora, preenche 1..N por
-- categoria na ordem em que a loja mostrava até hoje (id crescente).
--
-- IDEMPOTENTE: pode rodar mais de uma vez (não refaz a ordem já definida).
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'ordem');
SET @criar := (@c = 0);
SET @s := IF(@criar, 'ALTER TABLE `products` ADD COLUMN `ordem` INT NOT NULL DEFAULT 0 AFTER `category_id`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Preenche a ordem atual (só na primeira execução).
SET @s := IF(@criar,
  'UPDATE `products` p
     JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY category_id ORDER BY id) AS n FROM `products`) x ON x.id = p.id
      SET p.ordem = x.n',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Índice para listar a categoria na ordem.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_categoria_ordem');
SET @s := IF(@c = 0, 'ALTER TABLE `products` ADD INDEX `idx_products_categoria_ordem` (`category_id`, `ordem`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
