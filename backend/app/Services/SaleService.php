<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Models\Batch;
use App\Models\Medicine;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penjualan kasir: satu transaction menghasilkan header + item + pengurangan
 * stok FEFO + movement. Tidak ada penjualan parsial — gagal berarti rollback total.
 */
class SaleService
{
    public function __construct(
        protected NumberGenerator $numbers,
        protected StockService $stock,
    ) {
    }

    /**
     * @param  array{items: array<int, array{medicine_id: int, quantity: int}>, discount?: string|null, payment_method?: string|null}  $data
     */
    public function create(array $data, User $user): Sale
    {
        return DB::transaction(function () use ($data, $user) {
            // Harga diambil server-side dari master obat (snapshot); client tidak mengirim harga.
            $prices = $this->resolvePrices($data['items']);
            $subtotalCents = 0;

            foreach ($data['items'] as $item) {
                $subtotalCents += $this->toCents($prices[$item['medicine_id']]) * $item['quantity'];
            }

            $discountCents = $this->toCents($this->normalizeMoney($data['discount'] ?? '0.00'));

            if ($discountCents > $subtotalCents) {
                throw ValidationException::withMessages([
                    'discount' => ['Diskon tidak boleh melebihi subtotal.'],
                ]);
            }

            $sale = Sale::create([
                // Invariant uang: semua nilai disimpan presisi DECIMAL(15,2);
                // tidak ada pembulatan ke Rp100 — tidak ada kebutuhan kas ketat (M7).
                'invoice_number' => $this->numbers->generate('TRX', 'sales', 'invoice_number'),
                'sold_at' => now(),
                'user_id' => $user->id,
                'subtotal' => $this->toDecimal($subtotalCents),
                'discount' => $this->toDecimal($discountCents),
                'total' => $this->toDecimal($subtotalCents - $discountCents),
                'payment_method' => $data['payment_method'] ?? 'tunai',
                'status' => SaleStatus::Completed,
            ]);

            foreach ($data['items'] as $item) {
                $this->sellItem($sale, $item, $prices[$item['medicine_id']]);
            }

            return $sale->load(['items.batch', 'items.medicine', 'user']);
        });
    }

    /**
     * Kurangi stok satu item dengan strategi FEFO: batch yang paling dulu
     * kedaluwarsa dikeluarkan lebih dulu; quantity dipecah antar batch bila perlu.
     * Batch kedaluwarsa (sebelum hari ini) tidak pernah dipilih.
     */
    protected function sellItem(Sale $sale, array $item, string $unitPrice): void
    {
        $requested = $item['quantity'];

        $batches = Batch::query()
            ->where('medicine_id', $item['medicine_id'])
            ->where('quantity_on_hand', '>', 0)
            ->whereDate('expiry_date', '>=', today())
            ->lockForUpdate()
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get();

        $available = (int) $batches->sum('quantity_on_hand');

        if ($available < $requested) {
            $medicine = Medicine::query()->find($item['medicine_id']);

            throw new HttpResponseException(response()->json([
                'message' => "Stok '{$medicine->name}' tidak cukup.",
                'errors' => [
                    'items' => [[
                        'medicine_id' => $item['medicine_id'],
                        'name' => $medicine->name,
                        'requested' => $requested,
                        'available' => $available,
                    ]],
                ],
            ], 422));
        }

        $remaining = $requested;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($batch->quantity_on_hand, $remaining);

            $saleItem = $sale->items()->create([
                'medicine_id' => $item['medicine_id'],
                'batch_id' => $batch->id,
                'quantity' => $take,
                'unit_price' => $unitPrice,
                'subtotal' => $this->toDecimal($this->toCents($unitPrice) * $take),
            ]);

            $this->stock->deduct($batch, $take, MovementType::Sale, $saleItem, null, $unitPrice);

            $remaining -= $take;
        }
    }

    /**
     * @param  array<int, array{medicine_id: int}>  $items
     * @return array<int, string> medicine_id => sale_price (string decimal)
     */
    protected function resolvePrices(array $items): array
    {
        $prices = [];

        foreach ($items as $item) {
            if (isset($prices[$item['medicine_id']])) {
                continue;
            }

            $medicine = Medicine::query()->find($item['medicine_id']);

            if ($medicine === null || ! $medicine->is_active) {
                throw ValidationException::withMessages([
                    'items' => ['Obat tidak ditemukan atau tidak aktif.'],
                ]);
            }

            $prices[$item['medicine_id']] = (string) $medicine->sale_price;
        }

        return $prices;
    }

    /*
    |--------------------------------------------------------------------------
    | Aritmetika uang
    |--------------------------------------------------------------------------
    | Invariant: seluruh perhitungan dilakukan dalam satuan sen (integer);
    | float tidak pernah dipakai untuk uang. Cast model 'decimal:2' selalu
    | menghasilkan string dengan tepat 2 digit desimal, sehingga konversi
    | string -> sen cukup dengan membuang titik desimal.
    */

    /**
     * Normalisasi input uang dari JSON (int/float/string) ke string desimal
     * tepat 2 digit. Invariant: integer selalu Rupiah penuh (1000 = "1000.00"),
     * bukan sen — kesalahan ini pernah membuat discount:1000 tersimpan "10.00".
     */
    private function normalizeMoney(int|float|string $value): string
    {
        if (is_int($value)) {
            return sprintf('%d.00', $value);
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction.'00', 0, 2), 2, '0');
    }

    private function toCents(string $decimal): int
    {
        return (int) str_replace('.', '', $decimal);
    }

    private function toDecimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
