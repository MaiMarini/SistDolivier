-- ============================================================
-- Tabelas nutricionais: porção padrão fixa de 100 g.
-- A cliente digita os valores POR 100 g e informa quantos GRAMAS
-- tem a porção individual. O sistema calcula a coluna individual
-- por regra de três: valor_ind = valor_100g * (gramas_ind / 100).
--
-- Adiciona o campo numérico dos gramas da porção individual.
-- Percona/MySQL 5.7: rode UMA VEZ.
-- ============================================================

ALTER TABLE `tabelas_nutricionais`
  ADD COLUMN `porcao_individual_g` DECIMAL(8,2) NULL AFTER `nome`;

-- OBSERVAÇÃO (opcional): os antigos campos de texto deixam de ser usados.
-- Só remova se o código já não depender deles:
-- ALTER TABLE `tabelas_nutricionais` DROP COLUMN `nutri_porcao`;
-- ALTER TABLE `tabelas_nutricionais` DROP COLUMN `nutri_porcao_individual`;
