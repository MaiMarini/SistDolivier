-- ============================================================
-- Editor de tabelas nutricionais (admin › Tabelas nutricionais).
--   medida_caseira          "3 biscoitos", "1 unidade" (linha da porção no rótulo)
--   alergenos_contem        itens de "Contém", separados por vírgula (Trigo,Leite,...)
--   alergenos_traco         itens de "Pode conter (traços)", idem
--   contem_gluten           1 = "Contém glúten", 0 = "Não contém glúten"
--   alergenos_manual        texto escrito à mão (NULL = texto automático)
--   excluida_em             exclusão lógica (permite "Desfazer"); NULL = ativa
--   updated_at              "última alteração" (cria só se faltar)
-- A coluna "alergenicos" continua guardando o texto FINAL que a loja mostra.
-- As tabelas que já existem recebem o texto atual como texto manual (só quando a
-- coluna é criada agora), para nada mudar na loja até alguém editar.
--
-- Compatível com MySQL 5.7 / MariaDB (sem window functions). IDEMPOTENTE.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tabelas_nutricionais' AND COLUMN_NAME = 'medida_caseira');
SET @s := IF(@c = 0, 'ALTER TABLE `tabelas_nutricionais` ADD COLUMN `medida_caseira` VARCHAR(60) NULL AFTER `porcao_individual_g`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tabelas_nutricionais' AND COLUMN_NAME = 'alergenos_contem');
SET @s := IF(@c = 0, 'ALTER TABLE `tabelas_nutricionais` ADD COLUMN `alergenos_contem` VARCHAR(255) NULL AFTER `alergenicos`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tabelas_nutricionais' AND COLUMN_NAME = 'alergenos_traco');
SET @s := IF(@c = 0, 'ALTER TABLE `tabelas_nutricionais` ADD COLUMN `alergenos_traco` VARCHAR(255) NULL AFTER `alergenos_contem`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tabelas_nutricionais' AND COLUMN_NAME = 'contem_gluten');
SET @s := IF(@c = 0, 'ALTER TABLE `tabelas_nutricionais` ADD COLUMN `contem_gluten` TINYINT(1) NOT NULL DEFAULT 0 AFTER `alergenos_traco`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Texto manual: criado agora -> recebe o texto atual de todas as tabelas existentes.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tabelas_nutricionais' AND COLUMN_NAME = 'alergenos_manual');
SET @criar := (@c = 0);
SET @s := IF(@criar, 'ALTER TABLE `tabelas_nutricionais` ADD COLUMN `alergenos_manual` TEXT NULL AFTER `contem_gluten`', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF(@criar, 'UPDATE `tabelas_nutricionais` SET `alergenos_manual` = COALESCE(`alergenicos`, '''')', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tabelas_nutricionais' AND COLUMN_NAME = 'excluida_em');
SET @s := IF(@c = 0, 'ALTER TABLE `tabelas_nutricionais` ADD COLUMN `excluida_em` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tabelas_nutricionais' AND COLUMN_NAME = 'updated_at');
SET @s := IF(@c = 0, 'ALTER TABLE `tabelas_nutricionais` ADD COLUMN `updated_at` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
