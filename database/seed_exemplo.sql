-- =============================================================================
-- Dados de EXEMPLO (genéricos) para a loja virtual.
-- Importe DEPOIS do schema.sql. Tudo aqui é fictício e pode ser trocado.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Configurações da loja (settings) — "de marca", trocar por loja
-- -----------------------------------------------------------------------------
INSERT INTO settings (chave, valor) VALUES
    ('site_nome',                  'Minha Loja'),
    ('site_descricao',             'Produtos artesanais feitos com carinho.'),
    ('whatsapp_numero',            '5511999999999'),
    ('whatsapp_msg',               'Olá! Tenho interesse no produto: {produto}'),
    ('regras_texto',               'Pedidos personalizados levam de 3 a 7 dias para produção. Entregas por motoboy conforme a região ou retirada no local.'),
    ('sobre_texto',                'Somos uma loja de produtos artesanais. Cada peça é feita à mão, com atenção aos detalhes.'),
    ('entrega_taxa_centavos',      '1000'),
    ('entrega_obs',                'Taxa de entrega varia conforme a região. Retirada no local é gratuita.'),
    ('parcelamento_limite_centavos','12000'),
    ('parcelamento_max',           '3'),
    -- Cores do tema (valores padrão, nomes genéricos)
    ('cor_primaria',               '#6B4A2C'),
    ('cor_destaque',               '#D4A53F'),
    ('cor_acento',                 '#8C9A5E'),
    ('cor_pop',                    '#BC5B38'),
    ('cor_fundo',                  '#F6EEDD'),
    ('cor_texto',                  '#4A3320');

-- -----------------------------------------------------------------------------
-- Categorias de exemplo
-- -----------------------------------------------------------------------------
INSERT INTO categories (id, slug, nome, ordem, ativo) VALUES
    (1, 'velas',       'Velas Aromáticas', 1, 1),
    (2, 'sabonetes',   'Sabonetes',        2, 1),
    (3, 'personalizados','Personalizados', 3, 1);

-- -----------------------------------------------------------------------------
-- Produtos de exemplo (preços em CENTAVOS)
-- -----------------------------------------------------------------------------
INSERT INTO products
    (slug, nome, descricao, preco_centavos, category_id, imagem, dias_producao, personalizavel, ativo)
VALUES
    ('vela-lavanda',   'Vela de Lavanda',
     'Vela aromática de lavanda, cera vegetal, queima aproximada de 40 horas.',
     3500, 1, NULL, 0, 0, 1),
    ('sabonete-mel',   'Sabonete de Mel',
     'Sabonete artesanal de mel e aveia, 90g.',
     1800, 2, NULL, 0, 0, 1),
    ('caneca-personalizada', 'Caneca Personalizada',
     'Caneca de cerâmica com nome ou arte à sua escolha.',
     4900, 3, NULL, 5, 1, 1);

-- -----------------------------------------------------------------------------
-- Usuário administrador de exemplo
-- E-mail: admin@exemplo.com  |  Senha: admin123
-- Hash gerado com password_hash('admin123', PASSWORD_DEFAULT) (bcrypt).
-- -----------------------------------------------------------------------------
INSERT INTO users (nome, email, senha_hash, papel, aceita_email) VALUES
    ('Administrador', 'admin@exemplo.com',
     '$2y$10$5.g7ra4BZh5AlG..vAEAiesoO831jpAeirAum5/h31Ia8n4VPJroG',
     'admin', 0);
