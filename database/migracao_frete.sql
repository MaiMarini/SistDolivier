-- ============================================================
-- Frete por distância + retrato da entrega no pedido.
--  1) settings do frete (defaults; edite depois)
--  2) colunas de "retrato" da entrega em orders  -> RODAR UMA VEZ
--  3) tabela de cache de distância
-- ============================================================

-- 1) Configurações do frete ------------------------------------------------
-- frete_provedor: 'off' (ainda não calcula) | 'google' | 'haversine'
INSERT INTO `settings` (`chave`, `valor`) VALUES
  ('frete_provedor',        'off'),
  ('frete_base_km',         '5'),      -- primeiros 5 km
  ('frete_base_centavos',   '900'),    -- R$ 9,00 fixos nos primeiros km
  ('frete_por_km_centavos', '100'),    -- R$ 1,00 por km extra (ceil)
  ('entrega_raio_max_km',   '15'),     -- acima disso, só retirada
  ('loja_endereco',         ''),       -- origem (texto) para o provedor
  ('loja_lat',              ''),       -- origem (coordenada)
  ('loja_lng',              ''),
  ('retirada_endereco',     ''),       -- endereço/instruções da retirada
  ('maps_api_key',          '')        -- chave do provedor (fica só no servidor)
ON DUPLICATE KEY UPDATE `chave` = `chave`;

-- 2) Retrato da entrega no pedido ------------------------------------------
-- orders já tem: entrega, frete_centavos, subtotal/total, aceitou_termos, observacoes.
-- ATENÇÃO: ALTER TABLE não é idempotente no MySQL — rode este bloco UMA vez.
ALTER TABLE `orders`
  ADD COLUMN `endereco_entrega`     TEXT         NULL AFTER `observacoes`,
  ADD COLUMN `contato_nome`         VARCHAR(150) NULL AFTER `endereco_entrega`,
  ADD COLUMN `contato_telefone`     VARCHAR(20)  NULL AFTER `contato_nome`,
  ADD COLUMN `entrega_distancia_km` DECIMAL(6,2) NULL AFTER `contato_telefone`;

-- 3) Cache de distância (evita repetir chamadas ao provedor) ---------------
CREATE TABLE IF NOT EXISTS `frete_cache` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chave`        VARCHAR(190) NOT NULL,   -- ex.: cep+numero normalizado
  `distancia_km` DECIMAL(6,2) NULL,
  `lat`          DECIMAL(10,7) NULL,
  `lng`          DECIMAL(10,7) NULL,
  `criado_em`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_frete_cache_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
