<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/EntregaRepository.php';
require_once __DIR__ . '/EmpresaRepository.php';
require_once __DIR__ . '/ObrigacaoRepository.php';
require_once __DIR__ . '/Documento.php';
require_once __DIR__ . '/EmpresaValidador.php';
require_once __DIR__ . '/Calendario.php';
require_once __DIR__ . '/Recorrencia.php';
require_once __DIR__ . '/GeradorEntregas.php';
require_once __DIR__ . '/HistoricoRepository.php';

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

// Quem está fazendo a ação, para o histórico. Por enquanto, um usuário fictício (config.php).
function usuarioAtual(): string
{
    $config = require __DIR__ . '/config.php';

    return $config['usuario_demonstracao'];
}

// No LIKE, % e _ são curingas. Escapamos para buscar o texto literal digitado.
function escaparLike(string $texto): string
{
    return addcslashes($texto, '\\%_');
}

// Lê um parâmetro da URL como texto. Ignora valores que não são texto (ex.: ?empresa[]=x).
function parametroGet(string $nome): string
{
    $valor = $_GET[$nome] ?? '';

    return is_string($valor) ? trim($valor) : '';
}

// Lê o corpo da requisição em JSON. Devolve null se não for um objeto JSON válido.
// Exige o cabeçalho Content-Type: application/json. Um formulário de outro site não consegue
// enviar esse cabeçalho sem a permissão do navegador, o que dificulta ataques CSRF.
function lerCorpoJson(): ?array
{
    $tipo = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($tipo, 'application/json') !== 0) {
        responderJson(['erro' => 'Envie os dados em formato JSON (Content-Type: application/json).'], 415);
    }

    $dados = json_decode(file_get_contents('php://input'), true);

    return is_array($dados) ? $dados : null;
}

// Pega um campo de texto do corpo. Números viram texto; outros tipos (lista, true...) viram ''.
function textoDe(array $dados, string $campo): string
{
    $valor = $dados[$campo] ?? '';

    if (is_int($valor) || is_float($valor)) {
        return (string) $valor;
    }

    return is_string($valor) ? trim($valor) : '';
}

// Data AAAA-MM-DD à meia-noite exata (o "!" zera a hora; evita surpresas com horário de verão).
function dataDe(string $valor): DateTimeImmutable
{
    return DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
}

// Aceita 5 ou "5". Recusa 0, negativos, decimais, true, listas etc.
function inteiroPositivo(mixed $valor): ?int
{
    if (is_int($valor)) {
        return $valor > 0 ? $valor : null;
    }

    // O modificador D impede que uma quebra de linha no final ("5\n") seja aceita.
    if (is_string($valor) && preg_match('/^[1-9][0-9]{0,9}$/D', $valor)) {
        return (int) $valor;
    }

    return null;
}

// Competência no formato AAAA-MM, com mês de 01 a 12 e ano entre 1900 e 2099.
function competenciaValida(string $valor): bool
{
    return preg_match('/^(19|20)[0-9]{2}-(0[1-9]|1[0-2])$/D', $valor) === 1;
}

// Data no formato AAAA-MM-DD que existe no calendário (recusa 2026-02-30, por exemplo),
// com ano entre 1900 e 2099.
function dataValida(string $valor): bool
{
    if (preg_match('/^(19|20)[0-9]{2}-[0-9]{2}-[0-9]{2}$/D', $valor) !== 1) {
        return false;
    }

    $data = DateTime::createFromFormat('!Y-m-d', $valor);

    return $data !== false && $data->format('Y-m-d') === $valor;
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
