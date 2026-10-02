<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Batch;
use App\Models\Medicine;
use App\Models\PharmacySetting;
use App\Models\Sale;
use App\Support\Money;

/**
 * Widget dashboard per brief: penjualan hari ini, grafik 7 hari,
 * stok menipis/habis, dan batch mendekati kedaluwarsa.
 */
class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $today = today();
        $dayStart = $today->copy()->startOfDay();
        $dayEnd = $today->copy()->endOfDay();

        $todaySales = Sale::query()
            ->where('status', SaleStatus::Completed)
            ->whereBetween('sold_at', [$dayStart, $dayEnd]);

        $todayTotalCents = Money::fromSum((clone $todaySales)->sum('total'));
        $todayCount = (clone $todaySales)->count();

        return [
            'sales_today' => [
                'total' => Money::toDecimal($todayTotalCents),
                'transactions' => $todayCount,
            ],
            'sales_chart' => $this->salesChart($today),
            'low_stock' => $this->stockAlerts($today)['low'],
            'out_of_stock' => $this->stockAlerts($today)['out'],
            'expiry' => $this->expiry($today),
        ];
    }

    /**
     * Grafik 7 hari terakhir termasuk hari tanpa penjualan (nilai 0).
     *
     * @return array<int, array{date: string, total: string}>
     */
    protected function salesChart(\Carbon\Carbon $today): array
    {
        $from = $today->copy()->subDays(6)->startOfDay();

        // DATE() aman dipakai karena timestamp disimpan dalam timezone aplikasi.
        $rows = Sale::query()
            ->where('status', SaleStatus::Completed)
            ->where('sold_at', '>=', $from)
            ->selectRaw('DATE(sold_at) as day, SUM(total) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $chart = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i)->toDateString();
            $chart[] = [
                'date' => $date,
                'total' => Money::toDecimal(Money::fromSum($rows[$date] ?? null)),
            ];
        }

        return $chart;
    }

    /**
     * @return array{low: array<int, mixed>, out: array<int, mixed>}
     */
    protected function stockAlerts(\Carbon\Carbon $today): array
    {
        $medicines = Medicine::query()
            ->where('is_active', true)
            ->withSum('batches as stock_total', 'quantity_on_hand')
            ->get(['id', 'code', 'name', 'min_stock'])
            ->map(function (Medicine $m) {
                $m->stock_total = (int) ($m->stock_total ?? 0);

                return $m;
            });

        $low = $medicines
            ->filter(fn (Medicine $m) => $m->stock_total > 0 && $m->stock_total <= $m->min_stock)
            ->sortBy('stock_total')
            ->take(10)
            ->values()
            ->map(fn (Medicine $m) => [
                'id' => $m->id, 'code' => $m->code, 'name' => $m->name,
                'stock_total' => $m->stock_total, 'min_stock' => $m->min_stock,
            ])
            ->all();

        $out = $medicines
            ->filter(fn (Medicine $m) => $m->stock_total === 0)
            ->take(10)
            ->values()
            ->map(fn (Medicine $m) => [
                'id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'min_stock' => $m->min_stock,
            ])
            ->all();

        return ['low' => $low, 'out' => $out];
    }

    /**
     * @return array<string, mixed>
     */
    protected function expiry(\Carbon\Carbon $today): array
    {
        $warningDays = (int) PharmacySetting::current()->expiry_warning_days;

        $batches = Batch::query()
            ->with('medicine:id,code,name')
            ->where('quantity_on_hand', '>', 0)
            ->whereDate('expiry_date', '>=', $today->toDateString())
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($warningDays)->toDateString())
            ->orderBy('expiry_date')
            ->limit(10)
            ->get();

        return [
            'warning_days' => $warningDays,
            'items' => $batches->map(fn (Batch $b) => [
                'medicine' => ['id' => $b->medicine->id, 'code' => $b->medicine->code, 'name' => $b->medicine->name],
                'batch_number' => $b->batch_number,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'quantity_on_hand' => $b->quantity_on_hand,
            ])->all(),
            'summary' => [
                'within_30_days' => $this->expiryCount($today, 30),
                'within_60_days' => $this->expiryCount($today, 60),
            ],
        ];
    }

    protected function expiryCount(\Carbon\Carbon $today, int $days): int
    {
        return Batch::query()
            ->where('quantity_on_hand', '>', 0)
            ->whereDate('expiry_date', '>=', $today->toDateString())
            ->whereDate('expiry_date', '<=', $today->copy()->addDays($days)->toDateString())
            ->count();
    }
}
