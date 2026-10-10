-- ============================================================
-- Ordem dos produtos dentro da categoria (admin › Produtos e página da categoria).
-- Cria products.ordem e preenche 1..N por categoria na ordem em que a loja
-- mostrava até hoje (id crescente). O preenchimento só acontece quando a coluna
-- é criada agora OU quando a ordem ainda está toda zerada.
--
-- Compatível com MySQL 5.7 / MariaDB (sem ROW_NUMBER).
-- IDEMPOTENTE: pode rodar mais de uma vez (não refaz uma ordem já definida).
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'ordem');
SET @s := IF(@c = 0, 'ALTER TABLE `products` ADD COLUMN `ordem` INT NOT NULL DEFAULT 0 AFTER `category_id`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Preenche a ordem atual (só se ainda estiver toda em 0).
SET @zerada := (SELECT COALESCE(MAX(ordem), 0) = 0 FROM `products`);
SET @s := IF(@zerada,
  'UPDATE `products` p
     JOIN (SELECT a.id, COUNT(*) AS n
             FROM `products` a
             JOIN `products` b ON b.category_id <=> a.category_id AND b.id <= a.id
            GROUP BY a.id) x ON x.id = p.id
      SET p.ordem = x.n',
  'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Índice para listar a categoria na ordem.
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_categoria_ordem');
SET @s := IF(@c = 0, 'ALTER TABLE `products` ADD INDEX `idx_products_categoria_ordem` (`category_id`, `ordem`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
