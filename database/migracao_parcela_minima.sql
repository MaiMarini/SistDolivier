-- ============================================================
-- Parcelamento: parcelar só a partir de parcelamento_limite_centavos (R$ 120),
-- cada parcela >= parcela_minima_centavos (R$ 40), em ATÉ parcelamento_max (3x).
-- ============================================================

-- Valor mínimo da parcela (semeia; não sobrescreve ajuste do admin).
INSERT INTO `settings` (`chave`, `valor`) VALUES
  ('parcela_minima_centavos', '4000')   -- R$ 40,00
ON DUPLICATE KEY UPDATE `chave` = `chave`;

-- Mínimo do pedido para parcelar (semeia se ainda não existir).
INSERT INTO `settings` (`chave`, `valor`) VALUES
  ('parcelamento_limite_centavos', '12000')  -- R$ 120,00
ON DUPLICATE KEY UPDATE `chave` = `chave`;

-- Teto de parcelas: FORÇA 3 (sobrescreve valor anterior).
INSERT INTO `settings` (`chave`, `valor`) VALUES
  ('parcelamento_max', '3')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);
