<?php

/**
 * Gera as entregas de uma obrigação numa empresa, seguindo a regra da empresa,
 * para os próximos 12 meses a partir de hoje.
 */
class GeradorEntregas
{
    private const MESES_A_GERAR = 12;

    // Folga para trás: um vencimento original de alguns dias atrás pode ter sido adiado para hoje ou depois.
    private const DIAS_DE_FOLGA = 10;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Regra nova ou alterada: apaga as entregas futuras ainda não entregues e gera de novo.
     * Entregas já entregues e as atrasadas (vencimento passado) ficam como estão.
     * Devolve quantas entregas foram criadas.
     */
    public function regerar(int $empresaId, int $obrigacaoId, array $config): int
    {
        $this->apagarFuturasPendentes($empresaId, $obrigacaoId);

        return $this->completar($empresaId, $obrigacaoId, $config);
    }

    /**
     * Só cria o que falta na janela de 12 meses, sem apagar nada.
     * Usado também quando a regra não mudou, para a janela "andar" com o tempo.
     */
    public function completar(int $empresaId, int $obrigacaoId, array $config): int
    {
        $hoje = $this->hoje();
        $fim = $hoje->modify('+' . self::MESES_A_GERAR . ' months')->modify('-1 day');
        $inicio = $hoje->modify('-' . self::DIAS_DE_FOLGA . ' days');

        // Mensal, trimestral, semestral e anual: uma entrega por competência.
        // Diária e semanal: várias por competência (uma por vencimento).
        $umaPorCompetencia = !in_array($config['recorrencia'], ['diaria', 'semanal'], true);

        $existeNaCompetencia = $this->pdo->prepare(
            'SELECT 1 FROM entregas WHERE empresa_id = ? AND obrigacao_id = ? AND competencia = ? LIMIT 1'
        );

        // Se já existir a mesma entrega (mesmo vencimento), o banco ignora a repetida.
        $inserir = $this->pdo->prepare(
            "INSERT INTO entregas (empresa_id, obrigacao_id, competencia, vencimento, data_entrega)
             VALUES (?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE id = id"
        );

        $criadas = 0;
        foreach (Recorrencia::ocorrencias($config, $inicio, $fim) as $ocorrencia) {
            // Só vencimentos (já ajustados) de hoje em diante.
            if ($ocorrencia['vencimento'] < $hoje->format('Y-m-d')) {
                continue;
            }

            if ($umaPorCompetencia) {
                $existeNaCompetencia->execute([$empresaId, $obrigacaoId, $ocorrencia['competencia']]);
                if ($existeNaCompetencia->fetchColumn()) {
                    continue;
                }
            }

            $inserir->execute([$empresaId, $obrigacaoId, $ocorrencia['competencia'], $ocorrencia['vencimento']]);
            // rowCount() = 1 quando inseriu; 0 quando a entrega já existia.
            $criadas += $inserir->rowCount() === 1 ? 1 : 0;
        }

        return $criadas;
    }

    // Usado quando a regra muda ou a obrigação é retirada da empresa.
    public function apagarFuturasPendentes(int $empresaId, int $obrigacaoId): void
    {
        $sql = "DELETE FROM entregas
                 WHERE empresa_id = ? AND obrigacao_id = ?
                   AND data_entrega IS NULL
                   AND vencimento >= ?";

        $this->pdo->prepare($sql)->execute([$empresaId, $obrigacaoId, $this->hoje()->format('Y-m-d')]);
    }

    // "Hoje" segundo o banco: o mesmo relógio do resumo e dos filtros.
    private function hoje(): DateTimeImmutable
    {
        return dataDe($this->pdo->query('SELECT CURDATE()')->fetchColumn());
    }
}
