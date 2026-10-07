-- ============================================================
-- Pagamento online (Mercado Pago, Fase 3).
--  - orders.status ganha 'aguardando_pagamento' (pedido criado, ainda
--    não pago) e 'cancelado' (não pago no prazo de 24h).
--  - order_status_history acompanha os mesmos valores.
--  - orders.pago_em: quando o pagamento foi aprovado.
-- Os pedidos que já existem não mudam.
-- ATENÇÃO: rode UMA vez, ANTES de publicar o código desta fase.
-- ============================================================

ALTER TABLE `orders`
  MODIFY `status` ENUM('aguardando_pagamento','realizado','producao','pronto','finalizado','cancelado')
         NOT NULL DEFAULT 'realizado',
  ADD COLUMN `pago_em` DATETIME NULL AFTER `mp_payment_id`,
  ADD KEY `idx_orders_status_criado` (`status`, `criado_em`);

ALTER TABLE `order_status_history`
  MODIFY `status` ENUM('aguardando_pagamento','realizado','producao','pronto','finalizado','cancelado')
         NOT NULL;
