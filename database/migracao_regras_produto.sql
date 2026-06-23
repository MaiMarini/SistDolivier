-- Migração: adiciona a observação/regras específica do produto.
-- Aplique este script no banco JÁ EXISTENTE (antes de publicar o novo código,
-- pois a página do produto passa a ler esta coluna).

ALTER TABLE products ADD COLUMN regras_produto TEXT NULL AFTER descricao;
