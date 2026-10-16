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
 * Struktur umum: [baris judul opsional] + header + baris data;
 * laporan penjualan/pembelian punya section kedua (daftar transaksi).
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
        [$title, $headings, $rows] = $this->table($report, $params);

        $filename = sprintf('laporan-%s-%s.%s', $report, now()->format('Ymd'), $format);

        return $format === 'xlsx'
            ? $this->xlsx($title, $headings, $rows, $filename)
            : $this->csv($title, $headings, $rows, $filename);
    }

    /**
     * @return array{0: ?string, 1: array<int, string>, 2: array<int, array<int, string|int>>}
     */
    protected function table(string $report, array $params): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $params['from'] ?? today()->startOfMonth()->toDateString());
        $to = Carbon::createFromFormat('Y-m-d', $params['to'] ?? today()->toDateString());
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];

        return match ($report) {
            'sales' => $this->salesTable($range),
            'purchases' => $this->purchasesTable($range),
            'stock' => $this->stockTable(),
            'expiry' => $this->expiryTable((int) ($params['days'] ?? 30)),
            default => abort(404),
        };
    }

    /**
     * @param  array<int, \Carbon\Carbon|string>  $range
     * @return array{0: ?string, 1: array<int, string>, 2: array<int, array<int, string|int>>}
     */
    protected function salesTable(array $range): array
    {
        $rows = array_map(fn ($m) => [
            $m['code'], $m['name'], $m['quantity'], $m['omzet'],
        ], $this->reports->salesPerMedicine($range));

        // Section 2: daftar transaksi periode (tanggal transaksi tidak masuk akal
        // di agregat per obat, jadi dipisah — keputusan review).
        $rows[] = [];
        $rows[] = ['DAFTAR TRANSAKSI'];
        $rows[] = ['Nomor Invoice', 'Tanggal', 'Kasir', 'Jumlah Item', 'Total (Rp)'];

        foreach ($this->reports->salesTransactions($range) as $t) {
            $rows[] = [
                $t['invoice_number'], $this->idDate($t['sold_at']?->toDateString()),
                $t['cashier'], $t['items_count'], $t['total'],
            ];
        }

        return [null, ['Kode Obat', 'Nama Obat', 'Jumlah Terjual', 'Omzet (Rp)'], $rows];
    }

    /**
     * @return array{0: ?string, 1: array<int, string>, 2: array<int, array<int, string|int>>}
     */
    protected function purchasesTable(array $range): array
    {
        $rows = array_map(fn ($m) => [
            $m['code'], $m['name'], $m['quantity'], $m['total'],
        ], $this->reports->purchasesPerMedicine($range));

        $rows[] = [];
        $rows[] = ['DAFTAR PENERIMAAN'];
        $rows[] = ['Nomor Receipt', 'Tanggal Penerimaan', 'Nomor PO', 'Supplier', 'Jumlah Item', 'Total (Rp)'];

        foreach ($this->reports->purchaseReceiptsList($range) as $r) {
            $rows[] = [
                $r['receipt_number'], $this->idDate($r['received_at']?->toDateString()),
                $r['po_number'] ?? '-', $r['supplier'], $r['items_count'],
                Money::toDecimal($r['total_cents']),
            ];
        }

        return [null, ['Kode Obat', 'Nama Obat', 'Jumlah Diterima', 'Total (Rp)'], $rows];
    }

    /**
     * Laporan stok PER BATCH (bukan per obat) + baris TOTAL.
     *
     * @return array{0: string, 1: array<int, string>, 2: array<int, array<int, string|int>>}
     */
    protected function stockTable(): array
    {
        $title = 'Laporan Stok — per tanggal '.$this->idDate(today()->toDateString());

        $rows = [];
        $totalCents = 0;

        foreach ($this->reports->stockBatches() as $b) {
            $totalCents += $b['value_cents'];
            $rows[] = [
                $b['code'], $b['name'], (string) ($b['category'] ?? ''), (string) ($b['unit'] ?? ''),
                $b['batch_number'], $this->idDate($b['expiry_date']),
                $b['quantity'], $b['purchase_price'] ?? '0.00', Money::toDecimal($b['value_cents']),
            ];
        }

        $rows[] = ['', '', '', '', '', '', '', 'TOTAL', Money::toDecimal($totalCents)];

        return [
            $title,
            ['Kode Obat', 'Nama Obat', 'Kategori', 'Satuan', 'Nomor Batch', 'Tanggal Kedaluwarsa', 'Stok Batch', 'Harga Beli', 'Nilai Batch'],
            $rows,
        ];
    }

    /**
     * @return array{0: ?string, 1: array<int, string>, 2: array<int, array<int, string|int>>}
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

        return [null, ['Kode Obat', 'Nama Obat', 'No. Batch', 'Tgl Kedaluwarsa', 'Stok', 'Nilai (Rp)', 'Status'], $rows];
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
    protected function csv(?string $title, array $headings, array $rows, string $filename): Response
    {
        $lines = [];

        if ($title !== null) {
            $lines[] = $this->csvCell($title);
        }

        $lines[] = implode(';', array_map($this->csvCell(...), $headings));

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
    protected function xlsx(?string $title, array $headings, array $rows, string $filename): Response
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

        $excelRow = 1;

        if ($title !== null) {
            $writeRow([$title], $excelRow);
            $sheet->getStyle([1, $excelRow, 1, $excelRow])->getFont()->setBold(true);
            $excelRow++;
        }

        $writeRow($headings, $excelRow);
        $sheet->getStyle([1, $excelRow, count($headings), $excelRow])->getFont()->setBold(true);
        $excelRow++;

        foreach (array_values($rows) as $row) {
            $writeRow($row, $excelRow);
            $excelRow++;
        }

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
