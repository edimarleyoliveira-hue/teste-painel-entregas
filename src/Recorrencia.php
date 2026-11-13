<?php

/**
 * Regra de recorrência de uma obrigação (padrão da obrigação ou regra da empresa).
 *
 * Campos:
 * - recorrencia: diaria | semanal | mensal | trimestral | semestral | anual
 * - somente_dias_uteis: só na diária
 * - dia_semana: só na semanal (1 = segunda ... 7 = domingo)
 * - dia_mes: só na mensal (1 a 31)
 * - data_inicio: trimestral, semestral e anual (primeira cobrança)
 * - ajuste_vencimento: postergar | antecipar | manter (fim de semana ou feriado)
 */
class Recorrencia
{
    public const TIPOS = ['diaria', 'semanal', 'mensal', 'trimestral', 'semestral', 'anual'];
    public const AJUSTES = ['postergar', 'antecipar', 'manter'];

    // De quantos em quantos meses repete.
    private const MESES_POR_TIPO = ['trimestral' => 3, 'semestral' => 6, 'anual' => 12];

    /**
     * Valida os campos de recorrência vindos do formulário e devolve
     * [configuração normalizada, lista de erros]. Campos que não se aplicam ao tipo viram null.
     */
    public static function validar(array $dados, string $prefixoErro = ''): array
    {
        $erros = [];
        $tipo = textoDe($dados, 'recorrencia');
        $ajuste = textoDe($dados, 'ajuste_vencimento');

        $config = [
            'recorrencia' => $tipo,
            'somente_dias_uteis' => 1,
            'dia_semana' => null,
            'dia_mes' => null,
            'data_inicio' => null,
            'ajuste_vencimento' => $ajuste,
        ];

        if (!in_array($tipo, self::TIPOS, true)) {
            $erros[] = "{$prefixoErro}Escolha a recorrência (diária, semanal, mensal, trimestral, semestral ou anual).";
            return [$config, $erros];
        }

        if ($tipo === 'diaria') {
            // Aceita true/false, 1/0 e "true"/"false". Se não vier, o padrão é só dias úteis.
            $uteis = array_key_exists('somente_dias_uteis', $dados)
                ? filter_var($dados['somente_dias_uteis'], FILTER_VALIDATE_BOOLEAN)
                : true;
            $config['somente_dias_uteis'] = $uteis ? 1 : 0;
            // Na diária só existem dias úteis ou todos os dias; não há o que adiar.
            $config['ajuste_vencimento'] = 'manter';
        } elseif (!in_array($ajuste, self::AJUSTES, true)) {
            $erros[] = "{$prefixoErro}Escolha o que fazer quando o vencimento cair em fim de semana ou feriado.";
        }

        if ($tipo === 'semanal') {
            $dia = inteiroPositivo($dados['dia_semana'] ?? null);
            if ($dia === null || $dia > 7) {
                $erros[] = "{$prefixoErro}Escolha o dia da semana do vencimento.";
            }
            $config['dia_semana'] = $dia;
        }

        if ($tipo === 'mensal') {
            $dia = inteiroPositivo($dados['dia_mes'] ?? null);
            if ($dia === null || $dia > 31) {
                $erros[] = "{$prefixoErro}Informe o dia do mês do vencimento (1 a 31).";
            }
            $config['dia_mes'] = $dia;
        }

        if (isset(self::MESES_POR_TIPO[$tipo])) {
            $data = textoDe($dados, 'data_inicio');
            if (!dataValida($data)) {
                $erros[] = "{$prefixoErro}Informe a data da primeira cobrança.";
            }
            $config['data_inicio'] = $data;
        }

        return [$config, $erros];
    }

    /**
     * Vencimentos cuja data original (antes do ajuste) está entre $inicio e $fim, já ajustados para dia útil.
     * Devolve uma lista de ['competencia' => 'AAAA-MM', 'vencimento' => 'AAAA-MM-DD'].
     *
     * Competência:
     * - mensal, trimestral, semestral e anual: o mês ANTERIOR ao vencimento original,
     *   como na prática contábil (o DAS da competência 10/2026 vence em 11/2026);
     * - diária e semanal: o próprio mês do vencimento.
     */
    public static function ocorrencias(array $config, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $datas = match ($config['recorrencia']) {
            'diaria' => self::diarias($config, $inicio, $fim),
            'semanal' => self::semanais($config, $inicio, $fim),
            'mensal' => self::mensais($config, $inicio, $fim),
            default => self::periodicas($config, $inicio, $fim),
        };

        $competenciaNoMesAnterior = !in_array($config['recorrencia'], ['diaria', 'semanal'], true);

        $ocorrencias = [];
        foreach ($datas as $original) {
            $vencimento = Calendario::ajustar($original, $config['ajuste_vencimento']);
            $competencia = $competenciaNoMesAnterior
                ? $original->modify('first day of previous month')
                : $original;
            $ocorrencias[] = [
                'competencia' => $competencia->format('Y-m'),
                'vencimento' => $vencimento->format('Y-m-d'),
            ];
        }

        return $ocorrencias;
    }

    private static function diarias(array $config, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $datas = [];
        for ($dia = $inicio; $dia <= $fim; $dia = $dia->modify('+1 day')) {
            if (!$config['somente_dias_uteis'] || Calendario::ehDiaUtil($dia)) {
                $datas[] = $dia;
            }
        }
        return $datas;
    }

    private static function semanais(array $config, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        // Avança até o primeiro dia da semana escolhido e depois pula de 7 em 7 dias.
        $dia = $inicio;
        while ((int) $dia->format('N') !== (int) $config['dia_semana']) {
            $dia = $dia->modify('+1 day');
        }

        $datas = [];
        for (; $dia <= $fim; $dia = $dia->modify('+7 days')) {
            $datas[] = $dia;
        }
        return $datas;
    }

    private static function mensais(array $config, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $datas = [];
        $mes = $inicio->modify('first day of this month');

        for (; $mes <= $fim; $mes = $mes->modify('first day of next month')) {
            $data = self::diaNoMes($mes, (int) $config['dia_mes']);
            if ($data >= $inicio && $data <= $fim) {
                $datas[] = $data;
            }
        }
        return $datas;
    }

    // Trimestral, semestral e anual: a partir da primeira cobrança, a cada 3, 6 ou 12 meses.
    private static function periodicas(array $config, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $passo = self::MESES_POR_TIPO[$config['recorrencia']];
        $primeira = dataDe($config['data_inicio']);
        $diaDesejado = (int) $primeira->format('j');
        $mesBase = $primeira->modify('first day of this month');

        $datas = [];
        for ($k = 0; ; $k++) {
            $mes = $mesBase->modify('+' . ($k * $passo) . ' months');
            $data = self::diaNoMes($mes, $diaDesejado);
            if ($data > $fim) {
                break;
            }
            if ($data >= $inicio) {
                $datas[] = $data;
            }
        }
        return $datas;
    }

    // Dia X do mês; se o mês for mais curto (ex.: 31 em fevereiro), usa o último dia.
    private static function diaNoMes(DateTimeImmutable $primeiroDiaDoMes, int $dia): DateTimeImmutable
    {
        $ultimo = (int) $primeiroDiaDoMes->format('t');
        return $primeiroDiaDoMes->setDate(
            (int) $primeiroDiaDoMes->format('Y'),
            (int) $primeiroDiaDoMes->format('n'),
            min($dia, $ultimo)
        );
    }
}
