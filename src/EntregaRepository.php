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
     * - empresa: parte da razão social, do nome fantasia ou do nome da obrigação;
     * - departamento: um de DEPARTAMENTOS;
     * - status: um de statusValidos();
     * - competencia: AAAA-MM exato.
     */
    public function listar(array $filtros = []): array
    {
        $condicoes = [];
        $parametros = [];

        // Busca livre: razão social, nome fantasia ou nome da obrigação.
        if (($filtros['empresa'] ?? '') !== '') {
            $condicoes[] = '(emp.razao_social LIKE ? OR emp.nome_fantasia LIKE ? OR o.nome LIKE ?)';
            $termo = '%' . escaparLike($filtros['empresa']) . '%';
            array_push($parametros, $termo, $termo, $termo);
        }

        if (($filtros['departamento'] ?? '') !== '') {
            $condicoes[] = 'o.departamento = ?';
            $parametros[] = $filtros['departamento'];
        }

        if (($filtros['status'] ?? '') !== '' && isset(self::CONDICOES_STATUS[$filtros['status']])) {
            $condicoes[] = '(' . self::CONDICOES_STATUS[$filtros['status']] . ')';
        }

        if (($filtros['competencia'] ?? '') !== '') {
            $condicoes[] = 'e.competencia = ?';
            $parametros[] = $filtros['competencia'];
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

        $sql = "SELECT e.id,
                       e.competencia,
                       e.vencimento,
                       e.data_entrega,
                       emp.id AS empresa_id,
                       emp.razao_social,
                       emp.nome_fantasia,
                       emp.codigo AS empresa_codigo,
                       emp.documento,
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

    public function existe(int $empresaId, int $obrigacaoId, string $competencia): bool
    {
        $sql = "SELECT 1
                  FROM entregas
                 WHERE empresa_id = ? AND obrigacao_id = ? AND competencia = ?
                 LIMIT 1";

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([$empresaId, $obrigacaoId, $competencia]);

        return (bool) $consulta->fetchColumn();
    }

    // Toda entrega nova nasce sem data de entrega (NULL).
    public function criar(int $empresaId, int $obrigacaoId, string $competencia, string $vencimento): int
    {
        $sql = "INSERT INTO entregas (empresa_id, obrigacao_id, competencia, vencimento, data_entrega)
                VALUES (?, ?, ?, ?, NULL)";

        $this->pdo->prepare($sql)->execute([$empresaId, $obrigacaoId, $competencia, $vencimento]);

        return (int) $this->pdo->lastInsertId();
    }

    // Data de hoje segundo o banco: o mesmo relógio usado no resumo e nos status.
    public function hoje(): string
    {
        return $this->pdo->query('SELECT CURDATE()')->fetchColumn();
    }

    /**
     * Marca as entregas informadas como entregues, com a data escolhida
     * ou, se não vier data ($data = null), com a data de hoje, e registra no histórico.
     * Só entregas ainda não entregues são atingidas: uma entrega já entregue nunca
     * tem a data alterada, nem se alguém chamar a API diretamente.
     * Devolve quantas entregas foram de fato marcadas.
     */
    public function marcarComoEntregues(array $ids, ?string $data, string $usuario): int
    {
        if ($ids === []) {
            return 0;
        }

        $marcadores = implode(', ', array_fill(0, count($ids), '?'));
        $dataEntrega = $data ?? $this->hoje();

        // Atualização + histórico: ou grava os dois, ou nenhum.
        $this->pdo->beginTransaction();
        try {
            // FOR UPDATE trava as linhas: dois cliques ao mesmo tempo não registram a mesma entrega duas vezes.
            $consulta = $this->pdo->prepare(
                "SELECT id FROM entregas WHERE id IN ({$marcadores}) AND data_entrega IS NULL FOR UPDATE"
            );
            $consulta->execute(array_values($ids));
            $aMarcar = array_map('intval', $consulta->fetchAll(PDO::FETCH_COLUMN));

            if ($aMarcar !== []) {
                $marcadoresMarcar = implode(', ', array_fill(0, count($aMarcar), '?'));
                $this->pdo->prepare(
                    "UPDATE entregas SET data_entrega = ? WHERE id IN ({$marcadoresMarcar}) AND data_entrega IS NULL"
                )->execute([$dataEntrega, ...$aMarcar]);

                $this->registrarHistorico($aMarcar, 'entregue', $dataEntrega, $usuario);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return count($aMarcar);
    }

    /**
     * Desfaz uma entrega marcada por engano: a data de entrega volta a ficar vazia
     * (volta para pendente/atrasada) e a ação fica registrada no histórico.
     * Só atinge entregas que estão entregues. Devolve true se desfez.
     */
    public function desfazerEntrega(int $id, string $usuario): bool
    {
        $this->pdo->beginTransaction();
        try {
            $consulta = $this->pdo->prepare(
                'SELECT id FROM entregas WHERE id = ? AND data_entrega IS NOT NULL FOR UPDATE'
            );
            $consulta->execute([$id]);

            if (!$consulta->fetchColumn()) {
                $this->pdo->commit();
                return false;
            }

            $this->pdo->prepare('UPDATE entregas SET data_entrega = NULL WHERE id = ?')->execute([$id]);
            $this->registrarHistorico([$id], 'pendente', null, $usuario);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return true;
    }

    // Copia empresa, obrigação, competência e vencimento de cada entrega para o histórico.
    private function registrarHistorico(array $ids, string $acao, ?string $dataEntrega, string $usuario): void
    {
        $marcadores = implode(', ', array_fill(0, count($ids), '?'));

        $sql = "INSERT INTO historico_entregas
                       (entrega_id, empresa_id, obrigacao_id, competencia, vencimento, acao, data_entrega, usuario)
                SELECT id, empresa_id, obrigacao_id, competencia, vencimento, ?, ?, ?
                  FROM entregas
                 WHERE id IN ({$marcadores})";

        $this->pdo->prepare($sql)->execute([$acao, $dataEntrega, $usuario, ...$ids]);
    }
}
