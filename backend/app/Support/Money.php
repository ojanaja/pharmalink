<?php

namespace App\Support;

/**
 * Aritmetika uang dalam satuan sen (integer) — float tidak pernah dipakai
 * untuk uang. Cast model 'decimal:2' menghasilkan string tepat 2 digit
 * desimal, sehingga konversi string -> sen cukup membuang titik.
 */
class Money
{
    public static function toCents(?string $decimal): int
    {
        if ($decimal === null) {
            return 0;
        }

        return (int) str_replace('.', '', $decimal);
    }

    /**
     * Konversi hasil SQL SUM() kolom decimal: MySQL mengembalikan string
     * "15000.00", SQLite mengembalikan int/float. Jangan pernah cast mentah
     * ke string — "15000" akan terbaca sebagai sen, bukan Rupiah.
     */
    public static function fromSum(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (! is_string($value)) {
            $value = number_format((float) $value, 2, '.', '');
        }

        // String tanpa titik ("20000" dari PDO SQLite) = Rupiah penuh, bukan sen.
        if (! str_contains($value, '.')) {
            $value .= '.00';
        }

        return self::toCents($value);
    }

    public static function toDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * Pembagian dengan pembulatan setengah ke atas (untuk rata-rata),
     * tetap dalam integer sen.
     */
    public static function divRound(int $cents, int $divisor): string
    {
        return self::toDecimal(intdiv(2 * $cents + $divisor, 2 * $divisor));
    }

    public static function sum(iterable $decimals): int
    {
        $cents = 0;

        foreach ($decimals as $decimal) {
            $cents += self::toCents((string) $decimal);
        }

        return $cents;
    }
}
