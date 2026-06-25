<?php
/**
 * Rodapé do site: identidade da loja, observação de entrega, WhatsApp e ano.
 */
?>
<footer class="rodape">
    <!-- Borda superior curva (estica em qualquer largura) -->
    <svg class="rodape-curva" viewBox="0 0 1440 48" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,48 L0,24 Q720,-12 1440,24 L1440,48 Z" fill="currentColor"/>
    </svg>

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

        <?php
        // Redes sociais (mostra só as preenchidas nas configurações).
        $redes = [
            'Instagram' => cfg('rede_instagram', ''),
            'Facebook'  => cfg('rede_facebook', ''),
            'TikTok'    => cfg('rede_tiktok', ''),
        ];
        $redes = array_filter($redes);
        ?>
        <?php if (!empty($redes)): ?>
            <p class="rodape-redes">
                <?php foreach ($redes as $nome => $link): ?>
                    <a href="<?= e($link) ?>" target="_blank" rel="noopener"><?= e($nome) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <?php if (cfg('endereco')): ?>
            <p><?= e(cfg('endereco')) ?></p>
        <?php endif; ?>

        <p class="copyright">
            &copy; <?= e(date('Y')) ?> <?= e(cfg('site_nome', 'Minha Loja')) ?>.
            <?php if (cfg('cnpj')): ?>CNPJ: <?= e(cfg('cnpj')) ?>.<?php endif; ?>
            Todos os direitos reservados.
        </p>
    </div>
</footer>
