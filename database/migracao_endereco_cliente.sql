-- ============================================================
-- Endereço do cliente em campos separados (página "Minha conta").
-- users.endereco continua existindo: é o texto completo, montado
-- a partir destes campos ao salvar (checkout e frete usam ele).
-- ATENÇÃO: ALTER TABLE não é idempotente no MySQL — rode UMA vez,
-- ANTES de publicar o código que usa estas colunas.
-- ============================================================
ALTER TABLE `users`
  ADD COLUMN `cep`         VARCHAR(8)   NULL AFTER `telefone`,
  ADD COLUMN `rua`         VARCHAR(150) NULL AFTER `cep`,
  ADD COLUMN `numero`      VARCHAR(20)  NULL AFTER `rua`,
  ADD COLUMN `complemento` VARCHAR(100) NULL AFTER `numero`,
  ADD COLUMN `bairro`      VARCHAR(100) NULL AFTER `complemento`,
  ADD COLUMN `cidade`      VARCHAR(100) NULL AFTER `bairro`,
  ADD COLUMN `uf`          CHAR(2)      NULL AFTER `cidade`;
