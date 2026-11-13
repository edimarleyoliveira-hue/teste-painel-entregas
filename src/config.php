<?php

date_default_timezone_set('America/Sao_Paulo');

// Padrão do XAMPP: usuário root sem senha.
return [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'painel_entregas',
    'db_user' => 'root',
    'db_pass' => '',

    // Usuário fictício usado no histórico enquanto o painel não tem login.
    // Quando houver login, o nome passa a vir da sessão (ver usuarioAtual() em helpers.php).
    'usuario_demonstracao' => 'Usuário Demonstração',
];
