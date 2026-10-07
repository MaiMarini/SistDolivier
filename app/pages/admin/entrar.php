<?php
/**
 * /admin/entrar — o login do admin agora é feito pelo painel lateral (o mesmo
 * dos clientes; quem tem papel "admin" vai para o painel). Este endereço só
 * existe para links salvos: abre o painel lateral na home.
 */
$u = usuario_atual();
if ($u !== null && !empty($u['is_admin'])) {
    redirect('admin');
}
abrir_login();
