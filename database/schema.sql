-- =============================================================================
-- Esquema do banco da loja virtual
-- Motor: InnoDB | Charset: utf8mb4 | Dinheiro sempre em CENTAVOS (inteiro)
-- Compatível com importação direta (mysql < schema.sql).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS order_status_history;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS product_images;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS banners;
DROP TABLE IF EXISTS email_campaigns;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS settings;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- Configurações da loja (chave/valor). Tudo que é "de marca" mora aqui.
-- -----------------------------------------------------------------------------
CREATE TABLE settings (
    chave VARCHAR(100) NOT NULL,
    valor TEXT NULL,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Usuários (clientes e administradores)
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(150) NOT NULL,
    cpf         VARCHAR(14) NULL,
    email       VARCHAR(190) NOT NULL,
    senha_hash  VARCHAR(255) NOT NULL,
    telefone    VARCHAR(20) NULL,
    endereco    TEXT NULL,
    papel       ENUM('cliente','admin') NOT NULL DEFAULT 'cliente',
    aceita_email TINYINT(1) NOT NULL DEFAULT 1,
    criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Categorias de produtos
-- -----------------------------------------------------------------------------
CREATE TABLE categories (
    id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug  VARCHAR(150) NOT NULL,
    nome  VARCHAR(150) NOT NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Produtos
-- -----------------------------------------------------------------------------
CREATE TABLE products (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug           VARCHAR(150) NOT NULL,
    nome           VARCHAR(150) NOT NULL,
    descricao      TEXT NULL,
    regras_produto TEXT NULL,
    preco_centavos INT UNSIGNED NOT NULL DEFAULT 0,
    category_id    INT UNSIGNED NULL,
    imagem         VARCHAR(255) NULL,
    dias_producao  INT UNSIGNED NOT NULL DEFAULT 0,
    personalizavel TINYINT(1) NOT NULL DEFAULT 0,
    ativo          TINYINT(1) NOT NULL DEFAULT 1,
    criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_slug (slug),
    KEY idx_products_category (category_id),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Imagens dos produtos (galeria). A "capa" continua em products.imagem.
-- -----------------------------------------------------------------------------
CREATE TABLE product_images (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    arquivo    VARCHAR(255) NOT NULL,
    ordem      INT NOT NULL DEFAULT 0,
    criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_product_images_product (product_id),
    CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Banners da home
-- -----------------------------------------------------------------------------
CREATE TABLE banners (
    id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    imagem VARCHAR(255) NOT NULL,
    titulo VARCHAR(150) NULL,
    link   VARCHAR(255) NULL,
    ordem  INT NOT NULL DEFAULT 0,
    ativo  TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Pedidos
-- -----------------------------------------------------------------------------
CREATE TABLE orders (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NULL,
    status           ENUM('realizado','producao','pronto','finalizado')
                         NOT NULL DEFAULT 'realizado',
    entrega          ENUM('motoboy','retirada') NOT NULL DEFAULT 'retirada',
    subtotal_centavos INT UNSIGNED NOT NULL DEFAULT 0,
    frete_centavos    INT UNSIGNED NOT NULL DEFAULT 0,
    total_centavos    INT UNSIGNED NOT NULL DEFAULT 0,
    pagamento         VARCHAR(50) NULL,          -- ex.: pix, debito, credito
    pagamento_status  VARCHAR(50) NULL,          -- ex.: pendente, aprovado
    mp_preference_id  VARCHAR(100) NULL,         -- Mercado Pago (fase futura)
    mp_payment_id     VARCHAR(100) NULL,
    aceitou_termos    TINYINT(1) NOT NULL DEFAULT 0,
    observacoes       TEXT NULL,
    criado_em         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_orders_user (user_id),
    KEY idx_orders_status (status),
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Itens do pedido (guardam um "retrato" do produto no momento da compra)
-- -----------------------------------------------------------------------------
CREATE TABLE order_items (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id       INT UNSIGNED NOT NULL,
    product_id     INT UNSIGNED NULL,
    nome           VARCHAR(150) NOT NULL,
    preco_centavos INT UNSIGNED NOT NULL DEFAULT 0,
    quantidade     INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_order_items_order (order_id),
    KEY idx_order_items_product (product_id),
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Histórico de status dos pedidos
-- -----------------------------------------------------------------------------
CREATE TABLE order_status_history (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id  INT UNSIGNED NOT NULL,
    status    ENUM('realizado','producao','pronto','finalizado') NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_history_order (order_id),
    CONSTRAINT fk_status_history_order
        FOREIGN KEY (order_id) REFERENCES orders (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Campanhas de e-mail promocional (fase futura)
-- -----------------------------------------------------------------------------
CREATE TABLE email_campaigns (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assunto    VARCHAR(200) NOT NULL,
    corpo_html LONGTEXT NULL,
    status     VARCHAR(50) NOT NULL DEFAULT 'rascunho', -- rascunho, enviando, enviado
    criado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
