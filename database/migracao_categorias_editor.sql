-- ============================================================
-- Admin › Categorias (editar na linha).
--   excluida_em     exclusão lógica (permite "Desfazer"); NULL = ativa
--   exclusao_info   o que a exclusão mudou (JSON): ativo e ordem antes,
--                   destino e os produtos movidos com a posição anterior
-- Renumera a ordem 1, 2, 3… (na ordem atual) só se ela não for confiável
-- (valores repetidos ou zerados).
--
-- Compatível com MySQL 5.7 / MariaDB (sem window functions). IDEMPOTENTE.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'excluida_em');
SET @s := IF(@c = 0, 'ALTER TABLE `categories` ADD COLUMN `excluida_em` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'exclusao_info');
SET @s := IF(@c = 0, 'ALTER TABLE `categories` ADD COLUMN `exclusao_info` TEXT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Ordem: renumera só se houver repetidos ou zerados.
SET @ruim := (SELECT (COUNT(*) - COUNT(DISTINCT ordem)) + SUM(ordem <= 0) FROM `categories`) > 0;
SET @n := 0;
SET @s := IF(@ruim, 'UPDATE `categories` SET ordem = (@n := @n + 1) ORDER BY ordem ASC, id ASC', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
