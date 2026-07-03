-- ============================================================
-- Renomeia a coluna "medida caseira" -> "porção individual".
-- Aplique UMA VEZ no banco existente (MySQL/Percona 5.7).
-- CHANGE preserva o tipo e os dados já gravados.
-- ============================================================

ALTER TABLE `tabelas_nutricionais`
  CHANGE `nutri_medida_caseira` `nutri_porcao_individual` VARCHAR(60) NULL;
