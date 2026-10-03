<?php

namespace App\Services;

use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Ekspor laporan ke CSV (selalu tersedia) atau XLSX (PhpSpreadsheet).
 * CSV: BOM UTF-8 + delimiter ';' + tanggal dd/mm/yyyy (Excel Indonesia).
 */
class ReportExportService
{
    public function __construct(protected ReportService $reports)
    {
    }

    /**
     * @param  array{from?: string|null, to?: string|null, days?: int|null}  $params
     */
    public function download(string $report, string $format, array $params): Response
    {
        [$headings, $rows] = $this->table($report, $params);

        $filename = sprintf('laporan-%s-%s.%s', $report, now()->format('Ymd'), $format);

        return $format === 'xlsx'
            ? $this->xlsx($headings, $rows, $filename)
            : $this->csv($headings, $rows, $filename);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int>>}
     */
    protected function table(string $report, array $params): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $params['from'] ?? today()->startOfMonth()->toDateString());
        $to = Carbon::createFromFormat('Y-m-d', $params['to'] ?? today()->toDateString());

        return match ($report) {
            'sales' => $this->salesTable($from, $to),
            'purchases' => $this->purchasesTable($from, $to),
            'stock' => $this->stockTable(),
            'expiry' => $this->expiryTable((int) ($params['days'] ?? 30)),
            default => abort(404),
        };
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int>>}
     */
    protected function salesTable(Carbon $from, Carbon $to): array
    {
        $rows = array_map(fn ($m) => [
            $m['code'], $m['name'], $m['quantity'], $m['omzet'],
        ], $this->reports->salesPerMedicine([$from->copy()->startOfDay(), $to->copy()->endOfDay()]));

        return [['Kode Obat', 'Nama Obat', 'Jumlah Terjual', 'Omzet (Rp)'], $rows];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int>>}
     */
    protected function purchasesTable(Carbon $from, Carbon $to): array
    {
        $rows = array_map(fn ($m) => [
            $m['code'], $m['name'], $m['quantity'], $m['total'],
        ], $this->reports->purchasesPerMedicine([$from->copy()->startOfDay(), $to->copy()->endOfDay()]));

        return [['Kode Obat', 'Nama Obat', 'Jumlah Diterima', 'Total (Rp)'], $rows];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int>>}
     */
    protected function stockTable(): array
    {
        $rows = array_map(fn ($m) => [
            $m['code'], $m['name'], (string) $m['category'], $m['stock_total'],
            $m['min_stock'], ucfirst($m['status']), $m['stock_value'],
        ], $this->reports->stock()['medicines']);

        return [['Kode Obat', 'Nama Obat', 'Kategori', 'Stok', 'Min. Stok', 'Status', 'Nilai Stok (Rp)'], $rows];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int>>}
     */
    protected function expiryTable(int $days): array
    {
        $report = $this->reports->expiry($days);

        $rows = [];
        foreach ($report['expired'] as $b) {
            $rows[] = [$b['medicine']['code'], $b['medicine']['name'], $b['batch_number'],
                $this->idDate($b['expiry_date']), $b['quantity_on_hand'], $b['stock_value'], 'Kedaluwarsa'];
        }
        foreach ($report['expiring'] as $b) {
            $rows[] = [$b['medicine']['code'], $b['medicine']['name'], $b['batch_number'],
                $this->idDate($b['expiry_date']), $b['quantity_on_hand'], $b['stock_value'], 'Mendekati'];
        }

        return [['Kode Obat', 'Nama Obat', 'No. Batch', 'Tgl Kedaluwarsa', 'Stok', 'Nilai (Rp)', 'Status'], $rows];
    }

    /**
     * Tanggal dd/mm/yyyy untuk CSV/XLSX gaya Indonesia.
     */
    protected function idDate(?string $ymd): string
    {
        return $ymd === null ? '' : Carbon::createFromFormat('Y-m-d', $ymd)->format('d/m/Y');
    }

    /**
     * Satu sel CSV: cegah formula injection (sel diawali =,+,-,@ diprefix ')
     * lalu quoting RFC-4180 bila mengandung delimiter/quote/newline.
     */
    protected function csvCell(string|int $value): string
    {
        $v = (string) $value;

        if (preg_match('/^[=+\-@]/', $v)) {
            $v = "'".$v;
        }

        if (str_contains($v, ';') || str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r")) {
            $v = '"'.str_replace('"', '""', $v).'"';
        }

        return $v;
    }

    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string|int>>  $rows
     */
    protected function csv(array $headings, array $rows, string $filename): Response
    {
        $lines = [implode(';', array_map($this->csvCell(...), $headings))];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map($this->csvCell(...), $row));
        }

        return response("\u{FEFF}".implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @param  array<int, string>  $headings
     * @param  array<int, array<int, string|int>>  $rows
     */
    protected function xlsx(array $headings, array $rows, string $filename): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Semua sel ditulis eksplisit sebagai string — kolom input pengguna
        // (nama obat, batch, supplier) tidak boleh diinterpretasi sebagai
        // formula oleh Excel (mis. "=1+1" harus tetap literal).
        $writeRow = function (array $row, int $excelRow) use ($sheet) {
            $col = 1;
            foreach ($row as $value) {
                $sheet->setCellValueExplicit([$col, $excelRow], (string) $value, DataType::TYPE_STRING);
                $col++;
            }
        };

        $writeRow($headings, 1);
        foreach (array_values($rows) as $i => $row) {
            $writeRow($row, $i + 2);
        }

        $sheet->getStyle('A1')->getFont()->setBold(true);
        foreach (range('A', chr(ord('A') + count($headings) - 1)) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
