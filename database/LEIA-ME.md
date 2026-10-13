# Banco de dados: migrações

Esta pasta guarda o esquema inicial (`schema.sql`) e as **migrações**: scripts que mudam o banco
que já está no ar. A pasta é bloqueada na web (`database/.htaccess`).

## Como rodar uma migração

1. No cPanel, abra o **phpMyAdmin** e escolha o banco da loja.
2. Faça um backup antes (**Exportar** → Rápido → SQL).
3. Abra a aba **Importar**, escolha o arquivo `.sql` e clique em **Importar**.
   Também dá para abrir a aba **SQL**, colar o conteúdo do arquivo e clicar em **Executar**.
4. Rode **depois do deploy** do código da mesma etapa, a não ser que a tabela abaixo diga "antes".

Regras do servidor: o MySQL da HostGator não aceita *window functions* (`ROW_NUMBER`, `OVER`,
`PARTITION BY`), e nenhuma migração usa.

## Pendentes no servidor

| Migração | Para quê | O que acontece sem ela |
|---|---|---|
| `migracao_colecoes.sql` | Cria `colecoes` (Admin › Coleções: data limite para encomendar e data de entrega/retirada) e `products.colecao_id`. | Admin › Coleções pede para rodar a migração; o campo "Coleção" não aparece no produto. |
| `migracao_colecoes_capa.sql` (depois da de cima) | Início da campanha (`colecoes.abre_em`), capa e texto alternativo da coleção (`colecoes.capa`, `capa_alt`) e o endereço próprio (`colecoes.slug`, ex.: `/colecoes-sazonais/halloween`). | A categoria continua como lista simples de produtos (sem cartões de coleção) e o formulário da coleção não mostra Início da campanha nem Capa (pede a migração). |
| `migracao_aceite_termos.sql` | Guarda no pedido o texto das regras e prazos que o cliente aceitou no checkout (`orders.termos_texto`, `termos_aceitos_em`). | O checkout funciona e o modal de aceite aparece, mas o pedido sai sem a cópia do texto aceito. |

Para conferir se já rodou:

```sql
SHOW TABLES LIKE 'colecoes';    -- 1 linha = migracao_colecoes.sql já rodou
SHOW COLUMNS FROM colecoes LIKE 'capa';   -- 1 linha = migracao_colecoes_capa.sql já rodou
SHOW COLUMNS FROM orders LIKE 'termos_texto';   -- 1 linha = migracao_aceite_termos.sql já rodou
```

Quando rodar, tire-a desta lista. Até 12/10/2026, todas as anteriores estavam rodadas no banco de
produção (a última foi `migracao_recuperar_senha.sql`).

## Todas as migrações, na ordem

**Pode rodar de novo?**
- **Sim:** o script confere o que já existe e não muda nada na segunda vez.
- **Uma vez:** rodar de novo dá erro de coluna ou índice duplicado. O erro não estraga nada, mas pare ali.

| Data | Arquivo | O que faz | Pode rodar de novo? |
|---|---|---|---|
| 23/06 | `migracao_regras_produto.sql` | Regras específicas do produto. Rodar **antes** do deploy. | Uma vez |
| 24/06 | `migracao_product_images.sql` | Galeria de imagens por produto. | Sim |
| 24/06 | `migracao_destaque.sql` | Marca "destaque" do produto (home). | Uma vez |
| 25/06 | `migracao_bloco_editorial.sql` | Textos do bloco editorial da home (`settings`). | Sim |
| 25/06 | `migracao_marquee.sql` | Tabela das frases da faixa da home. | Sim |
| 25/06 | `migracao_redes_sociais.sql` | Redes sociais do rodapé (`settings`). | Sim |
| 03/07 | `migracao_permite_personalizacao.sql` | Botão "Personalizar" por produto. | Uma vez |
| 03/07 | `migracao_tabelas_nutricionais.sql` | Tabelas nutricionais e ligação com produtos. | Sim |
| 03/07 | `migracao_porcao_individual.sql` | Renomeia "medida caseira" para "porção individual". | Uma vez |
| 03/07 | `migracao_porcao_individual_g.sql` | Gramas da porção individual (base de 100 g). | Uma vez |
| 06/07 | `migracao_bloco_editorial_midia.sql` | Bloco editorial com foto ou vídeo. | Sim |
| 07/07 | `migracao_colecoes_imagem_lateral.sql` | Imagem lateral da seção Coleções. | Sim |
| 13/07 | `migracao_frete.sql` | Frete por distância: configurações, dados da entrega no pedido e cache. | Uma vez |
| 13/07 | `migracao_parcela_minima.sql` | Regras de parcelamento. | Sim |
| 05/10 | `migracao_endereco_cliente.sql` | Endereço do cliente em campos separados. Rodar **antes** do deploy. | Uma vez |
| 05/10 | `migracao_presente.sql` | Pedido para presente. Depois de `migracao_frete.sql`, **antes** do deploy. | Uma vez |
| 06/10 | `migracao_cpf_unico.sql` | CPF único por conta (falha se houver CPF repetido). | Uma vez |
| 07/10 | `migracao_pagamento.sql` | Status "aguardando pagamento" e "cancelado"; `pago_em`. Rodar **antes** do deploy. | Uma vez |
| 07/10 | `migracao_pagamento_detalhes.sql` | Tipo do pagamento e link do Pix/boleto. | Sim |
| 07/10 | `migracao_pagamento_eventos.sql` | Histórico das tentativas de pagamento. | Sim |
| 08/10 | `migracao_etapas_pedido.sql` | Etapas do pedido (Embalagem, Em rota) e cancelamento com estorno. | Sim |
| 08/10 | `migracao_estorno_tentativa.sql` | Contador de tentativas de estorno. | Sim |
| 09/10 | `migracao_indices_painel.sql` | Índices do Painel do admin. | Sim |
| 10/10 | `migracao_ordem_produtos.sql` | Ordem dos produtos dentro da categoria. | Sim |
| 10/10 | `migracao_produto_editor.sql` | Editor de produto (última alteração, fotos temporárias). | Sim |
| 10/10 | `migracao_tabelas_nutri_editor.sql` | Editor de tabelas nutricionais (alérgenos, exclusão com "Desfazer"). | Sim |
| 10/10 | `migracao_categorias_editor.sql` | Categorias com edição na linha e exclusão com "Desfazer". | Sim |
| 10/10 | `migracao_whatsapp_msg.sql` | Nova mensagem do WhatsApp do rodapé. | Sim |
| 10/10 | `migracao_home_editor.sql` | Home do admin: período dos banners e links por categoria. | Sim |
| 11/10 | `migracao_seguranca_login.sql` | Limite de tentativas de login; remove `maps_api_key`. | Sim |
| 11/10 | `migracao_email_pedido.sql` | Controle dos e-mails de pedido. | Sim |
| 12/10 | `migracao_recuperar_senha.sql` | Recuperação de senha e encerramento das outras sessões ao trocar a senha. | Sim |
| 12/10 | `migracao_colecoes.sql` | Coleções (datas de encomenda e de entrega/retirada) e a coleção do produto. | Sim |
| 12/10 | `migracao_colecoes_capa.sql` | Início da campanha, capa da coleção e endereço próprio na loja. | Sim |
| 13/10 | `migracao_aceite_termos.sql` | Cópia das regras e prazos aceitos no pedido. | Sim |

## Instalação nova

Rode `schema.sql` (cria tudo do zero e **apaga** as tabelas existentes) e depois as migrações na
ordem acima. O `schema.sql` é de 05/10, e parte das migrações antigas já está nele. Se uma marcada
"Uma vez" der erro de coluna duplicada, ela já estava no esquema: siga para a próxima.
`seed_exemplo.sql` traz dados de exemplo e é só para testes.

## Ao criar uma migração nova

- Nome: `migracao_<assunto>.sql`.
- Comece com um comentário dizendo o que muda e se pode rodar de novo.
- Prefira o formato que pode rodar de novo: confira em `information_schema` antes do `ALTER TABLE`
  (veja `migracao_email_pedido.sql`).
- Acrescente a migração nesta página: na lista de pendentes e na tabela completa.
