<?php

namespace App\Import;

/**
 * Convert a decimal string (e.g. "-768.792214" or "4'959.70") to integer minor units
 * for a 2-decimal currency. Rounds banker's-style on the final minor unit.
 */
final class MoneyParser
{
    public static function toMinor(string $decimal, int $exponent = 2): string
    {
        $normalized = str_replace(["'", ' '], '', trim($decimal));
        if ($normalized === '') {
            return '0';
        }
        // Treat trailing dot as no decimals.
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalized, $m)) {
            throw new \InvalidArgumentException("Invalid decimal value: {$decimal}");
        }
        $sign = $m[1];
        $intPart = $m[2];
        $fracPart = $m[3] ?? '';
        $fracPart = str_pad($fracPart, $exponent + 1, '0');
        $kept = substr($fracPart, 0, $exponent);
        $roundDigit = (int) ($fracPart[$exponent] ?? '0');

        $combined = ltrim($intPart . $kept, '0');
        if ($combined === '') {
            $combined = '0';
        }

        if ($roundDigit >= 5) {
            $combined = bcadd($combined, '1', 0);
        }

        return $sign . $combined;
    }
}
