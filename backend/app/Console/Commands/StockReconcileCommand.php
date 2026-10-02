<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rekonsiliasi snapshot stok vs ledger:
 *   php artisan stock:reconcile         -> laporkan drift, exit 1 bila ada
 *   php artisan stock:reconcile --fix   -> perbaiki snapshot dari ledger
 *
 * Snapshot (batches.quantity_on_hand) adalah cache dari ledger (movement
 * balance_after terakhir per batch). --fix TIDAK menulis movement — saldo
 * yang benar sudah ada di ledger; yang dikoreksi hanya cache-nya.
 */
class StockReconcileCommand extends Command
{
    protected $signature = 'stock:reconcile {--fix : Rekonsiliasi snapshot dari ledger}';

    protected $description = 'Bandingkan snapshot stok batch dengan ledger movement';

    public function handle(): int
    {
        $latestIds = StockMovement::query()
            ->whereNotNull('batch_id')
            ->selectRaw('MAX(id) as max_id')
            ->groupBy('batch_id');

        /** @var array<int, int> $ledger balance_after terakhir per batch */
        $ledger = StockMovement::query()
            ->whereIn('id', $latestIds->pluck('max_id'))
            ->pluck('balance_after', 'batch_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        /** @var array<int, int> $snapshots quantity_on_hand saat ini */
        $snapshots = Batch::query()->pluck('quantity_on_hand', 'id')->map(fn ($v) => (int) $v)->all();

        $drift = [];
        foreach ($snapshots as $batchId => $qty) {
            $expected = $ledger[$batchId] ?? 0; // batch tanpa movement dianggap saldo 0
            if ($qty !== $expected) {
                $drift[$batchId] = ['snapshot' => $qty, 'ledger' => $expected];
            }
        }

        if ($drift === []) {
            $this->info('OK: snapshot stok cocok dengan ledger untuk '.count($snapshots).' batch.');
            return self::SUCCESS;
        }

        foreach ($drift as $batchId => $d) {
            $this->error("Drift batch #{$batchId}: snapshot={$d['snapshot']} ledger={$d['ledger']}");
        }

        if ($this->option('fix')) {
            DB::transaction(function () use ($drift) {
                foreach ($drift as $batchId => $d) {
                    Batch::whereKey($batchId)->update(['quantity_on_hand' => $d['ledger']]);
                }
            });
            $this->warn('--fix: '.count($drift).' snapshot diperbaiki dari ledger (tanpa menulis movement).');

            return self::SUCCESS;
        }

        $this->warn(count($drift).' batch drift. Jalankan dengan --fix untuk memperbaiki snapshot.');

        return self::FAILURE;
    }
}
