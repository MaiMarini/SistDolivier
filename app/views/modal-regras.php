<?php
/**
 * Modal de regras com ACEITE obrigatório.
 * Incluído pelo layout em todas as páginas; escondido por padrão (sem a classe
 * "aberto"). Aberto por qualquer elemento com data-abrir-modal="modal-regras"
 * (ex.: o botão "Finalizar compra" do carrinho).
 *
 * O botão "Concordar e continuar" começa DESABILITADO; o app.js o habilita
 * somente quando o checkbox de aceite é marcado. Ao continuar, navega para
 * /carrinho?finalizar=1 (a finalização real vem na fase de checkout).
 */
?>
<div class="modal" id="modal-regras" role="dialog" aria-modal="true"
     aria-labelledby="modal-regras-titulo">
    <div class="modal-conteudo">
        <button class="modal-fechar" type="button" data-fechar-modal
                aria-label="Fechar">&times;</button>

        <h2 id="modal-regras-titulo">Antes de finalizar</h2>

        <div><?= nl2br(e(cfg('regras_texto', 'Em breve.'))) ?></div>

        <form method="get" action="<?= e(url('carrinho')) ?>" class="mt-1">
            <!-- GET para /carrinho?finalizar=1 (sem 'name' no checkbox, para a
                 URL ficar exatamente como esperado). -->
            <input type="hidden" name="finalizar" value="1">

            <div class="campo campo-inline">
                <input type="checkbox" id="aceite">
                <label for="aceite">Li e concordo com as regras e o prazo de produção.</label>
            </div>

            <div class="produto-acoes">
                <button class="btn sec" type="button" data-fechar-modal>Voltar</button>
                <button class="btn" type="submit" id="btn-finalizar" disabled>
                    Concordar e continuar
                </button>
            </div>
        </form>
    </div>
</div>
