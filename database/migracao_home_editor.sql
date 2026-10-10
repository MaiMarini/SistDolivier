-- ============================================================
-- Admin › Home (mapa da home).
--   banners.inicio / fim     período opcional (DATE). O banner aparece de
--                            "inicio" até o fim do dia de "fim" (fuso de
--                            Porto Velho); vazio = sempre.
--   banners.link_categoria_id  link para uma categoria pelo id (trocar o slug
--                            não quebra o link). "link" fica só para
--                            "Outro endereço".
--   settings.bloco_editorial_botao_categoria_id  o mesmo, para o botão do
--                            bloco editorial.
-- Converte os links atuais: /categoria/<slug> (relativo ou em
-- dolivier.com.br) de uma categoria existente vira a categoria; o resto
-- continua como "Outro endereço".
--
-- Compatível com MySQL 5.7 / MariaDB (sem window functions). IDEMPOTENTE.
-- ============================================================

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'banners' AND COLUMN_NAME = 'inicio');
SET @s := IF(@c = 0, 'ALTER TABLE `banners` ADD COLUMN `inicio` DATE NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'banners' AND COLUMN_NAME = 'fim');
SET @s := IF(@c = 0, 'ALTER TABLE `banners` ADD COLUMN `fim` DATE NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'banners' AND COLUMN_NAME = 'link_categoria_id');
SET @s := IF(@c = 0, 'ALTER TABLE `banners` ADD COLUMN `link_categoria_id` INT UNSIGNED NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Banners: /categoria/<slug> -> categoria (o slug vai até a próxima /, ? ou #).
UPDATE `banners` b
  JOIN `categories` c
    ON c.slug = SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(
                  SUBSTRING_INDEX(CONCAT('/', TRIM(b.link)), '/categoria/', -1),
                '/', 1), '?', 1), '#', 1)
   SET b.link_categoria_id = c.id, b.link = NULL
 WHERE b.link_categoria_id IS NULL
   AND CONCAT('/', TRIM(b.link)) LIKE '%/categoria/%'
   AND (TRIM(b.link) LIKE '/%' OR TRIM(b.link) LIKE 'categoria/%'
        OR TRIM(b.link) REGEXP '^https?://(www\\.)?dolivier\\.com\\.br/');

-- Bloco editorial: mesma conversão para o link do botão.
SET @cat := (
  SELECT c.id
    FROM `settings` s
    JOIN `categories` c
      ON c.slug = SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(
                    SUBSTRING_INDEX(CONCAT('/', TRIM(s.valor)), '/categoria/', -1),
                  '/', 1), '?', 1), '#', 1)
   WHERE s.chave = 'bloco_editorial_botao_link'
     AND CONCAT('/', TRIM(s.valor)) LIKE '%/categoria/%'
     AND (TRIM(s.valor) LIKE '/%' OR TRIM(s.valor) LIKE 'categoria/%'
          OR TRIM(s.valor) REGEXP '^https?://(www\\.)?dolivier\\.com\\.br/')
   LIMIT 1);

INSERT INTO `settings` (`chave`, `valor`)
SELECT 'bloco_editorial_botao_categoria_id', @cat FROM DUAL WHERE @cat IS NOT NULL
ON DUPLICATE KEY UPDATE `valor` = IF(`valor` IS NULL OR `valor` = '', VALUES(`valor`), `valor`);

UPDATE `settings` link
  JOIN `settings` cat ON cat.chave = 'bloco_editorial_botao_categoria_id' AND cat.valor <> ''
   SET link.valor = ''
 WHERE link.chave = 'bloco_editorial_botao_link';
