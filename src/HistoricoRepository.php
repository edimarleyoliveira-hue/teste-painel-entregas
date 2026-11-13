<?php

class HistoricoRepository
{
    // Quantas ações mostrar no máximo (as mais recentes).
    private const LIMITE = 200;

    public function __construct(private PDO $pdo)
    {
    }

    // Ações feitas numa entrega (marcou como entregue / voltou para pendente), da mais recente para a mais antiga.
    public function porEntrega(int $entregaId): array
    {
        $sql = "SELECT h.id, h.acao, h.data_entrega, h.usuario, h.realizado_em
                  FROM historico_entregas h
                 WHERE h.entrega_id = ?
                 ORDER BY h.realizado_em DESC, h.id DESC
                 LIMIT " . self::LIMITE;

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([$entregaId]);
        $historico = $consulta->fetchAll();

        foreach ($historico as &$acao) {
            $acao['id'] = (int) $acao['id'];
        }

        return $historico;
    }
}
