<?php

require_once __DIR__ . '/../src/helpers.php';

// Valida nome, departamento e a configuração padrão de recorrência.
function validarObrigacao(array $dados): array
{
    $erros = [];

    $nome = textoDe($dados, 'nome');
    if ($nome === '') {
        $erros[] = 'Informe o nome da obrigação.';
    } elseif (mb_strlen($nome) > 80) {
        $erros[] = 'Nome da obrigação: no máximo 80 caracteres.';
    }

    $departamento = textoDe($dados, 'departamento');
    if (!in_array($departamento, EntregaRepository::DEPARTAMENTOS, true)) {
        $erros[] = 'Escolha o departamento (Fiscal, Pessoal ou Contábil).';
    }

    [$config, $errosRecorrencia] = Recorrencia::validar($dados);

    return [['nome' => $nome, 'departamento' => $departamento] + $config, array_merge($erros, $errosRecorrencia)];
}

function salvarObrigacao(?int $id): void
{
    $criando = $id === null;
    $dados = lerCorpoJson();
    if ($dados === null) {
        responderJson(['erro' => 'Envie os dados em formato JSON.'], 400);
    }

    $repositorio = new ObrigacaoRepository(Database::conexao());

    if (!$criando && !$repositorio->existe($id)) {
        responderJson(['erro' => 'Obrigação não encontrada.'], 404);
    }

    [$obrigacao, $erros] = validarObrigacao($dados);
    if ($erros) {
        responderJson(['erro' => implode(' ', $erros)], 400);
    }

    if ($repositorio->nomeEmUso($obrigacao['nome'], $id)) {
        responderJson(['erro' => 'Já existe uma obrigação com este nome.'], 409);
    }

    try {
        if ($criando) {
            $id = $repositorio->criar($obrigacao);
        } else {
            $repositorio->atualizar($id, $obrigacao);
        }
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            responderJson(['erro' => 'Já existe uma obrigação com este nome.'], 409);
        }
        throw $e;
    }

    $mensagem = $criando
        ? 'Obrigação cadastrada com sucesso.'
        : 'Obrigação atualizada. A nova configuração vale como padrão para as próximas empresas; as empresas que já têm esta obrigação mantêm a regra própria.';

    responderJson(['id' => $id, 'mensagem' => $mensagem], $criando ? 201 : 200);
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $repositorio = new ObrigacaoRepository(Database::conexao());
            if (parametroGet('id') !== '') {
                $id = inteiroPositivo(parametroGet('id'));
                $obrigacao = $id === null ? null : $repositorio->buscar($id);
                if ($obrigacao === null) {
                    responderJson(['erro' => 'Obrigação não encontrada.'], 404);
                }
                responderJson($obrigacao);
            }
            responderJson($repositorio->listar());
            break;
        case 'POST':
            salvarObrigacao(null);
            break;
        case 'PUT':
            $id = inteiroPositivo(parametroGet('id'));
            if ($id === null) {
                responderJson(['erro' => 'Informe a obrigação a editar (?id=).'], 400);
            }
            salvarObrigacao($id);
            break;
        default:
            responderJson(['erro' => 'Método não permitido.'], 405);
    }
} catch (Throwable $e) {
    responderErro($e);
}
