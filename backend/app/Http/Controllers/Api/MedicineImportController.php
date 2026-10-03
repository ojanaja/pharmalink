<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MedicineImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MedicineImportController extends Controller
{
    public function __construct(protected MedicineImportService $imports)
    {
    }

    /**
     * Unduh template XLSX dengan header persis sesuai kontrak impor.
     */
    public function template(): Response
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->fromArray(MedicineImportService::HEADERS, null, 'A1');

        // Satu baris contoh; tanggal kedaluwarsa contoh = satu tahun dari hari ini.
        $sheet->fromArray([[
            'OBT-001', 'Paracetamol 500mg', 'Obat Bebas', 'Strip',
            3000, 10, 100, 'BTH-001', now()->addYear()->toDateString(),
        ]], null, 'A2');

        $sheet->getStyle('A1')->getFont()->setBold(true);
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($sheet->getParent());
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="template-obat.xlsx"',
        ]);
    }

    /**
     * Impor XLSX: upsert obat by kode + penyesuaian stok fisik (lihat
     * semantik di MedicineImportService). All-or-nothing.
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:2048'],
        ]);

        $summary = $this->imports->import($validated['file'], $request->user());

        return response()->json(['data' => $summary]);
    }
}
