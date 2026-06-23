<?php
/**
 * Rodapé do site: identidade da loja, observação de entrega, WhatsApp e ano.
 */
?>
<footer class="rodape">
    <div class="container">
        <h3><?= e(cfg('site_nome', 'Minha Loja')) ?></h3>

        <?php if (cfg('site_descricao')): ?>
            <p><?= e(cfg('site_descricao')) ?></p>
        <?php endif; ?>

        <?php if (cfg('entrega_obs')): ?>
            <p><?= e(cfg('entrega_obs')) ?></p>
        <?php endif; ?>

        <p>
            <a class="btn wpp" href="<?= e(whatsapp_link()) ?>"
               target="_blank" rel="noopener">Falar no WhatsApp</a>
        </p>

        <p class="copyright">
            &copy; <?= e(date('Y')) ?> <?= e(cfg('site_nome', 'Minha Loja')) ?>.
            Todos os direitos reservados.
        </p>
    </div>
</footer>
