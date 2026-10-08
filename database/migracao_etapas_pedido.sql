-- ============================================================
-- Etapas do pedido (admin: lista + quadro de produção) e cancelamento com estorno.
--
-- orders.status:
--   aguardando_pagamento -> realizado ("Novo": pago, aguarda produção)
--   -> producao -> embalagem -> pronto
--      retirada: pronto -> finalizado ("Retirado")
--      motoboy:  pronto -> em_rota -> finalizado ("Entregue")
--   cancelado (a qualquer momento antes de finalizar)
-- Os pedidos existentes não mudam de status (só entram as etapas novas).
--
-- IDEMPOTENTE: pode rodar mais de uma vez. Faça um backup antes (Exportar).
-- ============================================================

-- 1) Etapas novas no ENUM (reaplicar é inofensivo).
ALTER TABLE `orders`
  MODIFY `status` ENUM('aguardando_pagamento','realizado','producao','embalagem','pronto','em_rota','finalizado','cancelado')
         NOT NULL DEFAULT 'realizado';
ALTER TABLE `order_status_history`
  MODIFY `status` ENUM('aguardando_pagamento','realizado','producao','embalagem','pronto','em_rota','finalizado','cancelado')
         NOT NULL;

-- 2) Histórico de mudanças de status (substitui order_status_history daqui em diante).
CREATE TABLE IF NOT EXISTS `pedido_historico` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`    INT UNSIGNED NOT NULL,
  `status_de`   VARCHAR(30)  NULL,
  `status_para` VARCHAR(30)  NOT NULL,
  `usuario_id`  INT UNSIGNED NULL,
  `origem`      ENUM('admin','cliente','sistema') NOT NULL,
  `observacao`  VARCHAR(300) NULL,
  `criado_em`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pedido_historico_order` (`order_id`),
  CONSTRAINT `fk_pedido_historico_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Colunas de cancelamento e estorno (só cria as que faltam).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'cancelado_por');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `cancelado_por` ENUM(''cliente'',''loja'',''sistema'') NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'cancelamento_motivo');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `cancelamento_motivo` VARCHAR(60) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'cancelamento_obs');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `cancelamento_obs` VARCHAR(300) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'cancelado_em');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `cancelado_em` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'estorno_id');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `estorno_id` VARCHAR(40) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'estorno_status');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `estorno_status` VARCHAR(20) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'estorno_valor_centavos');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `estorno_valor_centavos` INT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Data do estorno (para "Reembolso de R$ X enviado em ...").
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'estorno_em');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `estorno_em` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- pago_em já foi criado em migracao_pagamento.sql; garante caso falte.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'pago_em');
SET @s := IF(@c = 0, 'ALTER TABLE `orders` ADD COLUMN `pago_em` DATETIME NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
