<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\OpnameStatus;
use App\Models\Batch;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock opname 3 tahap: snapshot sistem -> input fisik (boleh bertahap) ->
 * konfirmasi. Stok fisik TIDAK berubah sampai confirm; confirm menulis
 * movement hanya untuk item yang selisih.
 */
class StockOpnameService
{
    public function __construct(
        protected NumberGenerator $numbers,
        protected StockService $stock,
    ) {
    }

    /**
     * Snapshot semua batch berstok; batch dikunci agar tidak ada mutasi di tengah.
     */
    public function create(array $data, User $user): StockOpname
    {
        return DB::transaction(function () use ($data, $user) {
            $opname = StockOpname::create([
                'opname_number' => $this->numbers->generate('OPN', 'stock_opnames', 'opname_number'),
                'opname_at' => today(),
                'status' => OpnameStatus::Draft,
                'user_id' => $user->id,
                'note' => $data['note'] ?? null,
            ]);

            $batches = Batch::query()
                ->where('quantity_on_hand', '>', 0)
                ->lockForUpdate()
                ->orderBy('medicine_id')
                ->orderBy('expiry_date')
                ->orderBy('id')
                ->get();

            foreach ($batches as $batch) {
                $opname->items()->create([
                    'medicine_id' => $batch->medicine_id,
                    'batch_id' => $batch->id,
                    'system_qty' => $batch->quantity_on_hand,
                    'physical_qty' => $batch->quantity_on_hand, // default: diasumsikan sama
                    'reason' => null,
                ]);
            }

            return $opname->load(['items.batch:id,batch_number,expiry_date', 'items.medicine:id,code,name']);
        });
    }

    /**
     * Input hasil hitung fisik; boleh bertahap/bolak-balik selama masih draft.
     *
     * @param  array{counts: array<int, array{opname_item_id: int, physical_qty: int, reason?: string|null}>}  $data
     */
    public function updateCounts(StockOpname $opname, array $data): StockOpname
    {
        $this->assertDraft($opname);

        return DB::transaction(function () use ($opname, $data) {
            $items = $opname->items()->lockForUpdate()->get()->keyBy('id');

            foreach ($data['counts'] as $count) {
                $item = $items->get($count['opname_item_id']);

                if ($item === null) {
                    throw ValidationException::withMessages([
                        'counts' => ["Item opname #{$count['opname_item_id']} bukan bagian dari sesi ini."],
                    ]);
                }

                // Alasan wajib bila hasil fisik berbeda dari catatan sistem.
                if ($count['physical_qty'] !== $item->system_qty
                    && empty($count['reason'] ?? $item->reason)) {
                    throw ValidationException::withMessages([
                        "counts.{$count['opname_item_id']}.reason" => ['Alasan wajib diisi bila jumlah fisik berbeda dari sistem.'],
                    ]);
                }

                $item->physical_qty = $count['physical_qty'];
                $item->reason = $count['reason'] ?? $item->reason;
                $item->save();
            }

            return $opname->load(['items.batch:id,batch_number,expiry_date', 'items.medicine:id,code,name']);
        });
    }

    /**
     * Konfirmasi opname: tulis movement hanya untuk selisih != 0.
     * Selisih dihitung dari SALDO LIVE batch (bukan snapshot system_qty yang
     * bisa basi); bila stok bergerak sejak snapshot -> 422, minta sesi baru.
     * Opname confirmed bersifat immutable.
     */
    public function confirm(StockOpname $opname): StockOpname
    {
        $this->assertDraft($opname);

        return DB::transaction(function () use ($opname) {
            // Kunci baris opname + re-check status di dalam transaksi (pola SaleVoidService).
            $locked = StockOpname::whereKey($opname->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($locked);

            $items = $locked->items()->lockForUpdate()->get();

            $liveBatches = Batch::query()
                ->whereIn('id', $items->pluck('batch_id')->filter())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $batch = $liveBatches->get($item->batch_id);
                $liveQty = $batch !== null ? (int) $batch->quantity_on_hand : 0;

                // Snapshot basi: stok bergerak sejak sesi dibuat.
                if ($item->system_qty !== $liveQty) {
                    throw ValidationException::withMessages([
                        'opname' => ["Stok batch #{$item->batch_id} berubah sejak snapshot (sistem {$item->system_qty}, sekarang {$liveQty}). Buat sesi opname baru."],
                    ]);
                }

                $diff = $item->physical_qty - $liveQty;

                if ($diff === 0) {
                    continue;
                }

                $reason = $item->reason ?? "Stock opname {$locked->opname_number}";
                $type = MovementType::Opname;

                if ($diff > 0) {
                    $this->stock->add($batch, $diff, $type, $locked, $reason);
                } else {
                    $this->stock->deduct($batch, abs($diff), $type, $locked, $reason);
                }
            }

            $locked->status = OpnameStatus::Confirmed;
            $locked->save();

            return $locked->load(['items.batch:id,batch_number,expiry_date', 'items.medicine:id,code,name']);
        });
    }

    /**
     * @throws HttpResponseException 409 bila opname sudah confirmed.
     */
    protected function assertDraft(StockOpname $opname): void
    {
        if ($opname->status !== OpnameStatus::Draft) {
            throw new HttpResponseException(response()->json([
                'message' => "Stock opname {$opname->opname_number} sudah dikonfirmasi dan tidak dapat diubah.",
            ], 409));
        }
    }
}
