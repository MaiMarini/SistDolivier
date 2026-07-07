-- ============================================================
-- Imagem lateral (sticky) da seção "Coleções" na home.
-- settings = chave (PK) + valor. Seguro rodar mais de uma vez.
-- ============================================================

INSERT INTO `settings` (`chave`, `valor`) VALUES
  ('colecoes_imagem_lateral', '')
ON DUPLICATE KEY UPDATE `chave` = `chave`;
