<?php
/**
 * Categorias: listas para o admin sem as excluídas (exclusão lógica com
 * "Desfazer" em Admin › Categorias). Toleram a migração ainda não rodada.
 *
 * A loja não precisa disto: ao excluir, a categoria também fica com ativo = 0,
 * e o menu, a home e a página da categoria já filtram por ativo = 1.
 */

/** Categorias para selects/agrupamentos do admin (id, nome), na ordem do menu. */
function categorias_admin(): array
{
    foreach (['WHERE excluida_em IS NULL', ''] as $filtro) {
        try {
            return db()->query("SELECT id, nome FROM categories $filtro ORDER BY ordem ASC, id ASC")->fetchAll();
        } catch (PDOException $e) {
            if ($filtro === '' || $e->getCode() !== '42S22') {
                return [];
            }
        }
    }
    return [];
}

/** A categoria existe e não foi excluída? */
function categoria_valida(int $id): bool
{
    foreach (['AND excluida_em IS NULL', ''] as $filtro) {
        try {
            $st = db()->prepare("SELECT 1 FROM categories WHERE id = ? $filtro");
            $st->execute([$id]);
            return (bool) $st->fetchColumn();
        } catch (PDOException $e) {
            if ($filtro === '' || $e->getCode() !== '42S22') {
                return false;
            }
        }
    }
    return false;
}
