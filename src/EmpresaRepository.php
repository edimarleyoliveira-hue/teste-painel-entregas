<?php

class EmpresaRepository
{
    // Colunas gravadas a partir do formulário (na mesma ordem dos valores).
    private const COLUNAS = ['codigo', 'tipo_documento', 'documento', 'razao_social', 'nome_fantasia', 'regime',
        'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf',
        'contato_nome', 'contato_telefone', 'contato_email'];

    // Campos da regra de recorrência guardados em empresa_obrigacoes.
    private const CAMPOS_RECORRENCIA = ['recorrencia', 'somente_dias_uteis', 'dia_semana', 'dia_mes',
        'data_inicio', 'ajuste_vencimento'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Filtros opcionais e combináveis:
     * nome (razão social ou fantasia), documento (só os números), codigo, cidade, uf,
     * de / ate (data de cadastro, AAAA-MM-DD).
     */
    public function listar(array $filtros = []): array
    {
        $condicoes = [];
        $parametros = [];

        if (!empty($filtros['nome'])) {
            $condicoes[] = '(razao_social LIKE ? OR nome_fantasia LIKE ?)';
            $termo = '%' . escaparLike($filtros['nome']) . '%';
            array_push($parametros, $termo, $termo);
        }

        if (!empty($filtros['documento'])) {
            // Compara sem pontuação, então "12.345" e "12345" encontram o mesmo CNPJ.
            $condicoes[] = "REPLACE(REPLACE(REPLACE(documento, '.', ''), '/', ''), '-', '') LIKE ?";
            $parametros[] = '%' . $filtros['documento'] . '%';
        }

        if (!empty($filtros['codigo'])) {
            $condicoes[] = 'codigo LIKE ?';
            $parametros[] = '%' . escaparLike($filtros['codigo']) . '%';
        }

        if (!empty($filtros['cidade'])) {
            $condicoes[] = 'cidade LIKE ?';
            $parametros[] = '%' . escaparLike($filtros['cidade']) . '%';
        }

        if (!empty($filtros['uf'])) {
            $condicoes[] = 'uf = ?';
            $parametros[] = $filtros['uf'];
        }

        if (!empty($filtros['de'])) {
            $condicoes[] = 'criado_em >= ?';
            $parametros[] = $filtros['de'] . ' 00:00:00';
        }

        if (!empty($filtros['ate'])) {
            $condicoes[] = 'criado_em <= ?';
            $parametros[] = $filtros['ate'] . ' 23:59:59';
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

        $sql = "SELECT emp.id, emp.codigo, emp.tipo_documento, emp.documento, emp.razao_social, emp.nome_fantasia,
                       emp.cidade, emp.uf, DATE(emp.criado_em) AS criado_em,
                       (SELECT COUNT(*) FROM empresa_obrigacoes eo WHERE eo.empresa_id = emp.id) AS total_obrigacoes
                  FROM empresas emp
                 {$where}
                 ORDER BY emp.razao_social ASC";

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($parametros);
        $empresas = $consulta->fetchAll();

        foreach ($empresas as &$empresa) {
            $empresa['id'] = (int) $empresa['id'];
            $empresa['total_obrigacoes'] = (int) $empresa['total_obrigacoes'];
        }

        return $empresas;
    }

    // Empresa completa com as obrigações e a regra de cada uma. Null se não existir.
    public function buscar(int $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM empresas WHERE id = ?');
        $consulta->execute([$id]);
        $empresa = $consulta->fetch();

        if (!$empresa) {
            return null;
        }

        $empresa['id'] = (int) $empresa['id'];
        $empresa['obrigacoes'] = array_values($this->obrigacoesDaEmpresa($id));

        return $empresa;
    }

    public function existe(int $id): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM empresas WHERE id = ?');
        $consulta->execute([$id]);

        return (bool) $consulta->fetchColumn();
    }

    // Verifica se o valor já é usado por outra empresa (na edição, ignora a própria).
    public function emUso(string $coluna, string $valor, ?int $ignorarId = null): bool
    {
        if (!in_array($coluna, ['documento', 'codigo'], true)) {
            throw new InvalidArgumentException('Coluna não permitida.');
        }

        $consulta = $this->pdo->prepare("SELECT 1 FROM empresas WHERE {$coluna} = ? AND id <> ?");
        $consulta->execute([$valor, $ignorarId ?? 0]);

        return (bool) $consulta->fetchColumn();
    }

    public function criar(array $empresa): int
    {
        $colunas = implode(', ', self::COLUNAS);
        $marcadores = implode(', ', array_fill(0, count(self::COLUNAS), '?'));

        $this->pdo->prepare("INSERT INTO empresas ({$colunas}) VALUES ({$marcadores})")
            ->execute($this->valores($empresa));

        $id = (int) $this->pdo->lastInsertId();

        if ($empresa['codigo'] === null) {
            $this->gerarCodigo($id);
        }

        return $id;
    }

    // Na edição, ID da empresa em branco mantém o ID atual (COALESCE).
    public function atualizar(int $id, array $empresa): void
    {
        $atribuicoes = implode(', ', array_map(
            fn($c) => $c === 'codigo' ? 'codigo = COALESCE(?, codigo)' : "{$c} = ?",
            self::COLUNAS
        ));

        $this->pdo->prepare("UPDATE empresas SET {$atribuicoes} WHERE id = ?")
            ->execute([...$this->valores($empresa), $id]);
    }

    // Trava a linha da empresa até o fim da transação: duas edições ao mesmo tempo esperam uma pela outra.
    public function travar(int $id): void
    {
        $this->pdo->prepare('SELECT id FROM empresas WHERE id = ? FOR UPDATE')->execute([$id]);
    }

    public function codigoDe(int $id): string
    {
        $consulta = $this->pdo->prepare('SELECT codigo FROM empresas WHERE id = ?');
        $consulta->execute([$id]);

        return (string) $consulta->fetchColumn();
    }

    /**
     * Deixa as obrigações da empresa iguais à lista recebida:
     * - nova ou com regra alterada: grava a regra, apaga as entregas futuras pendentes e gera de novo;
     * - sem mudança: só completa o que faltar na janela de 12 meses (a janela anda com o tempo);
     * - retirada: apaga o vínculo e as entregas futuras ainda não entregues.
     * Devolve quantas entregas foram criadas.
     */
    public function sincronizarObrigacoes(int $empresaId, array $vinculos, GeradorEntregas $gerador): int
    {
        $atuais = $this->obrigacoesDaEmpresa($empresaId);
        $criadas = 0;
        $recebidas = [];

        foreach ($vinculos as $vinculo) {
            $obrigacaoId = $vinculo['obrigacao_id'];
            $recebidas[$obrigacaoId] = true;
            $config = array_intersect_key($vinculo, array_flip(self::CAMPOS_RECORRENCIA));

            if (isset($atuais[$obrigacaoId]) && $this->mesmaRegra($atuais[$obrigacaoId], $config)) {
                $criadas += $gerador->completar($empresaId, $obrigacaoId, $config);
                continue;
            }

            $this->gravarVinculo($empresaId, $obrigacaoId, $config);
            $criadas += $gerador->regerar($empresaId, $obrigacaoId, $config);
        }

        foreach (array_keys($atuais) as $obrigacaoId) {
            if (!isset($recebidas[$obrigacaoId])) {
                $gerador->apagarFuturasPendentes($empresaId, $obrigacaoId);
                $this->pdo->prepare('DELETE FROM empresa_obrigacoes WHERE empresa_id = ? AND obrigacao_id = ?')
                    ->execute([$empresaId, $obrigacaoId]);
            }
        }

        return $criadas;
    }

    // obrigacao_id => vínculo (com nome e departamento da obrigação).
    private function obrigacoesDaEmpresa(int $empresaId): array
    {
        $sql = "SELECT eo.obrigacao_id, o.nome, o.departamento,
                       eo.recorrencia, eo.somente_dias_uteis, eo.dia_semana, eo.dia_mes,
                       eo.data_inicio, eo.ajuste_vencimento
                  FROM empresa_obrigacoes eo
                  JOIN obrigacoes o ON o.id = eo.obrigacao_id
                 WHERE eo.empresa_id = ?
                 ORDER BY o.departamento, o.nome";

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([$empresaId]);

        $vinculos = [];
        foreach ($consulta->fetchAll() as $linha) {
            $linha['obrigacao_id'] = (int) $linha['obrigacao_id'];
            $linha['somente_dias_uteis'] = (int) $linha['somente_dias_uteis'];
            $linha['dia_semana'] = $linha['dia_semana'] === null ? null : (int) $linha['dia_semana'];
            $linha['dia_mes'] = $linha['dia_mes'] === null ? null : (int) $linha['dia_mes'];
            $vinculos[$linha['obrigacao_id']] = $linha;
        }

        return $vinculos;
    }

    private function mesmaRegra(array $atual, array $nova): bool
    {
        foreach (self::CAMPOS_RECORRENCIA as $campo) {
            if ((string) ($atual[$campo] ?? '') !== (string) ($nova[$campo] ?? '')) {
                return false;
            }
        }
        return true;
    }

    private function gravarVinculo(int $empresaId, int $obrigacaoId, array $config): void
    {
        $sql = "INSERT INTO empresa_obrigacoes
                       (empresa_id, obrigacao_id, recorrencia, somente_dias_uteis, dia_semana, dia_mes, data_inicio, ajuste_vencimento)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                       recorrencia = VALUES(recorrencia), somente_dias_uteis = VALUES(somente_dias_uteis),
                       dia_semana = VALUES(dia_semana), dia_mes = VALUES(dia_mes),
                       data_inicio = VALUES(data_inicio), ajuste_vencimento = VALUES(ajuste_vencimento)";

        $this->pdo->prepare($sql)->execute([
            $empresaId, $obrigacaoId, $config['recorrencia'], $config['somente_dias_uteis'],
            $config['dia_semana'], $config['dia_mes'], $config['data_inicio'], $config['ajuste_vencimento'],
        ]);
    }

    // ID automático: EMP + número da empresa com 4 dígitos (EMP0010). Se já existir, acrescenta letra.
    private function gerarCodigo(int $id): void
    {
        $base = 'EMP' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        $codigo = $base;

        for ($letra = 'A'; $this->emUso('codigo', $codigo, $id); $letra++) {
            $codigo = $base . $letra;
        }

        $this->pdo->prepare('UPDATE empresas SET codigo = ? WHERE id = ?')->execute([$codigo, $id]);
    }

    private function valores(array $empresa): array
    {
        return array_map(fn($coluna) => $empresa[$coluna], self::COLUNAS);
    }
}
