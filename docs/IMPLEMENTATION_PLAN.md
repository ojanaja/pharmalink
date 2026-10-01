# Rencana implementasi Pharmalink

Dokumen hidup. Pemilik: lead. Diperbarui tiap milestone selesai atau keputusan berubah. Ikuti `AGENTS.md` dan `docs/DOCUMENTATION_STANDARD.md` — catat hanya keputusan, asumsi, dan pemeriksaan yang benar-benar dijalankan.

## Keadaan awal (audit 2026-09-30)

- Repo: scaffold kosong. Backend Laravel 13 + Sanctum (hanya `User.php`, `routes/api.php` berisi `/user`). Frontend React 19 + Vite 8 masih template default. Commit terakhir: `51d976b chore: scaffold Pharmalink project`.
- Docker: Compose tersedia (`backend` build PHP 8.5, `frontend` node:22, `mysql` 8.4, volume `mysql_data`). Stack belum berjalan saat audit; dinyalakan saat M1.
- Figma: dapat diakses via REST (token MCP diperbarui 2026-09-30). File berisi 4 varian shell desain; halaman design system 01–06 lengkap (token, layout, forms, data display, feedback, states).
- Dependensi: PHP/Node dikunci `composer.lock` / `package-lock.json`. Frontend belum punya router, styling, maupun library komponen.

## Keputusan arsitektur (diputuskan lead 2026-09-30)

| Topik | Keputusan | Alasan |
| --- | --- | --- |
| Uang | `DECIMAL(15,2)` di semua kolom harga/nilai; cast `decimal:2`; tidak ada float | Brief §79; presisi Rupiah |
| Mutasi stok | `stock_movements` append-only (sumber kebenaran) + `batches.quantity_on_hand` snapshot + `balance_after` per movement | Audit sidang + query cepat dashboard; snapshot dan movement ditulis atomik dalam satu DB transaction |
| Pemilihan batch penjualan | FEFO (kedaluwarsa terdekat dulu; batch expired dikeluarkan), pecah antar batch bila perlu, batch berikutnya saat stok kurang, gagal total = rollback | Standar farmasi; keputusan terdokumentasi brief §84 |
| Zona waktu | `APP_TIMEZONE=Asia/Jakarta`; timestamp disimpan apa adanya | "Hari ini" transaksi = hari kalender lokal; paling sederhana untuk tim kecil |
| Nomor transaksi | `{prefix}-{ymd}-{seq4}` unik per hari (mis. `TRX-20260930-0001`); prefix konfigurabel di `pharmacy_settings` | Human-readable; unique constraint; gap nomor saat rollback diterima |
| Role | Enum `owner`/`apoteker` di `users.role`; otorisasi via Laravel Policy + Form Request | Brief: role awal dua; Spatie overkill. Layar Figma menampilkan 4 role — brief menang (lihat pertanyaan terbuka) |
| Penerimaan tanpa PO | Diizinkan; `purchase_receipts.po_id` nullable | M2 (penerimaan manual) tidak tergantung M4 (PO) |
| Harga transaksi | Snapshot `unit_price` di `sale_items`, `unit_cost` di `purchase_order_items`/`purchase_receipt_items`, `purchase_price` di `batches` | Perubahan harga master tidak mengubah histori (brief) |
| Multi-cabang | Tidak; `pharmacy_settings` singleton satu baris | Brief §88 |
| Pembayaran | Tunai + metode lain dicatat di `sales.payment_method` (varchar); bukan validasi kas ketat di M1–M3 | Lihat pertanyaan terbuka #1 |
| Agregasi total | `sales.total` disimpan di header (snapshot & kecepatan); total PO dihitung dari items (hindari drift) | Konsistensi laporan vs sederhana |

### Aturan otorisasi awal

- **owner**: semua — master data, users, transaksi, koreksi/opname, pembatalan, pengaturan, laporan.
- **apoteker**: transaksi penjualan, PO, penerimaan, opname, lihat master/laporan. Tanpa: kelola users, void transaksi, pengaturan apotek.
- Dijaga di Policy per model, bukan cek role tersebar di controller.

## Struktur backend

```
app/Enums/            Role, MovementType, SaleStatus, PurchaseOrderStatus, OpnameStatus
app/Models/           PharmacySetting, Category, Unit, Medicine, Supplier,
                      Batch, StockMovement, Sale, SaleItem,
                      PurchaseOrder, PurchaseOrderItem, PurchaseReceipt, PurchaseReceiptItem,
                      StockOpname, StockOpnameItem (+ User bawaan + role)
app/Services/         StockService   ← satu-satunya pintu ubah stok
app/Http/Controllers/Api/   tipis; validasi via Form Request; return Resource
app/Http/Requests/, app/Http/Resources/, app/Policies/
```

Prinsip: controller tidak pernah menyentuh `batches.quantity_on_hand` langsung; semua lewat `StockService` di dalam `DB::transaction` dengan `lockForUpdate` pada baris batch.

## Milestone

### M1 — Fondasi domain + auth ✅ selesai 2026-09-30

Hasil: migration 8 file reversible (rollback per langkah teruji), 5 enum, 14 model, StockService (lockForUpdate, snapshot + movement + balance_after atomik, saldo negatif ditolak), auth Sanctum login/logout, policy + middleware role, CRUD medicine/category/unit/supplier, seeder, 17 feature test hijau.

Penyimpangan dicatat: `redirectGuestsTo(null)` di bootstrap/app.php (API murni tanpa route login), override `TestCase::call()` (cache RequestGuard antar-request dalam satu proses test), CHECK constraints hanya di MySQL (raw, driver-conditional).

Anomali API test yang diperbaiki: APP_DEBUG=false di .env lokal (stack trace tidak bocor), locale id + lang/id/validation.php (pesan 422 Bahasa Indonesia), konsistensi resource store medicine (stock_total + is_active).

Migration skema domain penuh, enum, model + relasi, `StockService` primitif, login Sanctum, policy dasar, seeder, feature test.

Acceptance:
- `POST /api/login` (email+password) mengembalikan token + role; logout mencabut token; `GET /api/user` via Sanctum.
- Migration reversible (`down()` lengkap), FK + CHECK (`quantity <> 0`, `quantity_on_hand >= 0`) + UNIQUE nomor transaksi/batch.
- Seeder: 1 apotek, 1 owner, 1 apoteker, kategori+satuan, 5 obat, 2 batch/obat, 1 supplier.
- `php artisan test` hijau di container Docker.
- `StockService` menolak saldo negatif dan menulis movement + snapshot atomik (teruji).

### M2 — Mutasi stok + master data (API) ✅ selesai 2026-09-30

Hasil: PurchaseReceiptService (satu transaksi: receipt + items + batch find-or-create + movement, batch existing dengan expiry beda ditolak 422), NumberGenerator `{prefix}-{ymd}-{seq4}` reusable, kartu stok per obat + daftar mutasi global (filter type/tanggal/batch, paginasi, reference.number ter-resolve), endpoint batches (stok > 0, urut FEFO, expiring_within 30|60). Policy: owner & apoteker boleh penerimaan.

Penyimpangan kecil: purchase_price batch di-update oleh receipt terbaru ke batch existing (historis aman di movement + receipt item); expiring_within dibatasi 30|60 per brief.

Pemeriksaan: `php artisan test` 32 passed / 109 assertions. API test black-box 12/12 skenario sesuai, invarian saldo (Σ movement = balance_after = quantity_on_hand) hold. Dua catatan tester (pesan M1 mentah, stack trace bocor) terbukti stale saat diverifikasi ulang langsung. Data uji receipt/batch tersisa di DB dev (tidak ada endpoint hapus — by design, penghapusan fisik diluar kontrak).

### M3 — Penjualan kasir (FEFO) ✅ selesai 2026-09-30

Hasil: SaleService satu transaksi atomik — harga server-side (client tidak kirim harga), alokasi FEFO lockForUpdate (stok > 0, belum kedaluwarsa, expiry asc), pecah antar batch, stok kurang → 422 terstruktur {medicine_id, name, requested, available} + rollback total, snapshot unit_price, nomor TRX-{ymd}-{seq4}, diskon ≤ subtotal, riwayat + detail sale. Policy owner & apoteker.

Pemeriksaan: `php artisan test` 44 passed / 179 assertions (termasuk 3 regression). API test black-box 11/12 awal — FEFO terbukti dengan urutan batch benar, rollback total terbukti, snapshot harga tahan perubahan master. Defect billing ditemukan (diskon integer ter-skala ÷100 karena parser sen mengasumsikan string desimal) → diperbaiki lewat normalisasi uang (integer = Rupiah penuh) + regression test; reference.number movement sale kini terisi invoice_number. Catatan: TRX uji probe diskon lama (nilai diskon 10.00/0.50 yang salah) tersisa di DB dev — data historis, tidak dikoreksi manual (demonstrasi ketertelusuran).

### M4 — Purchase Order + penerimaan PO

PO tidak mengubah stok; konfirmasi penerimaan (parsial boleh) menambah batch/stok + movement + status PO.

### M5 — Koreksi + stock opname

Koreksi ber-alasan wajib; opname: snapshot sistem → input fisik → preview selisih → konfirmasi baru tulis movement.

### M6 — Dashboard + laporan + ekspor

Widget dashboard per brief (angka query nyata), 5 laporan per periode, ekspor CSV/XLSX.

### M7 — Retur/pembatalan + hardening

Void = status + movement reversal (tanpa hapus baris); retur ke batch asal; pembulatan Rupiah; uji konkurensi; dokumentasi sidang.

### Frontend (dijadwalkan terpisah, bergantung klarifikasi Figma)

Fondasi token CSS + komponen bersama (sidebar, top bar, tabel, form, badge, modal, tombol) → auth + shell → Persediaan → Kasir → Pembelian → Dashboard → Laporan → Pengaturan/User → flow advanced. Dependensi yang disarankan: react-router-dom, Tailwind v4, lucide-react, @tanstack/react-table, react-hook-form + zod, @tanstack/react-query, recharts. Instalasi saat frontend mulai, dicek terhadap lockfile.

#### F1 — Fondasi frontend ✅ selesai 2026-09-30

Dependensi: react-router-dom 7, tailwindcss v4 + @tailwindcss/vite, lucide-react, @tanstack/react-query (react-hook-form/zod, react-table, recharts ditunda). Token CSS halaman 01 lengkap di @theme. Komponen ui (Button, Badge, Field/Input/Select, Modal, StatCard, Pagination) + layout (Sidebar 240px #123C35, Topbar 72px, AppShell) + auth (api client Bearer, AuthContext, route guard, LoginPage) + router + PlaceholderPage per menu. Persediaan = tabel nyata GET /api/medicines dengan chip status (Aman/Menipis/Habis), skeleton/empty/error state. Template default dihapus.

Pemeriksaan: `tsc --noEmit` 0, `npm run build` sukses, oxlint 0/0, curl :5173 200, proxy /api → backend terbukti.

## Pemeriksaan yang dijalankan

- 2026-09-30: audit repo/Git/Docker/dependency (manual, lihat "Keadaan awal").
- 2026-09-30 M1: `php artisan migrate:fresh --seed` OK (10 movement dari seeder via StockService); `migrate:rollback --step=8` + migrate ulang OK; `php artisan test` 17 passed / 43 assertions.
- 2026-09-30 M1: API test black-box 27/27 skenario sesuai kontrak (login, otorisasi role, validasi 422, pagination/search, logout, token invalid). Temuan A1–A3 diperbaiki dan diverifikasi ulang.
- 2026-09-30 M2: `php artisan test` 32 passed / 109 assertions. API test black-box 12/12 skenario sesuai (penerimaan atomik + rollback bersih, nomor RCV berurutan, kartu stok + filter, batches FEFO + expiring_within, invarian saldo hold). Dua catatan tester terbukti stale saat diverifikasi ulang langsung (locale id dan APP_DEBUG=false berlaku).
- 2026-09-30 M3: `php artisan test` 44 passed / 179 assertions (3 regression diskon + reference.number). API test black-box 11/12 lalu defect diskon (A1) diperbaiki + terverifikasi curl; FEFO, rollback total, snapshot harga, invarian batch habis terbukti.
- 2026-09-30 F1 frontend: tsc 0 error, vite build sukses, oxlint 0/0, :5173 200, proxy /api terbukti.
- Catatan proses: code review independen backend (agent terblokir TCC Documents saat M1) belum pernah berjalan penuh; verifikasi selama ini lewat test suite + API test black-box + verifikasi langsung lead.
- Belum: code review independen (agent reviewer terblokir izin macOS Documents; penggantinya review temuan API test + test suite). Akses kategori/satuan hanya GET/POST (A4) dan perbedaan envelope list (A5) dicatat untuk konsumsi frontend.

## Pertanyaan terbuka — DITUTUP 2026-09-30 (keputusan user: ikut asumsi default)

1. Shell kanonik: **A "Apotek Sehat Sentosa"** (sidebar `#123C35`, menu sesuai brief).
2. Form CRUD master obat: tidak ada di Figma → disusun dari komponen design system halaman 03 (Forms).
3. Duplikat layar: versi shell A (#50:*, Pembelian #50:823, Detail Obat #50:1452, Persediaan #50:56) yang kanonik.
4. Palet: halaman 01 foundations sumber kebenaran (`#087F6A` primer, teal di layar lain abaikan).
5. Pembayaran: tunai dulu; `payment_method` varchar tetap ada, multi-metode menyusul bila perlu.
6. **4 role di layar User & Hak Akses** vs 2 role di brief — konfirmasi scope role.

## Risiko

- Konsistensi stok konkuren → `lockForUpdate` + satu `StockService` + unique constraint.
- Drift snapshot vs ledger → command rekonsiliasi (M7) + primitif tunggal.
- Ekspor/impor XLSX (PhpSpreadsheet) → validasi keras + template terkunci di M6.
- Scope creep (resep/pasien/dokter) → brief mengecualikan; keputusan eksplisit diperlukan.
