-- ============================================================
-- Migração: chaves de redes sociais no footer (tabela settings)
-- settings = chave (PK, varchar 100) + valor (text)
-- whatsapp_numero JÁ EXISTE (guarda só o número, ex.: 5511999999999)
-- Seguro rodar mais de uma vez: cria se não existir e NÃO
-- sobrescreve valores já preenchidos.
--
-- PADRÃO: o banco guarda o dado PURO; o footer monta o link.
--   instagram_usuario -> só o usuário (sem @), ex.: dolivier.confeitaria
--                        footer monta https://instagram.com/<usuario>
--   tiktok_usuario    -> só o usuário (sem @), ex.: dolivier.confeitaria
--                        footer monta https://tiktok.com/@<usuario>
--   facebook_url      -> link completo da página (FB não tem padrão simples de usuário)
--   pinterest_url     -> link completo do perfil (idem)
-- ============================================================

INSERT INTO `settings` (`chave`, `valor`) VALUES
  ('instagram_usuario', ''),
  ('tiktok_usuario',    ''),
  ('facebook_url',      ''),
  ('pinterest_url',     '')
ON DUPLICATE KEY UPDATE `chave` = `chave`;
