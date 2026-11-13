<?php

require_once __DIR__ . '/../src/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderJson(['erro' => 'Método não permitido.'], 405);
}

$filtros = [
    'empresa' => parametroGet('empresa'),
    'departamento' => parametroGet('departamento'),
    'status' => parametroGet('status'),
];

if (mb_strlen($filtros['empresa']) > 150) {
    responderJson(['erro' => 'A busca por empresa aceita no máximo 150 caracteres.'], 400);
}

if ($filtros['departamento'] !== '' && !in_array($filtros['departamento'], EntregaRepository::DEPARTAMENTOS, true)) {
    responderJson(['erro' => 'Departamento inválido. Use Fiscal, Pessoal ou Contábil.'], 400);
}

if ($filtros['status'] !== '' && !in_array($filtros['status'], EntregaRepository::statusValidos(), true)) {
    responderJson(['erro' => 'Status inválido. Use pendente, atrasada ou entregue.'], 400);
}

try {
    $repositorio = new EntregaRepository(Database::conexao());
    responderJson($repositorio->listar($filtros));
} catch (Throwable $e) {
    responderErro($e);
}
