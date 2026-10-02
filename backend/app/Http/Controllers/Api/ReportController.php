<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpiryReportRequest;
use App\Http\Requests\ReportExportRequest;
use App\Http\Requests\ReportPeriodRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\ReportExportService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reports,
        protected ReportExportService $exports,
    ) {
    }

    public function sales(ReportPeriodRequest $request): JsonResponse
    {
        $report = $this->reports->sales($request->fromDate(), $request->toDate());

        $transactions = SaleResource::collection(
            Sale::query()
                ->with('user:id,name')
                ->withCount('items')
                ->where('status', \App\Enums\SaleStatus::Completed)
                ->whereBetween('sold_at', [$request->fromDate()->startOfDay(), $request->toDate()->endOfDay()])
                ->orderByDesc('sold_at')
                ->orderByDesc('id')
                ->paginate($request->integer('per_page', 15))
                ->appends($request->query())
        )->response()->getData(true);

        return response()->json([
            'data' => array_merge($report, ['transactions' => $transactions]),
        ]);
    }

    public function purchases(ReportPeriodRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->purchases($request->fromDate(), $request->toDate()),
        ]);
    }

    public function stock(): JsonResponse
    {
        return response()->json(['data' => $this->reports->stock()]);
    }

    public function expiry(ExpiryReportRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->expiry((int) $request->input('days', 30)),
        ]);
    }

    public function profitLoss(ReportPeriodRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->profitLoss($request->fromDate(), $request->toDate()),
        ]);
    }

    /**
     * Unduh laporan (CSV selalu tersedia; XLSX butuh PhpSpreadsheet terinstall).
     */
    public function export(ReportExportRequest $request, string $report): Response
    {
        $format = $request->string('format')->toString();

        if ($format === 'xlsx' && ! class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            throw ValidationException::withMessages([
                'format' => ['Ekspor XLSX belum tersedia di server.'],
            ]);
        }

        return $this->exports->download($report, $format, $request->validated());
    }
}
