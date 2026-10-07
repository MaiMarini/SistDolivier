<?php
/**
 * Link antigo de cadastro: abre o painel lateral na home, já em "Criar conta".
 */
if (usuario_atual() !== null) {
    redirect('');
}
flash('abrir_login', 'cadastro');
redirect('');
