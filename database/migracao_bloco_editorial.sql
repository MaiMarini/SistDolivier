-- =============================================================================
-- Migração: campos do BLOCO EDITORIAL da home (único na página).
-- Aplique no banco JÁ EXISTENTE em produção.
--
-- Observação importante: NÃO há ALTER TABLE aqui. A tabela `settings` segue o
-- padrão chave/valor (coluna `chave` é PRIMARY KEY), então cada campo do bloco
-- é apenas uma LINHA nova — não é preciso criar colunas.
--
-- Idempotente: usamos INSERT IGNORE. Se a chave já existir, a linha é ignorada
-- (mantém o valor atual que você já editou); se não existir, é criada com o
-- valor padrão abaixo. Assim pode rodar este script mais de uma vez sem risco
-- de duplicar nem de sobrescrever conteúdo já cadastrado.
-- =============================================================================

INSERT IGNORE INTO settings (chave, valor) VALUES
    ('bloco_editorial_imagem',       ''),          -- arquivo em assets/uploads (ex.: img_xxx.jpg)
    ('bloco_editorial_titulo',       ''),          -- título grande (Fraunces)
    ('bloco_editorial_subtitulo',    ''),          -- subtítulo (Nunito)
    ('bloco_editorial_botao_texto',  'Ver mais'),  -- rótulo do botão
    ('bloco_editorial_botao_link',   '');          -- destino do botão (ex.: /categoria/velas)
