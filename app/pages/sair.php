<?php
/**
 * Logout: /sair
 * Encerra a sessão do usuário (mantém o carrinho) e volta para a home.
 */

unset($_SESSION['usuario']);
session_regenerate_id(true); // renova o id após mudar o estado de login

flash('sucesso', 'Você saiu da sua conta.');
redirect('');
