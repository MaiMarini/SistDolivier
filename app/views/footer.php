<?php
/**
 * Rodapé do site: identidade da loja, observação de entrega, WhatsApp e ano.
 */
?>
<footer class="rodape">
    <!-- Borda superior em arcos largos com fio dourado (estica em qualquer largura) -->
    <svg class="rodape-curva" viewBox="0 0 1200 120" preserveAspectRatio="none" aria-hidden="true">
        <!-- 1) fio dourado: só o contorno dos arcos -->
        <path class="rodape-curva-fio"
              d="M0,70 Q150,0 300,70 T600,70 T900,70 T1200,70"/>
        <!-- 2) corpo marrom: mesmo traçado, fechado até a base, por cima -->
        <path class="rodape-curva-corpo"
              d="M0,70 Q150,0 300,70 T600,70 T900,70 T1200,70 L1200,120 L0,120 Z"/>
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
