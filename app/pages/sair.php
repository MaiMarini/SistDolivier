<?php
/**
 * Logout: /sair
 * Apaga a sessão e o cookie (o carrinho passa para a sessão nova) e volta para a home.
 */

sessao_encerrar('Você saiu da sua conta.');
redirect('');
