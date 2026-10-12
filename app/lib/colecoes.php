<?php
/**
 * Coleções: grupos de produtos dentro de uma categoria (ex.: "Dia das Mães 2027"
 * em Coleções Sazonais), com a mesma data limite para encomendar e a mesma data
 * de entrega/retirada. Produto sem coleção usa o prazo de produção (dias úteis).
 * Toleram a migração migracao_colecoes.sql ainda não rodada (listas vazias).
 */

/** Coleções não excluídas (id, category_id, nome, encomendas_ate, entrega_em, ativo), na ordem. */
function colecoes_admin(): array
{
    try {
        return db()->query('SELECT id, category_id, nome, encomendas_ate, entrega_em, ativo
                              FROM colecoes WHERE excluida_em IS NULL ORDER BY ordem ASC, id ASC')->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** A coleção existe, não foi excluída e é desta categoria? */
function colecao_valida(int $id, int $category_id): bool
{
    try {
        $st = db()->prepare('SELECT 1 FROM colecoes WHERE id = ? AND category_id = ? AND excluida_em IS NULL');
        $st->execute([$id, $category_id]);
        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/** "dd/mm/aaaa" de uma data do banco (Y-m-d). */
function data_br(?string $ymd): string
{
    return $ymd ? date('d/m/Y', strtotime($ymd)) : '';
}
