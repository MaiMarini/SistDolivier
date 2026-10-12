-- ============================================================
-- Coleções (Admin › Coleções): grupos de produtos dentro de uma categoria
-- (ex.: "Dia das Mães 2027" em Coleções Sazonais) com a mesma data limite
-- para encomendar e a mesma data de entrega/retirada.
--   colecoes            nome, categoria, encomendas_ate, entrega_em,
--                       observação de entrega, ordem, ligada/desligada e
--                       exclusão lógica com "Desfazer" (como Categorias)
--   products.colecao_id coleção do produto (NULL = usa o prazo de produção)
--
-- Compatível com MySQL 5.7 / MariaDB (sem window functions). IDEMPOTENTE.
-- ============================================================

CREATE TABLE IF NOT EXISTS `colecoes` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id`    INT UNSIGNED NOT NULL,
    `nome`           VARCHAR(150) NOT NULL,
    `encomendas_ate` DATE         NOT NULL,
    `entrega_em`     DATE         NOT NULL,
    `observacao`     VARCHAR(500) NULL,
    `ordem`          INT          NOT NULL DEFAULT 0,
    `ativo`          TINYINT(1)   NOT NULL DEFAULT 1,
    `criado_em`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `excluida_em`    DATETIME     NULL,
    `exclusao_info`  TEXT         NULL,
    PRIMARY KEY (`id`),
    KEY `idx_colecoes_categoria` (`category_id`, `ordem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'colecao_id');
SET @s := IF(@c = 0, 'ALTER TABLE `products` ADD COLUMN `colecao_id` INT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_colecao');
SET @s := IF(@c = 0, 'ALTER TABLE `products` ADD INDEX `idx_products_colecao` (`colecao_id`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
