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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Impor XLSX obat + stok.
 *
 * SEMANTIK (keputusan M9, dokumentasi): impor adalah PENYESUAIAN stok ke
 * angka fisik di file (opname-like), bukan penambahan. Obat di-upsert by
 * Kode; bila Stok Fisik > 0, saldo batch disesuaikan (delta = fisik − saldo)
 * lewat movement type adjustment ber-reference StockAdjustment. Kategori /
 * satuan di-match by nama case-insensitive, dibuat otomatis bila belum ada;
 * kolom keduanya wajib untuk obat BARU dan boleh kosong untuk obat existing
 * (nilai lama dipertahankan). Semua baris divalidasi dulu; ada error ->
 * 422 + TIDAK ADA yang diterapkan (all-or-nothing).
 */
class MedicineImportService
{
    // Urutan kolom HARUS sama dengan template dan header file.
    public const HEADERS = [
        'Kode', 'Nama', 'Kategori', 'Satuan', 'Harga Jual',
        'Stok Minimum', 'Stok Fisik', 'Nomor Batch', 'Tanggal Kedaluwarsa',
    ];

    public function __construct(
        protected StockService $stock,
        protected BatchService $batches,
    ) {
    }

    /**
     * @return array{medicines_created: int, medicines_updated: int, batches_adjusted: int, movements_created: int, rows_processed: int}
     *
     * @throws HttpResponseException 422 dengan daftar error per baris.
     */
    public function import(UploadedFile $file, User $user): array
    {
        $rows = $this->readRows($file);

        $errors = $this->validateRows($rows);
        if ($errors !== []) {
            throw new HttpResponseException(response()->json([
                'message' => 'Impor gagal validasi. Tidak ada perubahan yang diterapkan.',
                'errors' => ['rows' => $errors],
            ], 422));
        }

        return DB::transaction(function () use ($rows, $user) {
            $summary = [
                'medicines_created' => 0,
                'medicines_updated' => 0,
                'batches_adjusted' => 0,
                'movements_created' => 0,
                'rows_processed' => count($rows),
            ];

            foreach ($rows as $row) {
                $this->applyRow($row, $user, $summary);
            }

            return $summary;
        });
    }

    /**
     * Baca sheet pertama jadi array baris; header (baris 1) dan baris kosong dibuang.
     *
     * @return array<int, array<int, mixed>> index 0 = baris data pertama (sheet row 2)
     */
    protected function readRows(UploadedFile $file): array
    {
        $sheet = IOFactory::load($file->getPathname())->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);

        array_shift($data); // header

        return array_values(array_filter($data, fn ($row) => collect($row)->contains(
            fn ($cell) => $cell !== null && trim((string) $cell) !== ''
        )));
    }

    /**
     * Fase 1 — validasi seluruh baris. Mengembalikan daftar error per baris
     * (nomor baris = nomor baris asli di sheet, header = baris 1).
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<int, array{row: int, errors: array<int, string>}>
     */
    protected function validateRows(array $rows): array
    {
        $errors = [];
        $seenCodes = [];

        foreach ($rows as $index => $row) {
            $sheetRow = $index + 2; // header di baris 1
            $rowErrors = [];

            $kode = $this->str($row[0] ?? null);
            $nama = $this->str($row[1] ?? null);
            $kategori = $this->str($row[2] ?? null);
            $satuan = $this->str($row[3] ?? null);
            $harga = $row[4] ?? null;
            $minStock = $row[5] ?? null;
            $stokFisik = $row[6] ?? null;
            $batchNumber = $this->str($row[7] ?? null);
            $expiry = $this->str($row[8] ?? null);

            if ($kode === '') {
                $rowErrors[] = 'Kode wajib diisi.';
            } elseif (isset($seenCodes[$kode])) {
                $rowErrors[] = "Kode {$kode} duplikat dalam file.";
            } else {
                $seenCodes[$kode] = true;
            }

            if ($nama === '') {
                $rowErrors[] = 'Nama wajib diisi.';
            }

            $medicine = $kode !== '' ? Medicine::query()->where('code', $kode)->first() : null;

            // Kategori/satuan: wajib untuk obat baru, opsional untuk existing.
            if ($medicine === null && ($kategori === '' || $satuan === '')) {
                $rowErrors[] = 'Kategori dan Satuan wajib diisi untuk obat baru.';
            }

            if (! is_numeric($harga) || (float) $harga < 0) {
                $rowErrors[] = 'Harga Jual wajib berupa angka >= 0.';
            }

            if (! $this->isInt($minStock) || (int) $minStock < 0) {
                $rowErrors[] = 'Stok Minimum harus bilangan bulat >= 0.';
            }

            if (! $this->isInt($stokFisik) || (int) $stokFisik < 0) {
                $rowErrors[] = 'Stok Fisik harus bilangan bulat >= 0.';
            }

            $fisik = $this->isInt($stokFisik) ? (int) $stokFisik : 0;

            if ($fisik > 0) {
                if ($batchNumber === '') {
                    $rowErrors[] = 'Nomor Batch wajib diisi bila Stok Fisik > 0.';
                }

                if ($expiry === '' || ! Carbon::hasFormat($expiry, 'Y-m-d')) {
                    $rowErrors[] = 'Tanggal Kedaluwarsa wajib format YYYY-MM-DD bila Stok Fisik > 0.';
                } elseif (Carbon::createFromFormat('Y-m-d', $expiry)->isBefore(today())) {
                    $rowErrors[] = 'Tanggal Kedaluwarsa tidak boleh tanggal lampau.';
                }

                // Guard identitas batch (sama dengan penerimaan): nomor batch tidak
                // boleh dipakai obat lain atau punya expiry berbeda.
                if ($batchNumber !== '' && $expiry !== '' && Carbon::hasFormat($expiry, 'Y-m-d')) {
                    $existing = Batch::query()->where('batch_number', $batchNumber)->first();

                    if ($existing !== null) {
                        if ($medicine === null || $existing->medicine_id !== $medicine->id) {
                            $rowErrors[] = "Nomor batch {$batchNumber} sudah dipakai obat lain.";
                        } elseif ($existing->expiry_date?->toDateString() !== $expiry) {
                            $rowErrors[] = "Batch {$batchNumber} sudah terdaftar dengan tanggal kedaluwarsa {$existing->expiry_date?->toDateString()}.";
                        }
                    }
                }
            }

            if ($rowErrors !== []) {
                $errors[] = ['row' => $sheetRow, 'errors' => $rowErrors];
            }
        }

        return $errors;
    }

    /**
     * Fase 2 — terapkan satu baris (dipanggil hanya bila validasi bersih).
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $summary
     */
    protected function applyRow(array $row, User $user, array &$summary): void
    {
        $kode = $this->str($row[0]);
        $nama = $this->str($row[1]);
        $kategori = $this->str($row[2]);
        $satuan = $this->str($row[3]);
        $harga = number_format((float) $row[4], 2, '.', '');
        $minStock = $this->isInt($row[5] ?? null) ? (int) $row[5] : 0;
        $fisik = $this->isInt($row[6] ?? null) ? (int) $row[6] : 0;

        $medicine = Medicine::query()->where('code', $kode)->first();

        if ($medicine === null) {
            $medicine = Medicine::create([
                'code' => $kode,
                'name' => $nama,
                'category_id' => $this->categoryId($kategori),
                'unit_id' => $this->unitId($satuan),
                'sale_price' => $harga,
                'min_stock' => $minStock,
            ]);
            $summary['medicines_created']++;
        } else {
            $medicine->name = $nama;
            $medicine->sale_price = $harga;
            $medicine->min_stock = $minStock;
            if ($kategori !== '') {
                $medicine->category_id = $this->categoryId($kategori);
            }
            if ($satuan !== '') {
                $medicine->unit_id = $this->unitId($satuan);
            }
            $medicine->save();
            $summary['medicines_updated']++;
        }

        if ($fisik <= 0) {
            return;
        }

        $batch = $this->batches->findOrCreateForReceipt(
            $medicine->id,
            $this->str($row[7]),
            $this->str($row[8]),
        );

        // Kunci batch saat baca saldo agar dua impor paralel tidak saling menimpa delta.
        $batch = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

        $delta = $fisik - $batch->quantity_on_hand;

        if ($delta === 0) {
            return;
        }

        $adjustment = StockAdjustment::create([
            'medicine_id' => $medicine->id,
            'batch_id' => $batch->id,
            'quantity' => $delta,
            'reason' => 'Impor XLSX',
            'user_id' => $user->id,
        ]);

        $type = MovementType::Adjustment;
        if ($delta > 0) {
            $this->stock->add($batch, $delta, $type, $adjustment, 'Impor XLSX');
        } else {
            $this->stock->deduct($batch, abs($delta), $type, $adjustment, 'Impor XLSX');
        }

        $summary['batches_adjusted']++;
        $summary['movements_created']++;
    }

    /**
     * Match by nama case-insensitive (LIKE tanpa wildcard); buat master baru
     * bila belum ada.
     */
    protected function categoryId(string $name): int
    {
        return Category::query()->where('name', 'like', $name)->first()?->id
            ?? Category::create(['name' => $name])->id;
    }

    protected function unitId(string $name): int
    {
        return Unit::query()->where('name', 'like', $name)->first()?->id
            ?? Unit::create(['name' => $name])->id;
    }

    protected function str(mixed $cell): string
    {
        return $cell === null ? '' : trim((string) $cell);
    }

    protected function isInt(mixed $cell): bool
    {
        if ($cell === null || $cell === '') {
            return true; // kosong = pakai default, dianggap valid di sini
        }

        return is_numeric($cell) && (float) $cell === (float) (int) (float) $cell;
    }
}
