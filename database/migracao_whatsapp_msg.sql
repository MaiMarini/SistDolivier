-- ============================================================
-- Mensagem do botão do WhatsApp no rodapé (settings.whatsapp_msg).
-- É uma mensagem geral de contato, sem produto e sem link: troca o texto antigo
-- ("Olá! Tenho interesse no produto: {produto}") pelo novo. Só troca se o texto
-- gravado estiver vazio ou ainda tiver {produto}/{link}; um texto já ajustado
-- à mão fica como está. IDEMPOTENTE.
-- ============================================================

INSERT INTO `settings` (`chave`, `valor`)
VALUES ('whatsapp_msg', 'Olá! Vim pelo site da D''Olivier e gostaria de falar com vocês.')
ON DUPLICATE KEY UPDATE `valor` = IF(
    `valor` IS NULL OR TRIM(`valor`) = '' OR `valor` LIKE '%{produto}%' OR `valor` LIKE '%{link}%',
    VALUES(`valor`),
    `valor`
);
