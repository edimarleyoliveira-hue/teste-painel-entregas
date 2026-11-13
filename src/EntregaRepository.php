<?php

class EntregaRepository
{
    public const DEPARTAMENTOS = ['Fiscal', 'Pessoal', 'Contábil'];

    // Mesma regra de status usada no resumo(), escrita em SQL.
    private const CONDICOES_STATUS = [
        'entregue' => 'e.data_entrega IS NOT NULL',
        'pendente' => 'e.data_entrega IS NULL AND e.vencimento >= CURDATE()',
        'atrasada' => 'e.data_entrega IS NULL AND e.vencimento < CURDATE()',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public static function statusValidos(): array
    {
        return array_keys(self::CONDICOES_STATUS);
    }

    /**
     * Filtros aceitos (todos opcionais e combináveis):
     * - empresa: parte da razão social;
     * - departamento: um de DEPARTAMENTOS;
     * - status: um de statusValidos().
     */
    public function listar(array $filtros = []): array
    {
        $condicoes = [];
        $parametros = [];

        if (!empty($filtros['empresa'])) {
            $condicoes[] = 'emp.razao_social LIKE ?';
            $parametros[] = '%' . $this->escaparLike($filtros['empresa']) . '%';
        }

        if (!empty($filtros['departamento'])) {
            $condicoes[] = 'o.departamento = ?';
            $parametros[] = $filtros['departamento'];
        }

        if (!empty($filtros['status']) && isset(self::CONDICOES_STATUS[$filtros['status']])) {
            $condicoes[] = '(' . self::CONDICOES_STATUS[$filtros['status']] . ')';
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

        $sql = "SELECT e.id,
                       e.competencia,
                       e.vencimento,
                       e.data_entrega,
                       emp.id AS empresa_id,
                       emp.razao_social,
                       emp.cnpj,
                       o.nome AS obrigacao,
                       o.departamento
                  FROM entregas e
                  JOIN empresas emp ON emp.id = e.empresa_id
                  JOIN obrigacoes o ON o.id = e.obrigacao_id
                 {$where}
                 ORDER BY e.vencimento ASC, emp.razao_social ASC";

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($parametros);
        $entregas = $consulta->fetchAll();

        foreach ($entregas as &$entrega) {
            $entrega['id'] = (int) $entrega['id'];
            $entrega['empresa_id'] = (int) $entrega['empresa_id'];
            $entrega['status'] = calcularStatus($entrega);
        }

        return $entregas;
    }

    public function resumo(): array
    {
        $sql = "SELECT COUNT(*) AS total,
                       COALESCE(SUM(data_entrega IS NOT NULL), 0) AS entregues,
                       COALESCE(SUM(data_entrega IS NULL AND vencimento >= CURDATE()), 0) AS pendentes,
                       COALESCE(SUM(data_entrega IS NULL AND vencimento < CURDATE()), 0) AS atrasadas
                  FROM entregas";

        return array_map('intval', $this->pdo->query($sql)->fetch());
    }

    // No LIKE, % e _ são curingas. Escapamos para buscar o texto literal digitado.
    private function escaparLike(string $texto): string
    {
        return addcslashes($texto, '\\%_');
    }
}
