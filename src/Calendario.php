<?php

// Dias úteis e feriados nacionais (fixos + móveis calculados a partir da Páscoa).
class Calendario
{
    // Cache por ano para não recalcular a cada dia.
    private static array $feriadosPorAno = [];

    /**
     * Feriados nacionais do ano, no formato AAAA-MM-DD => nome.
     * Carnaval, Sexta-feira Santa e Corpus Christi entram porque, na prática,
     * os órgãos e bancos não funcionam nesses dias.
     */
    public static function feriados(int $ano): array
    {
        if (isset(self::$feriadosPorAno[$ano])) {
            return self::$feriadosPorAno[$ano];
        }

        $fixos = [
            '01-01' => 'Confraternização Universal',
            '04-21' => 'Tiradentes',
            '05-01' => 'Dia do Trabalho',
            '09-07' => 'Independência do Brasil',
            '10-12' => 'Nossa Senhora Aparecida',
            '11-02' => 'Finados',
            '11-15' => 'Proclamação da República',
            '11-20' => 'Dia Nacional de Zumbi e da Consciência Negra',
            '12-25' => 'Natal',
        ];

        $feriados = [];
        foreach ($fixos as $mesDia => $nome) {
            $feriados["{$ano}-{$mesDia}"] = $nome;
        }

        // easter_days() devolve quantos dias a Páscoa cai depois de 21 de março.
        $pascoa = (new DateTimeImmutable("{$ano}-03-21"))->modify('+' . easter_days($ano) . ' days');
        $moveis = [
            '-48 days' => 'Carnaval (segunda-feira)',
            '-47 days' => 'Carnaval (terça-feira)',
            '-2 days' => 'Sexta-feira Santa',
            '+60 days' => 'Corpus Christi',
        ];
        foreach ($moveis as $deslocamento => $nome) {
            $feriados[$pascoa->modify($deslocamento)->format('Y-m-d')] = $nome;
        }

        ksort($feriados);

        return self::$feriadosPorAno[$ano] = $feriados;
    }

    public static function ehFeriado(DateTimeImmutable $data): bool
    {
        return isset(self::feriados((int) $data->format('Y'))[$data->format('Y-m-d')]);
    }

    // Dia útil = segunda a sexta e não feriado nacional.
    public static function ehDiaUtil(DateTimeImmutable $data): bool
    {
        return (int) $data->format('N') <= 5 && !self::ehFeriado($data);
    }

    /**
     * Ajusta um vencimento que caiu em fim de semana ou feriado:
     * - postergar: vai para o próximo dia útil;
     * - antecipar: volta para o dia útil anterior;
     * - manter: não mexe.
     */
    public static function ajustar(DateTimeImmutable $data, string $modo): DateTimeImmutable
    {
        if ($modo === 'manter') {
            return $data;
        }

        $passo = $modo === 'antecipar' ? '-1 day' : '+1 day';
        while (!self::ehDiaUtil($data)) {
            $data = $data->modify($passo);
        }

        return $data;
    }
}
