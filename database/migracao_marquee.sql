-- =============================================================================
-- Migração: tabela das frases do marquee (a tarja de frases em movimento da home).
-- Aplique no banco JÁ EXISTENTE em produção. Usa CREATE TABLE IF NOT EXISTS,
-- então não recria nada se já existir e pode rodar mais de uma vez.
--
-- Segue o estilo da tabela `banners` (InnoDB, utf8mb4) e usa o mesmo nome de
-- coluna de timestamp do restante do projeto: `criado_em` (não "created_at").
-- =============================================================================

CREATE TABLE IF NOT EXISTS marquee_frases (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    texto     VARCHAR(120) NOT NULL,                       -- frase curta exibida na tarja
    ordem     INT NOT NULL DEFAULT 0,                      -- ordem de exibição (menor primeiro)
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
