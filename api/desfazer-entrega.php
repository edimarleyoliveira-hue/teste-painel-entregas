<?php

require_once __DIR__ . '/../src/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['erro' => 'Método não permitido.'], 405);
}

try {
    $dados = lerCorpoJson();

    if ($dados === null) {
        responderJson(['erro' => 'Envie os dados em formato JSON.'], 400);
    }

    $id = inteiroPositivo($dados['id'] ?? null);

    if ($id === null) {
        responderJson(['erro' => 'Informe o número (id) da entrega a desfazer.'], 400);
    }

    $repositorio = new EntregaRepository(Database::conexao());

    if (!$repositorio->desfazerEntrega($id, usuarioAtual())) {
        responderJson(['erro' => 'Esta entrega não existe ou não está marcada como entregue.'], 409);
    }

    responderJson(['mensagem' => 'Entrega desfeita: ela voltou a ficar sem data de entrega.']);
} catch (Throwable $e) {
    responderErro($e);
}
