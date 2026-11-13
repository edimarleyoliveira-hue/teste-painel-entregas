<?php

require_once __DIR__ . '/../src/helpers.php';

const MENSAGEM_DUPLICADA = 'Já existe uma entrega desta obrigação para esta empresa nesta competência.';

// GET: lista as entregas, com filtros opcionais na URL.
function listarEntregas(): void
{
    $filtros = [
        'empresa' => parametroGet('empresa'),
        'departamento' => parametroGet('departamento'),
        'status' => parametroGet('status'),
        'competencia' => parametroGet('competencia'),
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

    if ($filtros['competencia'] !== '' && !competenciaValida($filtros['competencia'])) {
        responderJson(['erro' => 'Competência inválida no filtro: use AAAA-MM (ex.: 2026-11).'], 400);
    }

    $repositorio = new EntregaRepository(Database::conexao());
    responderJson($repositorio->listar($filtros));
}

// POST: cadastra uma entrega nova (sempre sem data de entrega).
function cadastrarEntrega(): void
{
    $dados = lerCorpoJson();

    if ($dados === null) {
        responderJson(['erro' => 'Envie os dados da entrega em formato JSON.'], 400);
    }

    $empresaId = inteiroPositivo($dados['empresa_id'] ?? null);
    $obrigacaoId = inteiroPositivo($dados['obrigacao_id'] ?? null);
    $competencia = textoDe($dados, 'competencia');
    $vencimento = textoDe($dados, 'vencimento');

    $erros = [];

    if ($empresaId === null) {
        $erros[] = 'Selecione a empresa.';
    }

    if ($obrigacaoId === null) {
        $erros[] = 'Selecione a obrigação.';
    }

    if ($competencia === '') {
        $erros[] = 'Informe a competência (mês e ano).';
    } elseif (!competenciaValida($competencia)) {
        $erros[] = 'Competência inválida: use mês e ano no formato AAAA-MM (ex.: 2026-11), com ano entre 1900 e 2099.';
    }

    if ($vencimento === '') {
        $erros[] = 'Informe o vencimento.';
    } elseif (!dataValida($vencimento)) {
        $erros[] = 'Vencimento inválido: informe uma data que exista no calendário, com ano entre 1900 e 2099.';
    }

    if ($erros) {
        responderJson(['erro' => implode(' ', $erros)], 400);
    }

    $pdo = Database::conexao();

    if (!(new EmpresaRepository($pdo))->existe($empresaId)) {
        responderJson(['erro' => 'A empresa selecionada não está cadastrada.'], 400);
    }

    if (!(new ObrigacaoRepository($pdo))->existe($obrigacaoId)) {
        responderJson(['erro' => 'A obrigação selecionada não está cadastrada.'], 400);
    }

    $repositorio = new EntregaRepository($pdo);

    if ($repositorio->existe($empresaId, $obrigacaoId, $competencia)) {
        responderJson(['erro' => MENSAGEM_DUPLICADA], 409);
    }

    try {
        $id = $repositorio->criar($empresaId, $obrigacaoId, $competencia, $vencimento);
    } catch (PDOException $e) {
        // Dois cadastros iguais ao mesmo tempo: o índice único do banco barra o segundo.
        if (($e->errorInfo[1] ?? null) === 1062) {
            responderJson(['erro' => MENSAGEM_DUPLICADA], 409);
        }
        throw $e;
    }

    responderJson(['id' => $id, 'mensagem' => 'Entrega cadastrada com sucesso.'], 201);
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            listarEntregas();
            break;
        case 'POST':
            cadastrarEntrega();
            break;
        default:
            responderJson(['erro' => 'Método não permitido.'], 405);
    }
} catch (Throwable $e) {
    responderErro($e);
}
