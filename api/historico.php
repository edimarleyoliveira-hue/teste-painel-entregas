<?php

require_once __DIR__ . '/../src/helpers.php';

// GET ?entrega_id=N: histórico de ações de uma entrega (botão "Detalhes" da tela de Entregas).
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responderJson(['erro' => 'Método não permitido.'], 405);
}

try {
    $entregaId = inteiroPositivo(parametroGet('entrega_id'));
    if ($entregaId === null) {
        responderJson(['erro' => 'Informe a entrega (?entrega_id=).'], 400);
    }

    responderJson((new HistoricoRepository(Database::conexao()))->porEntrega($entregaId));
} catch (Throwable $e) {
    responderErro($e);
}
