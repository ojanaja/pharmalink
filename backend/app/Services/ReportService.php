<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Batch;
use App\Models\Medicine;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Laporan operasional per periode. Semua agregasi uang dalam sen (integer);
 * unit_price/total dari DB adalah string decimal 2 digit (cast model decimal:2).
 */
class ReportService
{
    /**
     * Laporan penjualan: omzet, jumlah transaksi, rata-rata, rincian per obat.
     *
     * @return array{summary: array{omzet: string, transactions: int, average: string}, medicines: array<int, array<string, mixed>>}
     */
    public function sales(Carbon $from, Carbon $to): array
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $base = Sale::query()
            ->where('status', SaleStatus::Completed)
            ->whereBetween('sold_at', $range);

        $omzetCents = Money::fromSum((clone $base)->sum('total'));
        $count = (clone $base)->count();

        return [
            'summary' => [
                'omzet' => Money::toDecimal($omzetCents),
                'transactions' => $count,
                'average' => $count > 0 ? Money::divRound($omzetCents, $count) : '0.00',
            ],
            'medicines' => $this->salesPerMedicine($range),
        ];
    }

    /**
     * Agregasi di PHP (bukan SQL) — sqlite menormalkan kolom decimal menjadi
     * angka, sehingga trik SQL REPLACE('.', '') tidak portabel. Volume apotek
     * mikro membuat ini lebih dari cukup dan tetap exact dalam sen.
     *
     * @return array<int, array<string, mixed>> [kode, nama, qty, omzet]
     */
    public function salesPerMedicine(array $range): array
    {
        $rows = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('medicines', 'medicines.id', '=', 'sale_items.medicine_id')
            ->where('sales.status', SaleStatus::Completed)
            ->whereBetween('sales.sold_at', $range)
            ->orderBy('medicines.name')
            ->get(['sale_items.medicine_id', 'medicines.code', 'medicines.name', 'sale_items.quantity', 'sale_items.unit_price']);

        $grouped = [];
        foreach ($rows as $row) {
            $g = $grouped[$row->medicine_id] ?? ['code' => $row->code, 'name' => $row->name, 'quantity' => 0, 'cents' => 0];
            $g['quantity'] += $row->quantity;
            $g['cents'] += $row->quantity * Money::fromSum($row->unit_price);
            $grouped[$row->medicine_id] = $g;
        }

        return array_map(fn ($g) => [
            'code' => $g['code'],
            'name' => $g['name'],
            'quantity' => $g['quantity'],
            'omzet' => Money::toDecimal($g['cents']),
        ], array_values($grouped));
    }

    /**
     * Laporan pembelian dari data penerimaan (uang keluar saat barang diterima).
     *
     * @return array{summary: array{total: string, receipts: int}, medicines: array, suppliers: array}
     */
    public function purchases(Carbon $from, Carbon $to): array
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $cents = 0;
        $items = PurchaseReceiptItem::query()
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_items.receipt_id')
            ->whereBetween('purchase_receipts.received_at', $range)
            ->get(['purchase_receipt_items.quantity', 'purchase_receipt_items.unit_price']);

        foreach ($items as $item) {
            $cents += $item->quantity * Money::fromSum($item->unit_price);
        }

        return [
            'summary' => [
                'total' => Money::toDecimal($cents),
                'receipts' => PurchaseReceipt::query()->whereBetween('received_at', $range)->count(),
            ],
            'medicines' => $this->purchasesPerMedicine($range),
            'suppliers' => $this->purchasesPerSupplier($range),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function purchasesPerMedicine(array $range): array
    {
        $rows = PurchaseReceiptItem::query()
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_items.receipt_id')
            ->join('medicines', 'medicines.id', '=', 'purchase_receipt_items.medicine_id')
            ->whereBetween('purchase_receipts.received_at', $range)
            ->orderBy('medicines.name')
            ->get(['purchase_receipt_items.medicine_id', 'medicines.code', 'medicines.name',
                'purchase_receipt_items.quantity', 'purchase_receipt_items.unit_price']);

        $grouped = [];
        foreach ($rows as $row) {
            $g = $grouped[$row->medicine_id] ?? ['code' => $row->code, 'name' => $row->name, 'quantity' => 0, 'cents' => 0];
            $g['quantity'] += $row->quantity;
            $g['cents'] += $row->quantity * Money::fromSum($row->unit_price);
            $grouped[$row->medicine_id] = $g;
        }

        return array_map(fn ($g) => [
            'code' => $g['code'],
            'name' => $g['name'],
            'quantity' => $g['quantity'],
            'total' => Money::toDecimal($g['cents']),
        ], array_values($grouped));
    }

    /**
     * Per supplier hanya mungkin dari penerimaan ber-PO; penerimaan manual
     * masuk bucket "Penerimaan manual (tanpa PO)".
     *
     * @return array<int, array<string, mixed>>
     */
    public function purchasesPerSupplier(array $range): array
    {
        $rows = PurchaseReceiptItem::query()
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_items.receipt_id')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_receipts.po_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->whereNotNull('purchase_receipts.po_id')
            ->whereBetween('purchase_receipts.received_at', $range)
            ->orderBy('suppliers.name')
            ->get(['suppliers.id', 'suppliers.name', 'purchase_receipts.id as receipt_id',
                'purchase_receipt_items.quantity', 'purchase_receipt_items.unit_price']);

        $grouped = [];
        foreach ($rows as $row) {
            $g = $grouped[$row->id] ?? ['id' => (int) $row->id, 'name' => $row->name, 'receipts' => [], 'cents' => 0];
            $g['receipts'][$row->receipt_id] = true;
            $g['cents'] += $row->quantity * Money::fromSum($row->unit_price);
            $grouped[$row->id] = $g;
        }

        $withPo = array_map(fn ($g) => [
            'id' => $g['id'],
            'name' => $g['name'],
            'receipts' => count($g['receipts']),
            'total' => Money::toDecimal($g['cents']),
        ], array_values($grouped));

        $manualRows = PurchaseReceiptItem::query()
            ->join('purchase_receipts', 'purchase_receipts.id', '=', 'purchase_receipt_items.receipt_id')
            ->whereNull('purchase_receipts.po_id')
            ->whereBetween('purchase_receipts.received_at', $range)
            ->get(['purchase_receipt_items.quantity', 'purchase_receipt_items.unit_price', 'purchase_receipts.id as receipt_id']);

        $manualReceipts = $manualRows->pluck('receipt_id')->unique()->count();
        $manualCents = 0;
        foreach ($manualRows as $row) {
            $manualCents += $row->quantity * Money::fromSum($row->unit_price);
        }

        if ($manualReceipts > 0) {
            $withPo[] = [
                'id' => null,
                'name' => 'Penerimaan manual (tanpa PO)',
                'receipts' => $manualReceipts,
                'total' => Money::toDecimal($manualCents),
            ];
        }

        return $withPo;
    }

    /**
     * Kondisi stok saat ini + nilai persediaan.
     * Nilai stok memakai purchase_price batch (harga beli terakhir per batch);
     * batch tanpa harga beli dinilai 0 dan dihitung terpisah.
     *
     * @return array{medicines: array<int, array<string, mixed>>, total_value: string, items_without_purchase_price: int}
     */
    public function stock(): array
    {
        $medicines = Medicine::query()
            ->with(['category:id,name', 'batches:id,medicine_id,quantity_on_hand,purchase_price'])
            ->withSum('batches as stock_total', 'quantity_on_hand')
            ->orderBy('name')
            ->get();

        $rows = [];
        $totalCents = 0;
        $withoutPrice = 0;

        foreach ($medicines as $m) {
            $stockTotal = (int) ($m->stock_total ?? 0);
            $valueCents = 0;

            foreach ($m->batches as $batch) {
                if ($batch->purchase_price === null && $batch->quantity_on_hand > 0) {
                    $withoutPrice++;
                }
                $valueCents += $batch->quantity_on_hand * Money::toCents((string) $batch->purchase_price);
            }

            $totalCents += $valueCents;

            $rows[] = [
                'code' => $m->code,
                'name' => $m->name,
                'category' => $m->category?->name,
                'stock_total' => $stockTotal,
                'min_stock' => $m->min_stock,
                'status' => $stockTotal === 0 ? 'habis' : ($stockTotal <= $m->min_stock ? 'menipis' : 'aman'),
                'stock_value' => Money::toDecimal($valueCents),
            ];
        }

        return [
            'medicines' => $rows,
            'total_value' => Money::toDecimal($totalCents),
            'items_without_purchase_price' => $withoutPrice,
        ];
    }

    /**
     * Batch kedaluwarsa / mendekati kedaluwarsa dalam N hari.
     *
     * @return array{expired: array<int, array<string, mixed>>, expiring: array<int, array<string, mixed>>, counts: array{expired: int, expiring: int}}
     */
    public function expiry(int $days): array
    {
        $today = today();
        $limit = $today->copy()->addDays($days);

        $map = fn (Batch $b) => [
            'medicine' => ['code' => $b->medicine->code, 'name' => $b->medicine->name],
            'batch_number' => $b->batch_number,
            'expiry_date' => $b->expiry_date?->toDateString(),
            'quantity_on_hand' => $b->quantity_on_hand,
            // Nilai berisiko dinilai dari harga beli batch (0 bila belum ada).
            'stock_value' => Money::toDecimal(
                $b->quantity_on_hand * Money::toCents((string) $b->purchase_price)
            ),
        ];

        $expired = Batch::query()
            ->with('medicine:id,code,name')
            ->where('quantity_on_hand', '>', 0)
            ->whereDate('expiry_date', '<', $today->toDateString())
            ->orderBy('expiry_date')
            ->get()
            ->map($map)
            ->all();

        $expiring = Batch::query()
            ->with('medicine:id,code,name')
            ->where('quantity_on_hand', '>', 0)
            ->whereDate('expiry_date', '>=', $today->toDateString())
            ->whereDate('expiry_date', '<=', $limit->toDateString())
            ->orderBy('expiry_date')
            ->get()
            ->map($map)
            ->all();

        return [
            'expired' => $expired,
            'expiring' => $expiring,
            'counts' => ['expired' => count($expired), 'expiring' => count($expiring)],
        ];
    }

    /**
     * Laba rugi sederhana: HPP memakai purchase_price BATCH asal keluar
     * (sale_items.batch_id), bukan harga beli master — harga beli bisa beda
     * antar batch. Item tanpa harga beli dihitung HPP 0 dan dilaporkan.
     *
     * @return array{sales: string, cogs: string, gross_profit: string, items_without_cost: int}
     */
    public function profitLoss(Carbon $from, Carbon $to): array
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        $omzetCents = Money::fromSum(Sale::query()
            ->where('status', SaleStatus::Completed)
            ->whereBetween('sold_at', $range)
            ->sum('total'));

        $items = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('batches', 'batches.id', '=', 'sale_items.batch_id')
            ->where('sales.status', SaleStatus::Completed)
            ->whereBetween('sales.sold_at', $range)
            ->get(['sale_items.quantity', 'batches.purchase_price']);

        $cogsCents = 0;
        $withoutCost = 0;

        foreach ($items as $item) {
            if ($item->purchase_price === null) {
                $withoutCost += $item->quantity;
                continue;
            }
            // Kolom join tanpa cast model; fromSum menormalkan int/float/string.
            $cogsCents += $item->quantity * Money::fromSum($item->purchase_price);
        }

        return [
            'sales' => Money::toDecimal($omzetCents),
            'cogs' => Money::toDecimal($cogsCents),
            'gross_profit' => Money::toDecimal($omzetCents - $cogsCents),
            'items_without_cost' => $withoutCost,
        ];
    }
}
