-- ============================================================
-- Coleções: início da campanha, capa e endereço próprio na loja
--   colecoes.abre_em   início da campanha: até esse dia a loja mostra
--                      "Em breve" e não vende (NULL = abre ao salvar)
--   colecoes.capa     arquivo da capa em assets/uploads (NULL = sem capa;
--                      a loja usa um degradê no lugar)
--   colecoes.capa_alt  texto alternativo da capa (NULL = usa o nome)
--   colecoes.slug      parte final do endereço /{categoria}/{slug}
--                      (ex.: /colecoes-sazonais/halloween). Gerado pelo
--                      sistema a partir do nome; as coleções que já existem
--                      ganham o slug na primeira vez que a loja abrir.
--
-- Requer migracao_colecoes.sql. Compatível com MySQL 5.7 / MariaDB
-- (sem window functions). IDEMPOTENTE: pode rodar mais de uma vez.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND COLUMN_NAME = 'abre_em');
SET @s := IF(@c = 0, 'ALTER TABLE `colecoes` ADD COLUMN `abre_em` DATE NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND COLUMN_NAME = 'capa');
SET @s := IF(@c = 0, 'ALTER TABLE `colecoes` ADD COLUMN `capa` VARCHAR(255) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND COLUMN_NAME = 'capa_alt');
SET @s := IF(@c = 0, 'ALTER TABLE `colecoes` ADD COLUMN `capa_alt` VARCHAR(150) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND COLUMN_NAME = 'slug');
SET @s := IF(@c = 0, 'ALTER TABLE `colecoes` ADD COLUMN `slug` VARCHAR(170) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'colecoes' AND INDEX_NAME = 'idx_colecoes_slug');
SET @s := IF(@c = 0, 'ALTER TABLE `colecoes` ADD INDEX `idx_colecoes_slug` (`category_id`, `slug`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
