-- ============================================================
-- Tentativas de pagamento de cada pedido (para o histórico do admin).
-- Uma linha a cada mudança de situação de um pagamento do Mercado Pago:
-- aprovado, recusado, cancelado pela cliente, em análise, Pix gerado, estornado...
-- IDEMPOTENTE: pode rodar mais de uma vez.
-- ============================================================
CREATE TABLE IF NOT EXISTS `order_payment_events` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`       INT UNSIGNED NOT NULL,
  `mp_payment_id`  VARCHAR(100) NOT NULL,
  `status`         VARCHAR(30)  NOT NULL,   -- mesmo vocabulário de orders.pagamento_status
  `forma`          VARCHAR(30)  NULL,       -- pix | credito | debito | boleto
  `valor_centavos` INT UNSIGNED NULL,
  `criado_em`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payment_events_order` (`order_id`),
  CONSTRAINT `fk_payment_events_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
