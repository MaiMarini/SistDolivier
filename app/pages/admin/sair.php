<?php
/**
 * Logout do painel: /admin/sair
 * Apaga a sessão e o cookie e volta para a loja.
 */
sessao_encerrar('Você saiu do painel.');
redirect('');
