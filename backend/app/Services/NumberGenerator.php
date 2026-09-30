<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generator nomor transaksi: {prefix}-{ymd}-{seq4}, nomor urut per hari.
 * Wajib dipanggil di dalam DB::transaction; unique constraint di kolom nomor
 * adalah pengaman terakhir, dengan satu kali percobaan ulang bila bentrok.
 */
class NumberGenerator
{
    public function generate(string $prefix, string $table, string $column): string
    {
        $date = now()->format('Ymd');
        $pattern = "{$prefix}-{$date}-%";

        // Count nomor hari ini; murah untuk volume transaksi apotek mikro.
        $sequence = DB::table($table)->where($column, 'like', $pattern)->count() + 1;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $number = sprintf('%s-%s-%04d', $prefix, $date, $sequence + $attempt);

            if (! DB::table($table)->where($column, $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException("Gagal membuat nomor transaksi untuk prefix {$prefix}.");
    }
}
