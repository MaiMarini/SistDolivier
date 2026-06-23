<?php
/**
 * Logout do painel: /admin/sair
 * Encerra a sessão do usuário e volta para /admin/entrar.
 */
unset($_SESSION['usuario']);
session_regenerate_id(true);

flash('sucesso', 'Você saiu do painel.');
redirect('admin/entrar');
