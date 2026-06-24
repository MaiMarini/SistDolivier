-- Migração: marca de "destaque" do produto (aparece na home).
-- Aplique no banco JÁ EXISTENTE.

ALTER TABLE products ADD COLUMN destaque TINYINT(1) NOT NULL DEFAULT 0 AFTER personalizavel;
