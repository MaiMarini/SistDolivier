<?php
/**
 * Peças comuns da lista e do editor de tabelas nutricionais: diálogo "Sair sem
 * salvar?", aviso (toast) e as URLs para o JS. Espera $url_post.
 */
?>
<div class="tn-modal" data-tn-modal hidden>
    <div role="dialog" aria-modal="true" aria-labelledby="tn-modal-titulo">
        <h2 id="tn-modal-titulo">Sair sem salvar?</h2>
        <p data-tn-modal-texto>As alterações vão ser perdidas.</p>
        <div class="tn-modal-botoes">
            <button type="button" class="ap-btn ap-btn-linha" data-tn-ficar>Continuar editando</button>
            <button type="button" class="ap-btn ap-btn-perigo-cheio" data-tn-sair>Descartar</button>
        </div>
    </div>
</div>
<div class="pe-toast" data-tn-toast role="status" aria-live="polite" hidden></div>
<script>window.TN_URL = <?= json_encode(['post' => $url_post, 'csrf' => csrf_token()]) ?>;</script>
