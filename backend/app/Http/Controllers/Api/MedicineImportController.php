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
     * Unduh template XLSX 2 sheet persis format resmi pengguna.
     */
    public function template(): Response
    {
        $spreadsheet = new Spreadsheet();

        $stock = $spreadsheet->getActiveSheet();
        $stock->setTitle('Update Stock (Menu Persediaan)');
        $stock->fromArray(['Kode', 'Nama Obat', 'Nomor Batch', 'Stock Masuk', 'Stock Keluar', 'Keterangan'], null, 'A1');
        $stock->fromArray([['OBAT-PCT', 'Paracetamol 500mg', 'BTH-PCT-001', 50, null, 'Stok awal impor']], null, 'A2');
        $stock->getStyle('A1')->getFont()->setBold(true);

        $master = $spreadsheet->createSheet();
        $master->setTitle('Master Data Obat');
        $master->fromArray(['Kode', 'Nama Obat', 'Satuan', 'Harga jual', 'Minimum stok'], null, 'A1');
        $master->fromArray([['(Auto Generate)', 'Vitamin D3 1000IU', 'Tablet', 15000, 10]], null, 'A2');
        $master->getStyle('A1')->getFont()->setBold(true);

        foreach ([$stock, $master] as $sheet) {
            foreach (range('A', 'F') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }

        $writer = new Xlsx($spreadsheet);
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
