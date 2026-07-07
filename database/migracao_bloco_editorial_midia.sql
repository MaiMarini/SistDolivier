-- ============================================================
-- Bloco editorial da home: aceitar FOTO ou VÍDEO.
-- settings = chave (PK) / valor. Aplique no banco JÁ EXISTENTE.
--
-- Novas chaves:
--   bloco_editorial_tipo_midia -> "foto" ou "video" (padrão: "foto")
--   bloco_editorial_video      -> caminho do arquivo de vídeo (ex.: assets/uploads/xxx.mp4)
--
-- A chave bloco_editorial_imagem (foto) já existe e continua sendo usada.
--
-- ON DUPLICATE KEY UPDATE chave = chave: no-op se a chave já existir
-- (NÃO sobrescreve valores já cadastrados). Seguro rodar mais de uma vez.
-- ============================================================

INSERT INTO `settings` (`chave`, `valor`) VALUES
  ('bloco_editorial_tipo_midia', 'foto'),
  ('bloco_editorial_video',      '')
ON DUPLICATE KEY UPDATE `chave` = `chave`;
