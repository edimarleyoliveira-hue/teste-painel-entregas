<?php

class ObrigacaoRepository
{
    // Colunas gravadas a partir do formulário (na mesma ordem dos valores).
    private const COLUNAS = ['nome', 'departamento', 'recorrencia', 'somente_dias_uteis', 'dia_semana',
        'dia_mes', 'data_inicio', 'ajuste_vencimento'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Obrigações com a configuração padrão e em quantas empresas são usadas.
     * Filtros opcionais e combináveis: nome (parte do nome) e recorrencia (um de Recorrencia::TIPOS).
     */
    public function listar(array $filtros = []): array
    {
        $condicoes = [];
        $parametros = [];

        if (($filtros['nome'] ?? '') !== '') {
            $condicoes[] = 'o.nome LIKE ?';
            $parametros[] = '%' . escaparLike($filtros['nome']) . '%';
        }

        if (($filtros['recorrencia'] ?? '') !== '') {
            $condicoes[] = 'o.recorrencia = ?';
            $parametros[] = $filtros['recorrencia'];
        }

        $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';

        $sql = "SELECT o.*,
                       (SELECT COUNT(*) FROM empresa_obrigacoes eo WHERE eo.obrigacao_id = o.id) AS total_empresas
                  FROM obrigacoes o
                 {$where}
                 ORDER BY o.departamento ASC, o.nome ASC";

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute($parametros);

        return array_map([$this, 'normalizar'], $consulta->fetchAll());
    }

    public function buscar(int $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM obrigacoes WHERE id = ?');
        $consulta->execute([$id]);
        $obrigacao = $consulta->fetch();

        return $obrigacao ? $this->normalizar($obrigacao) : null;
    }

    public function existe(int $id): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM obrigacoes WHERE id = ?');
        $consulta->execute([$id]);

        return (bool) $consulta->fetchColumn();
    }

    // Nome já usado por outra obrigação (na edição, ignora a própria).
    public function nomeEmUso(string $nome, ?int $ignorarId = null): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM obrigacoes WHERE nome = ? AND id <> ?');
        $consulta->execute([$nome, $ignorarId ?? 0]);

        return (bool) $consulta->fetchColumn();
    }

    public function criar(array $obrigacao): int
    {
        $colunas = implode(', ', self::COLUNAS);
        $marcadores = implode(', ', array_fill(0, count(self::COLUNAS), '?'));

        $this->pdo->prepare("INSERT INTO obrigacoes ({$colunas}) VALUES ({$marcadores})")
            ->execute($this->valores($obrigacao));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Altera o padrão da obrigação. Empresas que já têm a obrigação não mudam:
     * a regra de cada empresa é independente (o padrão só vale para novas inclusões).
     */
    public function atualizar(int $id, array $obrigacao): void
    {
        $atribuicoes = implode(', ', array_map(fn($c) => "{$c} = ?", self::COLUNAS));

        $this->pdo->prepare("UPDATE obrigacoes SET {$atribuicoes} WHERE id = ?")
            ->execute([...$this->valores($obrigacao), $id]);
    }

    private function valores(array $obrigacao): array
    {
        return array_map(fn($coluna) => $obrigacao[$coluna], self::COLUNAS);
    }

    private function normalizar(array $linha): array
    {
        foreach (['id', 'somente_dias_uteis', 'total_empresas'] as $campo) {
            if (isset($linha[$campo])) {
                $linha[$campo] = (int) $linha[$campo];
            }
        }
        foreach (['dia_semana', 'dia_mes'] as $campo) {
            $linha[$campo] = $linha[$campo] === null ? null : (int) $linha[$campo];
        }

        return $linha;
    }
}
