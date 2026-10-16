<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Batch;
use App\Models\Category;
use App\Models\Medicine;
use App\Models\StockAdjustment;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Impor XLSX 2 sheet mengikuti template resmi pengguna:
 *
 *   Sheet "Update Stock (Menu Persediaan)": mutasi stok per batch
 *     (Stock Masuk / Stock Keluar ber-alasan, wajib persis satu terisi).
 *   Sheet "Master Data Obat": upsert obat by Kode; kode kosong atau
 *     "(Auto Generate)" membuat kode acak OBAT-XXXXXX.
 *
 * KEPUTUSAN DOMAIN:
 * - Template tidak punya kolom kategori → obat baru selalu masuk kategori
 *   "Umum" (auto-create bila belum ada).
 * - Sheet Update Stock TIDAK membuat batch baru — batch harus sudah ada
 *   (dibuik lewat penerimaan); template tak punya tanggal kedaluwarsa.
 * - Dua fase: validasi SEMUA baris kedua sheet; ada error -> 422 dan
 *   TIDAK ADA yang diterapkan (all-or-nothing); bersih -> satu transaction.
 */
class MedicineImportService
{
    private const SHEET_STOCK_HINT = 'Update Stock';

    private const SHEET_MASTER_HINT = 'Master Data';

    private const MASTER_HEADERS = ['Kode', 'Nama Obat', 'Satuan', 'Harga jual', 'Minimum stok'];

    private const STOCK_HEADERS = ['Kode', 'Nama Obat', 'Nomor Batch', 'Stock Masuk', 'Stock Keluar', 'Keterangan'];

    public function __construct(protected StockService $stock)
    {
    }

    /**
     * @return array{medicines_created: int, medicines_updated: int, stock_rows_applied: int, movements_created: int, rows_processed: int}
     *
     * @throws HttpResponseException 422 dengan daftar error per sheet+baris.
     */
    public function import(UploadedFile $file, User $user): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());

        $masterRows = $this->sheetRows($spreadsheet, self::SHEET_MASTER_HINT);
        $stockRows = $this->sheetRows($spreadsheet, self::SHEET_STOCK_HINT);

        $errors = ['sheets' => [], 'rows' => []];

        if ($masterRows === null) {
            $errors['sheets'][] = 'Sheet "Master Data" tidak ditemukan dalam file.';
        }
        if ($stockRows === null) {
            $errors['sheets'][] = 'Sheet "Update Stock" tidak ditemukan dalam file.';
        }

        $master = [];
        $stock = [];

        if ($errors['sheets'] === []) {
            $master = $this->validateMaster($masterRows, $errors);
            $stock = $this->validateStock($stockRows, $master, $errors);
        }

        if ($errors['sheets'] !== [] || $errors['rows'] !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Impor gagal validasi. Tidak ada perubahan yang diterapkan.',
                'errors' => $errors,
            ], 422));
        }

        return DB::transaction(function () use ($master, $stock, $user) {
            $summary = [
                'medicines_created' => 0,
                'medicines_updated' => 0,
                'stock_rows_applied' => 0,
                'movements_created' => 0,
                'rows_processed' => count($master) + count($stock),
            ];

            // Master dulu agar obat baru bisa langsung dipakai sheet Update Stock.
            $medicineIds = $this->applyMaster($master, $summary);
            $this->applyStock($stock, $medicineIds, $user, $summary);

            return $summary;
        });
    }

    /**
     * Cari sheet by judul (toleran ejaan: mengandung hint, case-insensitive).
     * Null bila tidak ada; array baris (header dibuang, baris kosong dibuang).
     *
     * @return array<int, array<int, mixed>>|null
     */
    protected function sheetRows(Spreadsheet $spreadsheet, string $hint): ?array
    {
        $sheet = collect($spreadsheet->getAllSheets())
            ->first(fn ($s) => str_contains(strtolower($s->getTitle()), strtolower($hint)));

        if ($sheet === null) {
            return null;
        }

        $data = $sheet->toArray(null, true, false, false);
        array_shift($data); // header

        return array_values(array_filter($data, fn ($row) => collect($row)->contains(
            fn ($cell) => $cell !== null && trim((string) $cell) !== ''
        )));
    }

    /**
     * Fase 1a — validasi sheet Master Data Obat.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array{sheets: array<int, string>, rows: array<int, mixed>}  $errors
     * @return array<int, array{kode: string, auto: bool, nama: string, satuan: string, harga: string, min_stock: int}>
     */
    protected function validateMaster(array $rows, array &$errors): array
    {
        $result = [];
        $seenCodes = [];

        foreach ($rows as $index => $row) {
            $sheetRow = $index + 2;
            $rowErrors = [];

            $kode = $this->str($row[0] ?? null);
            $auto = $kode === '' || strcasecmp($kode, '(Auto Generate)') === 0;
            $nama = $this->str($row[1] ?? null);
            $satuan = $this->str($row[2] ?? null);
            $harga = $this->normalizeNumber($row[3] ?? null);
            $minStock = $this->normalizeNumber($row[4] ?? null);

            if (! $auto) {
                if (isset($seenCodes[$kode])) {
                    $rowErrors[] = "Kode {$kode} duplikat dalam sheet Master Data.";
                } else {
                    $seenCodes[$kode] = true;
                }
            }

            if ($nama === '') {
                $rowErrors[] = 'Nama Obat wajib diisi.';
            }

            $medicine = ! $auto && $kode !== '' ? Medicine::query()->where('code', $kode)->first() : null;

            // Satuan wajib untuk obat baru (master sheet selalu punya kolomnya).
            if ($medicine === null && $satuan === '') {
                $rowErrors[] = 'Satuan wajib diisi untuk obat baru.';
            }

            if (! is_numeric($harga) || (float) $harga < 0) {
                $rowErrors[] = 'Harga jual wajib berupa angka >= 0.';
            }

            if (! $this->isInt($minStock) || (int) $minStock < 0) {
                $rowErrors[] = 'Minimum stok harus bilangan bulat >= 0.';
            }

            if ($rowErrors !== []) {
                $errors['rows'][] = ['sheet' => 'Master Data', 'row' => $sheetRow, 'errors' => $rowErrors];
            } else {
                $result[] = [
                    'kode' => $kode,
                    'auto' => $auto,
                    'nama' => $nama,
                    'satuan' => $satuan,
                    'harga' => number_format((float) $harga, 2, '.', ''),
                    'min_stock' => $this->isInt($minStock) ? (int) $minStock : 0,
                ];
            }
        }

        return $result;
    }

    /**
     * Fase 1b — validasi sheet Update Stock. Obat boleh berasal dari DB atau
     * dari sheet Master dalam file yang sama (hanya untuk kode eksplisit).
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, array{kode: string, auto: bool}>  $master
     * @param  array{sheets: array<int, string>, rows: array<int, mixed>}  $errors
     * @return array<int, array{kode: string, batch_number: string, masuk: int, keluar: int, keterangan: string}>
     */
    protected function validateStock(array $rows, array $master, array &$errors): array
    {
        // Kode eksplisit yang dibuat/diupdate sheet Master dalam file ini.
        $masterCodes = collect($master)->where('auto', false)->pluck('kode')->all();

        $result = [];

        foreach ($rows as $index => $row) {
            $sheetRow = $index + 2;
            $rowErrors = [];

            $kode = $this->str($row[0] ?? null);
            $batchNumber = $this->str($row[2] ?? null);
            $masuk = $this->normalizeNumber($row[3] ?? null);
            $keluar = $this->normalizeNumber($row[4] ?? null);
            $keterangan = $this->str($row[5] ?? null);

            if ($kode === '') {
                $rowErrors[] = 'Kode wajib diisi.';
            }

            $medicine = $kode !== '' ? Medicine::query()->where('code', $kode)->first() : null;

            if ($kode !== '' && $medicine === null && ! in_array($kode, $masterCodes, true)) {
                $rowErrors[] = "Kode obat {$kode} tidak ditemukan.";
            }

            if ($batchNumber === '') {
                $rowErrors[] = 'Nomor Batch wajib diisi.';
            }

            $masukQty = $this->isInt($masuk) && is_numeric($masuk) ? (int) $masuk : 0;
            $keluarQty = $this->isInt($keluar) && is_numeric($keluar) ? (int) $keluar : 0;

            if ($masukQty > 0 && $keluarQty > 0) {
                $rowErrors[] = 'Isi salah satu saja: Stock Masuk atau Stock Keluar.';
            } elseif ($masukQty <= 0 && $keluarQty <= 0) {
                $rowErrors[] = 'Stock Masuk atau Stock Keluar wajib diisi (salah satu).';
            }

            if (! $this->isInt($masuk) || (int) $masuk < 0 || ! $this->isInt($keluar) || (int) $keluar < 0) {
                $rowErrors[] = 'Stock Masuk/Keluar harus bilangan bulat >= 0.';
            }

            if (mb_strlen($keterangan) < 5) {
                $rowErrors[] = 'Keterangan wajib diisi (minimal 5 karakter).';
            }

            // Batch wajib sudah ada; template tidak punya tanggal kedaluwarsa
            // sehingga batch baru tidak bisa dibuat dari sini.
            if ($medicine !== null && $batchNumber !== '') {
                $batch = Batch::query()
                    ->where('medicine_id', $medicine->id)
                    ->where('batch_number', $batchNumber)
                    ->first();

                if ($batch === null) {
                    $rowErrors[] = "Nomor batch {$batchNumber} untuk obat {$kode} tidak ditemukan.";
                } elseif ($keluarQty > 0 && $keluarQty > $batch->quantity_on_hand) {
                    $rowErrors[] = "Stok tidak cukup untuk batch {$batchNumber} (tersedia {$batch->quantity_on_hand}).";
                }
            }

            if ($rowErrors !== []) {
                $errors['rows'][] = ['sheet' => 'Update Stock', 'row' => $sheetRow, 'errors' => $rowErrors];
            } else {
                $result[] = [
                    'kode' => $kode,
                    'batch_number' => $batchNumber,
                    'masuk' => $masukQty,
                    'keluar' => $keluarQty,
                    'keterangan' => $keterangan,
                ];
            }
        }

        return $result;
    }

    /**
     * Fase 2a — upsert obat; return peta kode => medicine id.
     *
     * @param  array<int, array{kode: string, auto: bool, nama: string, satuan: string, harga: string, min_stock: int}>  $master
     * @param  array<string, int>  $summary
     * @return array<string, int>
     */
    protected function applyMaster(array $master, array &$summary): array
    {
        // Kategori default template (tidak ada kolom kategori di template resmi).
        $umumId = Category::query()->firstOrCreate(['name' => 'Umum'])->id;

        $map = [];
        $usedCodes = [];

        foreach ($master as $row) {
            $kode = $row['kode'];

            if ($row['auto']) {
                $kode = $this->generateCode($usedCodes);
            }

            $medicine = Medicine::query()->where('code', $kode)->first();

            if ($medicine === null) {
                $medicine = Medicine::create([
                    'code' => $kode,
                    'name' => $row['nama'],
                    'category_id' => $umumId,
                    'unit_id' => $this->unitId($row['satuan']),
                    'sale_price' => $row['harga'],
                    'min_stock' => $row['min_stock'],
                ]);
                $summary['medicines_created']++;
            } else {
                $medicine->name = $row['nama'];
                $medicine->sale_price = $row['harga'];
                $medicine->min_stock = $row['min_stock'];
                if ($row['satuan'] !== '') {
                    $medicine->unit_id = $this->unitId($row['satuan']);
                }
                $medicine->save();
                $summary['medicines_updated']++;
            }

            $map[$kode] = $medicine->id;
        }

        return $map;
    }

    /**
     * Fase 2b — mutasi stok per baris Update Stock.
     *
     * @param  array<int, array{kode: string, batch_number: string, masuk: int, keluar: int, keterangan: string}>  $stock
     * @param  array<string, int>  $medicineIds
     * @param  array<string, int>  $summary
     */
    protected function applyStock(array $stock, array $medicineIds, User $user, array &$summary): void
    {
        foreach ($stock as $row) {
            // Obat bisa berasal dari sheet Master file ini atau sudah ada di DB.
            $medicineId = $medicineIds[$row['kode']]
                ?? Medicine::query()->where('code', $row['kode'])->value('id');

            $batch = Batch::query()
                ->where('medicine_id', $medicineId)
                ->where('batch_number', $row['batch_number'])
                ->lockForUpdate()
                ->firstOrFail();

            $delta = $row['masuk'] > 0 ? $row['masuk'] : -$row['keluar'];
            $reason = 'Impor: '.$row['keterangan'];

            $adjustment = StockAdjustment::create([
                'medicine_id' => $batch->medicine_id,
                'batch_id' => $batch->id,
                'quantity' => $delta,
                'reason' => $reason,
                'user_id' => $user->id,
            ]);

            if ($delta > 0) {
                $this->stock->add($batch, $delta, MovementType::Adjustment, $adjustment, $reason);
            } else {
                $this->stock->deduct($batch, abs($delta), MovementType::Adjustment, $adjustment, $reason);
            }

            $summary['stock_rows_applied']++;
            $summary['movements_created']++;
        }
    }

    /**
     * Kode acak OBAT-XXXXXX; retry bila bentrok dengan DB atau kode dalam file.
     *
     * @param  array<string, true>  $usedCodes
     */
    protected function generateCode(array &$usedCodes): string
    {
        do {
            $code = 'OBAT-'.strtoupper(Str::random(6));
        } while (isset($usedCodes[$code]) || Medicine::query()->where('code', $code)->exists());

        $usedCodes[$code] = true;

        return $code;
    }

    /**
     * Match by nama case-insensitive (LIKE tanpa wildcard); buat master baru
     * bila belum ada.
     */
    protected function unitId(string $name): int
    {
        return Unit::query()->where('name', 'like', $name)->first()?->id
            ?? Unit::create(['name' => $name])->id;
    }

    protected function str(mixed $cell): string
    {
        return $cell === null ? '' : trim((string) $cell);
    }

    /**
     * Sel angka: string dengan pemisah ribuan "15.000" dinormalkan jadi "15000".
     */
    protected function normalizeNumber(mixed $cell): mixed
    {
        if (is_string($cell) && preg_match('/^\d{1,3}(\.\d{3})+$/', $cell)) {
            return str_replace('.', '', $cell);
        }

        return $cell;
    }

    protected function isInt(mixed $cell): bool
    {
        if ($cell === null || $cell === '') {
            return true; // kosong = pakai default, dianggap valid di sini
        }

        return is_numeric($cell) && (float) $cell === (float) (int) (float) $cell;
    }
}
