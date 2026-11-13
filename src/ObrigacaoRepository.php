<?php

class ObrigacaoRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function listar(): array
    {
        $sql = "SELECT id, nome, departamento
                  FROM obrigacoes
                 ORDER BY departamento ASC, nome ASC";

        $obrigacoes = $this->pdo->query($sql)->fetchAll();

        foreach ($obrigacoes as &$obrigacao) {
            $obrigacao['id'] = (int) $obrigacao['id'];
        }

        return $obrigacoes;
    }

    public function existe(int $id): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM obrigacoes WHERE id = ?');
        $consulta->execute([$id]);

        return (bool) $consulta->fetchColumn();
    }
}
