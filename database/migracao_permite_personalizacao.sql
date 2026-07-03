-- Migração: coluna que habilita o botão "Personalizar" (WhatsApp) por produto.
-- Aplique no banco JÁ EXISTENTE. (0 = não mostra o botão; 1 = mostra.)
--
-- Se aparecer "Duplicate column name", a coluna já existe — pode ignorar.
-- Para conferir antes: SHOW COLUMNS FROM products LIKE 'permite_personalizacao';

ALTER TABLE products
    ADD COLUMN permite_personalizacao TINYINT(1) NOT NULL DEFAULT 0 AFTER destaque;
