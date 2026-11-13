<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EntregaRepository.php';
require_once __DIR__ . '/EmpresaRepository.php';
require_once __DIR__ . '/ObrigacaoRepository.php';

function responderJson($dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function responderErro(Throwable $e): void
{
    // O detalhe técnico fica só no log do servidor, nunca na resposta.
    error_log($e->getMessage());

    responderJson([
        'erro' => 'Não foi possível concluir a operação.',
    ], 500);
}

// Lê um parâmetro da URL como texto. Ignora valores que não são texto (ex.: ?empresa[]=x).
function parametroGet(string $nome): string
{
    $valor = $_GET[$nome] ?? '';

    return is_string($valor) ? trim($valor) : '';
}

/**
 * Status possíveis:
 * - entregue: possui data de entrega;
 * - atrasada: não entregue e vencimento anterior a hoje;
 * - pendente: não entregue e vencimento hoje ou no futuro.
 */
function calcularStatus(array $entrega): string
{
    if (!empty($entrega['data_entrega'])) {
        return 'entregue';
    }

    // Datas no formato AAAA-MM-DD podem ser comparadas como texto.
    if ($entrega['vencimento'] < date('Y-m-d')) {
        return 'atrasada';
    }

    return 'pendente';
}
