<?php

require_once __DIR__ . '/../src/helpers.php';

// Limite de entregas por requisição, para ninguém enviar uma lista gigante.
const LIMITE_POR_VEZ = 500;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(['erro' => 'Método não permitido.'], 405);
}

try {
    $dados = lerCorpoJson();

    if ($dados === null) {
        responderJson(['erro' => 'Envie os dados em formato JSON.'], 400);
    }

    $ids = $dados['ids'] ?? null;

    if (!is_array($ids) || $ids === []) {
        responderJson(['erro' => 'Selecione ao menos uma entrega para marcar como entregue.'], 400);
    }

    if (count($ids) > LIMITE_POR_VEZ) {
        responderJson(['erro' => 'Marque no máximo ' . LIMITE_POR_VEZ . ' entregas por vez.'], 400);
    }

    // Só aceita números inteiros positivos; repetidos contam uma vez só.
    $idsValidos = [];
    foreach ($ids as $id) {
        $numero = inteiroPositivo($id);

        if ($numero === null) {
            responderJson(['erro' => 'Lista de entregas inválida: envie apenas os números (id) das entregas.'], 400);
        }

        $idsValidos[$numero] = $numero;
    }

    $repositorio = new EntregaRepository(Database::conexao());

    // Data de entrega opcional (bônus). Sem data, vale a data de hoje.
    $valorData = $dados['data_entrega'] ?? null;
    $dataEntrega = is_string($valorData) ? trim($valorData) : null;

    if ($valorData !== null && !is_string($valorData)) {
        responderJson(['erro' => 'Data de entrega inválida: envie a data como texto no formato AAAA-MM-DD.'], 400);
    } elseif ($dataEntrega === null || $dataEntrega === '') {
        $dataEntrega = null;
    } elseif (!dataValida($dataEntrega)) {
        responderJson(['erro' => 'Data de entrega inválida: use o formato AAAA-MM-DD com uma data que exista no calendário.'], 400);
    } elseif ($dataEntrega > $repositorio->hoje()) {
        // Datas AAAA-MM-DD podem ser comparadas como texto.
        responderJson(['erro' => 'A data de entrega não pode ser uma data futura.'], 400);
    }

    $marcadas = $repositorio->marcarComoEntregues(array_values($idsValidos), $dataEntrega, usuarioAtual());

    $mensagem = $marcadas === 1
        ? '1 entrega marcada como entregue.'
        : "{$marcadas} entregas marcadas como entregues.";

    $ignoradas = count($idsValidos) - $marcadas;
    if ($ignoradas === 1) {
        $mensagem .= ' 1 entrega não foi alterada porque já estava entregue ou não existe.';
    } elseif ($ignoradas > 1) {
        $mensagem .= " {$ignoradas} entregas não foram alteradas porque já estavam entregues ou não existem.";
    }

    responderJson(['marcadas' => $marcadas, 'mensagem' => $mensagem]);
} catch (Throwable $e) {
    responderErro($e);
}
