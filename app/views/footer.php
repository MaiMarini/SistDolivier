<?php
/**
 * Rodapé do site: identidade da loja, observação de entrega, WhatsApp e ano.
 */
?>
<footer class="rodape">
    <!-- Borda superior em arcos largos com fio dourado (estica em qualquer largura) -->
    <svg class="rodape-curva" viewBox="0 0 1200 80" preserveAspectRatio="none" aria-hidden="true">
        <!-- 1) fio dourado: arco mais alto -->
        <path class="rodape-curva-fio"
              d="M0,38 Q150,8 300,38 T600,38 T900,38 T1200,38"/>
        <!-- 2) corpo marrom: arco paralelo ~14px abaixo, fechado até a base -->
        <path class="rodape-curva-corpo"
              d="M0,52 Q150,22 300,52 T600,52 T900,52 T1200,52 L1200,80 L0,80 Z"/>
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
