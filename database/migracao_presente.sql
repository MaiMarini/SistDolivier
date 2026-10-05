-- ============================================================
-- Pedido para presente (checkout): quem recebe + mensagem do cartão.
-- ATENÇÃO: ALTER TABLE não é idempotente no MySQL — rode UMA vez,
-- DEPOIS de migracao_frete.sql e ANTES de publicar o código.
-- ============================================================
ALTER TABLE `orders`
  ADD COLUMN `presente`          TINYINT(1)   NOT NULL DEFAULT 0 AFTER `entrega_distancia_km`,
  ADD COLUMN `presente_para`     VARCHAR(150) NULL AFTER `presente`,
  ADD COLUMN `presente_telefone` VARCHAR(20)  NULL AFTER `presente_para`,
  ADD COLUMN `presente_mensagem` TEXT         NULL AFTER `presente_telefone`;
