<?php

// Valida e normaliza os dados do formulário de empresa.
class EmpresaValidador
{
    public const REGIMES = ['Simples Nacional', 'Lucro Presumido', 'Lucro Real'];
    public const UFS = ['AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE',
        'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO'];

    // Campo => [rótulo, tamanho máximo, obrigatório]
    private const TEXTOS = [
        'razao_social' => ['Razão social', 150, true],
        'nome_fantasia' => ['Nome fantasia', 150, false],
        'logradouro' => ['Rua', 150, true],
        'numero' => ['Número', 20, true],
        'complemento' => ['Complemento', 80, false],
        'bairro' => ['Bairro', 80, true],
        'cidade' => ['Cidade', 80, true],
        'contato_nome' => ['Nome do contato', 100, false],
    ];

    /**
     * Devolve [empresa normalizada, vínculos de obrigações, erros].
     * Vínculos: null (não alterar) ou lista de ['obrigacao_id' => int] + configuração de recorrência
     * (ou 'usar_padrao' => true quando a regra não foi enviada).
     */
    public static function validar(array $dados): array
    {
        $erros = [];
        $empresa = [];

        // Documento: CNPJ ou CPF, com dígitos verificadores conferidos; grava no formato oficial.
        $tipo = textoDe($dados, 'tipo_documento');
        $documento = textoDe($dados, 'documento');
        if (!in_array($tipo, Documento::TIPOS, true)) {
            $erros[] = 'Escolha o tipo de documento (CNPJ ou CPF).';
        } elseif ($documento === '') {
            $erros[] = "Informe o {$tipo}.";
        } elseif (!Documento::valido($tipo, $documento)) {
            $erros[] = "{$tipo} inválido: confira os números digitados.";
        } else {
            $documento = Documento::formatar($tipo, $documento);
        }
        $empresa['tipo_documento'] = $tipo;
        $empresa['documento'] = $documento;

        foreach (self::TEXTOS as $campo => [$rotulo, $maximo, $obrigatorio]) {
            $valor = textoDe($dados, $campo);
            if ($obrigatorio && $valor === '') {
                $erros[] = "Informe: {$rotulo}.";
            } elseif (mb_strlen($valor) > $maximo) {
                $erros[] = "{$rotulo}: no máximo {$maximo} caracteres.";
            }
            $empresa[$campo] = $valor === '' ? null : $valor;
        }

        $regime = textoDe($dados, 'regime');
        if (!in_array($regime, self::REGIMES, true)) {
            $erros[] = 'Escolha o regime tributário.';
        }
        $empresa['regime'] = $regime;

        $cep = Documento::digitos(textoDe($dados, 'cep'));
        if (strlen($cep) !== 8) {
            $erros[] = 'CEP inválido: use 00000-000.';
        }
        $empresa['cep'] = substr($cep, 0, 5) . '-' . substr($cep, 5);

        $uf = strtoupper(textoDe($dados, 'uf'));
        if (!in_array($uf, self::UFS, true)) {
            $erros[] = 'Escolha o estado (UF).';
        }
        $empresa['uf'] = $uf;

        // Telefone opcional: 10 dígitos (fixo) ou 11 (celular), com DDD.
        $telefone = Documento::digitos(textoDe($dados, 'contato_telefone'));
        if ($telefone !== '' && !in_array(strlen($telefone), [10, 11], true)) {
            $erros[] = 'Telefone inválido: informe DDD + número, ex.: (31) 99999-9999.';
        }
        $empresa['contato_telefone'] = $telefone === '' ? null : self::formatarTelefone($telefone);

        $email = textoDe($dados, 'contato_email');
        if ($email !== '' && (mb_strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $erros[] = 'E-mail do contato inválido.';
        }
        $empresa['contato_email'] = $email === '' ? null : $email;

        // ID da empresa: opcional (gerado automaticamente), letras e números, sempre maiúsculo.
        $codigo = strtoupper(textoDe($dados, 'codigo'));
        if ($codigo !== '' && preg_match('/^[A-Z0-9]{1,20}$/D', $codigo) !== 1) {
            $erros[] = 'ID da empresa: use só letras e números (até 20), sem espaços nem acentos.';
        }
        $empresa['codigo'] = $codigo === '' ? null : $codigo;

        // Sem o campo "obrigacoes", as obrigações da empresa não são alteradas (vínculos = null).
        // Lista vazia enviada de propósito retira todas.
        if (!array_key_exists('obrigacoes', $dados)) {
            return [$empresa, null, $erros];
        }

        [$vinculos, $errosVinculos] = self::validarObrigacoes($dados['obrigacoes']);

        return [$empresa, $vinculos, array_merge($erros, $errosVinculos)];
    }

    private static function validarObrigacoes(mixed $lista): array
    {
        if (!is_array($lista) || !array_is_list($lista)) {
            return [[], ['Lista de obrigações inválida.']];
        }

        $erros = [];
        $vinculos = [];
        $vistos = [];

        foreach ($lista as $posicao => $item) {
            $numero = $posicao + 1;
            if (!is_array($item)) {
                $erros[] = "Obrigação {$numero}: dados inválidos.";
                continue;
            }

            $obrigacaoId = inteiroPositivo($item['obrigacao_id'] ?? null);
            if ($obrigacaoId === null) {
                $erros[] = "Obrigação {$numero}: escolha a obrigação.";
                continue;
            }
            if (isset($vistos[$obrigacaoId])) {
                $erros[] = "Obrigação {$numero}: esta obrigação já está na lista.";
                continue;
            }
            $vistos[$obrigacaoId] = true;

            // Sem "recorrencia", vale o padrão da obrigação (preenchido depois, na API).
            if (!array_key_exists('recorrencia', $item)) {
                $vinculos[] = ['obrigacao_id' => $obrigacaoId, 'usar_padrao' => true];
                continue;
            }

            [$config, $errosConfig] = Recorrencia::validar($item, "Obrigação {$numero}: ");
            $erros = array_merge($erros, $errosConfig);
            $vinculos[] = ['obrigacao_id' => $obrigacaoId] + $config;
        }

        return [$vinculos, $erros];
    }

    private static function formatarTelefone(string $n): string
    {
        $meio = strlen($n) === 11 ? 5 : 4;
        return '(' . substr($n, 0, 2) . ') ' . substr($n, 2, $meio) . '-' . substr($n, 2 + $meio);
    }
}
