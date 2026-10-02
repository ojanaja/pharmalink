<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nomor dokumen retur: RET-{ymd}-{seq4} unik, dibuat service untuk data
     * baru; data existing di-backfill per tanggal created_at agar unik.
     * Langkah idempotent karena run pertama pernah gagal di tengah (DDL
     * MySQL tidak transaksional).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('sale_returns', 'return_number')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->string('return_number', 50)->nullable()->after('id');
            });
        }

        // Backfill: seq per tanggal, urut created_at agar deterministik.
        $perDate = [];
        $rows = DB::table('sale_returns')->whereNull('return_number')->orderBy('created_at')->orderBy('id')->get(['id', 'created_at']);

        foreach ($rows as $row) {
            $date = Carbon::parse($row->created_at)->format('Ymd');
            $perDate[$date] = ($perDate[$date] ?? 0) + 1;
            DB::table('sale_returns')->where('id', $row->id)->update([
                'return_number' => sprintf('RET-%s-%04d', $date, $perDate[$date]),
            ]);
        }

        try {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->unique('return_number', 'uk_sale_returns_number');
            });
        } catch (\Illuminate\Database\QueryException) {
            // Unique sudah ada (run sebelumnya gagal di tengah); idempotent.
        }
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropUnique('uk_sale_returns_number');
            $table->dropColumn('return_number');
        });
    }
};
