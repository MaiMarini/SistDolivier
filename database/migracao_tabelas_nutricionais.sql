-- ============================================================
-- Tabelas nutricionais
--
-- Cada tabela nutricional guarda nome, aviso de alérgenos e os
-- valores no padrão ANVISA. Um produto pode ter várias tabelas
-- nutricionais associadas (produto simples = 1; kit = várias),
-- e uma tabela nutricional pode ser usada em vários produtos.
--
-- MySQL/Percona 5.7 · utf8mb4 · InnoDB.
-- Valores numéricos como DECIMAL, NULL quando não preenchidos.
-- ============================================================

CREATE TABLE IF NOT EXISTS `tabelas_nutricionais` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                   VARCHAR(120) NOT NULL,
  `alergenicos`            TEXT NULL,
  `nutri_porcao`           VARCHAR(60)  NULL,
  `nutri_medida_caseira`   VARCHAR(60)  NULL,
  `nutri_valor_energetico` DECIMAL(8,2) NULL,
  `nutri_carboidratos`     DECIMAL(8,2) NULL,
  `nutri_acucares_totais`  DECIMAL(8,2) NULL,
  `nutri_acucares_add`     DECIMAL(8,2) NULL,
  `nutri_proteinas`        DECIMAL(8,2) NULL,
  `nutri_gorduras_totais`  DECIMAL(8,2) NULL,
  `nutri_gorduras_sat`     DECIMAL(8,2) NULL,
  `nutri_gorduras_trans`   DECIMAL(8,2) NULL,
  `nutri_fibra`            DECIMAL(8,2) NULL,
  `nutri_sodio`            DECIMAL(8,2) NULL,
  `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ligação produto <-> tabela nutricional (muitos-para-muitos).
-- `ordem` controla a sequência de exibição das tabelas no produto.
CREATE TABLE IF NOT EXISTS `produto_tabelas_nutricionais` (
  `produto_id`            INT UNSIGNED NOT NULL,
  `tabela_nutricional_id` INT UNSIGNED NOT NULL,
  `ordem`                 INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`produto_id`, `tabela_nutricional_id`),
  KEY `idx_ptn_tabela` (`tabela_nutricional_id`),
  CONSTRAINT `fk_ptn_produto` FOREIGN KEY (`produto_id`)
      REFERENCES `products`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ptn_tabela` FOREIGN KEY (`tabela_nutricional_id`)
      REFERENCES `tabelas_nutricionais`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
