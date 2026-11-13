<?php

require_once __DIR__ . '/../src/helpers.php';

// GET sem id: lista com filtros. GET ?id=N: empresa completa com as obrigações.
function listarOuBuscarEmpresas(): void
{
    $repositorio = new EmpresaRepository(Database::conexao());

    if (parametroGet('id') !== '') {
        $id = inteiroPositivo(parametroGet('id'));
        $empresa = $id === null ? null : $repositorio->buscar($id);

        if ($empresa === null) {
            responderJson(['erro' => 'Empresa não encontrada.'], 404);
        }
        responderJson($empresa);
    }

    $filtros = [
        'nome' => parametroGet('nome'),
        'documento' => Documento::caracteres(parametroGet('documento')),
        'codigo' => strtoupper(parametroGet('codigo')),
        'cidade' => parametroGet('cidade'),
        'uf' => strtoupper(parametroGet('uf')),
        'de' => parametroGet('de'),
        'ate' => parametroGet('ate'),
    ];

    foreach (['nome', 'codigo', 'cidade'] as $campo) {
        if (mb_strlen($filtros[$campo]) > 150) {
            responderJson(['erro' => 'Busca muito longa: use no máximo 150 caracteres.'], 400);
        }
    }

    if ($filtros['uf'] !== '' && !in_array($filtros['uf'], EmpresaValidador::UFS, true)) {
        responderJson(['erro' => 'Estado (UF) inválido.'], 400);
    }

    foreach (['de' => 'inicial', 'ate' => 'final'] as $campo => $rotulo) {
        if ($filtros[$campo] !== '' && !dataValida($filtros[$campo])) {
            responderJson(['erro' => "Data {$rotulo} do período inválida."], 400);
        }
    }

    if ($filtros['de'] !== '' && $filtros['ate'] !== '' && $filtros['de'] > $filtros['ate']) {
        responderJson(['erro' => 'Período inválido: a data "de" é depois da data "até".'], 400);
    }

    responderJson($repositorio->listar($filtros));
}

// POST (criar) e PUT ?id=N (editar).
function salvarEmpresa(?int $id): void
{
    $criando = $id === null;
    $dados = lerCorpoJson();
    if ($dados === null) {
        responderJson(['erro' => 'Envie os dados em formato JSON.'], 400);
    }

    $pdo = Database::conexao();
    $empresas = new EmpresaRepository($pdo);

    if ($id !== null && !$empresas->existe($id)) {
        responderJson(['erro' => 'Empresa não encontrada.'], 404);
    }

    [$empresa, $vinculos, $erros] = EmpresaValidador::validar($dados);
    if ($erros) {
        responderJson(['erro' => implode(' ', $erros)], 400);
    }

    // Confere se as obrigações existem e, quando o vínculo veio sem regra, copia o padrão da obrigação.
    $obrigacoes = new ObrigacaoRepository($pdo);
    foreach ($vinculos ?? [] as $posicao => $vinculo) {
        $obrigacao = $obrigacoes->buscar($vinculo['obrigacao_id']);
        if ($obrigacao === null) {
            responderJson(['erro' => 'Uma das obrigações escolhidas não está cadastrada.'], 400);
        }
        if (!empty($vinculo['usar_padrao'])) {
            $vinculos[$posicao] = ['obrigacao_id' => $obrigacao['id']] + $obrigacao;
        }
    }

    if ($empresas->emUso('documento', $empresa['documento'], $id)) {
        responderJson(['erro' => "Já existe uma empresa com este {$empresa['tipo_documento']}."], 409);
    }

    if ($empresa['codigo'] !== null && $empresas->emUso('codigo', $empresa['codigo'], $id)) {
        responderJson(['erro' => 'Este ID de empresa já está em uso. Escolha outro ou deixe em branco para gerar automaticamente.'], 409);
    }

    // Empresa + obrigações + entregas geradas: ou grava tudo, ou nada.
    $pdo->beginTransaction();
    try {
        if ($criando) {
            $id = $empresas->criar($empresa);
        } else {
            $empresas->travar($id);
            $empresas->atualizar($id, $empresa);
        }
        // Sem a lista de obrigações no corpo, as obrigações da empresa ficam como estão.
        $geradas = $vinculos === null ? 0 : $empresas->sincronizarObrigacoes($id, $vinculos, new GeradorEntregas($pdo));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Duas pessoas salvando o mesmo documento/ID ao mesmo tempo: o índice único barra.
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
            responderJson(['erro' => 'Já existe uma empresa com este documento ou ID.'], 409);
        }
        throw $e;
    }

    $mensagem = $criando ? 'Empresa cadastrada com sucesso.' : 'Empresa atualizada com sucesso.';
    if ($geradas > 0) {
        $mensagem .= " {$geradas} entrega(s) gerada(s) para os próximos 12 meses.";
    }

    responderJson([
        'id' => $id,
        'codigo' => $empresas->codigoDe($id),
        'entregas_geradas' => $geradas,
        'mensagem' => $mensagem,
    ], $criando ? 201 : 200);
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            listarOuBuscarEmpresas();
            break;
        case 'POST':
            salvarEmpresa(null);
            break;
        case 'PUT':
            $id = inteiroPositivo(parametroGet('id'));
            if ($id === null) {
                responderJson(['erro' => 'Informe a empresa a editar (?id=).'], 400);
            }
            salvarEmpresa($id);
            break;
        default:
            responderJson(['erro' => 'Método não permitido.'], 405);
    }
} catch (Throwable $e) {
    responderErro($e);
}
