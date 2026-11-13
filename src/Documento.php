<?php

/**
 * Validação e formatação de CPF e CNPJ (cálculo oficial dos dígitos verificadores).
 * O CNPJ pode ser alfanumérico (Receita Federal, a partir de julho de 2026): as 12 primeiras
 * posições aceitam letras e números; os 2 dígitos verificadores continuam numéricos.
 */
class Documento
{
    public const TIPOS = ['CNPJ', 'CPF'];

    // Remove tudo que não é dígito (usado em CPF, CEP e telefone).
    public static function digitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor);
    }

    // Deixa só letras e números, em maiúsculas (usado no CNPJ e na busca por documento).
    public static function caracteres(string $valor): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $valor));
    }

    public static function valido(string $tipo, string $valor): bool
    {
        return $tipo === 'CPF'
            ? self::cpfValido(self::digitos($valor))
            : self::cnpjValido(self::caracteres($valor));
    }

    // Devolve no formato oficial: 000.000.000-00 (CPF) ou 00.000.000/0000-00 (CNPJ).
    public static function formatar(string $tipo, string $valor): string
    {
        if ($tipo === 'CPF') {
            $n = self::digitos($valor);
            return substr($n, 0, 3) . '.' . substr($n, 3, 3) . '.' . substr($n, 6, 3) . '-' . substr($n, 9, 2);
        }

        $n = self::caracteres($valor);
        return substr($n, 0, 2) . '.' . substr($n, 2, 3) . '.' . substr($n, 5, 3) . '/' . substr($n, 8, 4) . '-' . substr($n, 12, 2);
    }

    private static function cpfValido(string $n): bool
    {
        // 11 dígitos e não todos iguais (111.111.111-11 passa no cálculo, mas não existe).
        if (strlen($n) !== 11 || preg_match('/^(\d)\1{10}$/', $n)) {
            return false;
        }

        for ($posicao = 9; $posicao < 11; $posicao++) {
            $soma = 0;
            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $n[$i] * (($posicao + 1) - $i);
            }
            $digito = ($soma * 10) % 11 % 10;
            if ((int) $n[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }

    private static function cnpjValido(string $n): bool
    {
        if (preg_match('/^[A-Z0-9]{12}[0-9]{2}$/D', $n) !== 1 || preg_match('/^(.)\1{13}$/', $n)) {
            return false;
        }

        $pesos = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        for ($posicao = 12; $posicao < 14; $posicao++) {
            $soma = 0;
            // Primeiro dígito usa os pesos 5,4,3,2,9..2; o segundo usa 6,5,4,3,2,9..2.
            $inicioPesos = $posicao === 12 ? 1 : 0;
            for ($i = 0; $i < $posicao; $i++) {
                // Valor de cada posição: código ASCII - 48 ("0" = 0 ... "9" = 9, "A" = 17, "B" = 18...).
                $soma += (ord($n[$i]) - 48) * $pesos[$i + $inicioPesos];
            }
            $resto = $soma % 11;
            $digito = $resto < 2 ? 0 : 11 - $resto;
            if ((int) $n[$posicao] !== $digito) {
                return false;
            }
        }

        return true;
    }
}
