<?php

class EmpresaRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function listar(): array
    {
        $sql = "SELECT id, razao_social, cnpj
                  FROM empresas
                 ORDER BY razao_social ASC";

        $empresas = $this->pdo->query($sql)->fetchAll();

        foreach ($empresas as &$empresa) {
            $empresa['id'] = (int) $empresa['id'];
        }

        return $empresas;
    }

    public function existe(int $id): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM empresas WHERE id = ?');
        $consulta->execute([$id]);

        return (bool) $consulta->fetchColumn();
    }
}
