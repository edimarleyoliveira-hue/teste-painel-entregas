<?php

require_once __DIR__ . '/../src/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderJson(['erro' => 'Método não permitido.'], 405);
}

try {
    $repositorio = new EmpresaRepository(Database::conexao());
    responderJson($repositorio->listar());
} catch (Throwable $e) {
    responderErro($e);
}
