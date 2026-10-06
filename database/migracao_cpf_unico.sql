-- ============================================================
-- CPF único por conta.
-- O admin pode ficar com CPF NULL (o índice único aceita vários NULL).
-- ATENÇÃO: falha se já houver CPFs repetidos. Confira antes com:
--   SELECT cpf, COUNT(*) FROM users WHERE cpf IS NOT NULL
--    GROUP BY cpf HAVING COUNT(*) > 1;
-- Rode UMA vez.
-- ============================================================

-- CPF vazio vira NULL (para não contar como repetido).
UPDATE `users` SET `cpf` = NULL WHERE `cpf` = '';

ALTER TABLE `users` ADD UNIQUE KEY `uq_users_cpf` (`cpf`);
