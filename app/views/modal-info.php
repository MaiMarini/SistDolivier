<?php
/**
 * Modal informativo genérico (somente leitura, sem checkbox).
 * Separado do modal de aceite do checkout. Espera:
 *   $modal_id       (string) id único do modal (acionado por data-abrir-modal)
 *   $modal_titulo   (string) título exibido
 *   $modal_conteudo (string) HTML do corpo JÁ ESCAPADO/preparado pelo chamador
 */
$modal_id       = isset($modal_id) ? $modal_id : 'modal-info';
$modal_titulo   = isset($modal_titulo) ? $modal_titulo : '';
$modal_conteudo = isset($modal_conteudo) ? $modal_conteudo : '';
?>
<div class="modal" id="<?= e($modal_id) ?>" role="dialog" aria-modal="true"
     aria-labelledby="<?= e($modal_id) ?>-titulo">
    <div class="modal-conteudo">
        <button class="modal-fechar" type="button" data-fechar-modal
                aria-label="Fechar">&times;</button>
        <h2 id="<?= e($modal_id) ?>-titulo"><?= e($modal_titulo) ?></h2>
        <div><?= $modal_conteudo ?></div>
        <p class="mt-1">
            <button class="btn sec" type="button" data-fechar-modal>Fechar</button>
        </p>
    </div>
</div>
