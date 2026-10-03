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

### M4 — Purchase Order + penerimaan PO ✅ selesai 2026-10-01

Hasil: PurchaseOrderService (PO-{ymd}-{seq4}, status ordered, harga beli fallback pivot medicine_supplier, total dihitung dari items bukan disimpan), PurchaseService::receive satu transaksi (lock per po_item, over-receipt 422 terstruktur + rollback, batch merge, status ordered/partially_received/received), monthly summary dari data receipt. Policy owner & apoteker.

Pemeriksaan: `php artisan test` 57 passed / 255 assertions (2 regression batch). API test black-box 11/12 awal — PO tidak mengubah stok terbukti, rekap bulanan rekonsiliasi manual 100% akurat. Defect integritas ditemukan: guard batch hanya match (medicine_id, batch_number) sehingga nomor batch duplikat antar obat lolos → diperbaiki lewat BatchService terpusat (satu nomor batch = identitas fisik: duplikat antar obat atau expiry beda selalu 422), artefak uji dibersihkan di DB dev.

### M5 — Koreksi + stock opname ✅ selesai 2026-10-01

Hasil: migration stock_adjustments (alasan+user terstruktur, reference movement terisi), StockAdjustmentService (movement adjustment, saldo kurang 422 rollback, reason wajib min 5), StockOpnameService 3 tahap sesuai Figma (snapshot lockForUpdate semua batch berstok → input fisik bertahap dengan alasan wajib saat selisih → confirm satu transaksi menulis movement hanya untuk selisih ≠ 0, status confirmed immutable 409). Stok berubah hanya saat confirm. Policy owner & apoteker.

Pemeriksaan: `php artisan test` 69 passed / 334 assertions. API test black-box 12/12 sesuai — snapshot 15/15 batch akurat, immutability + rollback terbukti, movement opname hanya untuk selisih. Minor: reference.number StockAdjustment diisi "ADJ-{id}".

### M6 — Dashboard + laporan + ekspor ✅ selesai 2026-10-01

Hasil: DashboardService (penjualan hari ini, grafik 7 hari dengan hari kosong = 0, stok menipis/habis, kedaluwarsa pakai expiry_warning_days dari pharmacy_settings + ringkasan 30/60), ReportService 5 laporan (sales, purchases, stock, expiry, profit-loss — HPP dari purchase_price batch asal per sale_item), ReportExportService CSV (BOM, delimiter ;) + XLSX (PhpSpreadsheet v5.10, install --ignore-platform-req=ext-gd), util Money sen-integer terpusat. Keputusan: ambang expiry 30|60|90 (Figma Obat Kedaluwarsa); default periode = bulan berjalan. Bug latent diperbaiki: PharmacySetting::current() refresh.

Pemeriksaan: `php artisan test` 78 passed / 398 assertions. API test black-box 9/9 sesuai dengan rekonsiliasi manual eksak (omzet, pembelian, COGS, nilai persediaan). Catatan dokumentasi: omzet per obat basis pre-diskon, summary post-diskon — selisih = total diskon.

### M7 — Retur/pembatalan + hardening ✅ selesai 2026-10-01 (backend selesai)

Hasil: SaleVoidService (void owner-only, guard completed + lockForUpdate, reversal ke batch asal per item, movement sale_cancellation, cancelled_reason/at/by), SaleReturnService (retur parsial bertahap dengan batas returnable 422 terstruktur, movement return_in, nomor RET-{ymd}-{seq4} via migration backfill), command `stock:reconcile` (deteksi drift snapshot vs ledger, exit 1, `--fix` tanpa menulis movement). Keputusan terdokumentasi: total sale presisi 2 digit tanpa pembulatan Rp100. 2 migration (cancelled_reason, sale_returns + return_number).

Pemeriksaan: `php artisan test` 88 passed / 456 assertions. API test black-box 13/13 sesuai — rantai saldo replay konsisten, void/retur guard ketat, reconcile deteksi + fix terbukti. Perbaikan kontrak output: resource expose cancelled_* eksplisit, nomor retur konsisten.

### Frontend (dijadwalkan terpisah, bergantung klarifikasi Figma)

Fondasi token CSS + komponen bersama (sidebar, top bar, tabel, form, badge, modal, tombol) → auth + shell → Persediaan → Kasir → Pembelian → Dashboard → Laporan → Pengaturan/User → flow advanced. Dependensi yang disarankan: react-router-dom, Tailwind v4, lucide-react, @tanstack/react-table, react-hook-form + zod, @tanstack/react-query, recharts. Instalasi saat frontend mulai, dicek terhadap lockfile.

#### F1 — Fondasi frontend ✅ selesai 2026-09-30

Dependensi: react-router-dom 7, tailwindcss v4 + @tailwindcss/vite, lucide-react, @tanstack/react-query (react-hook-form/zod, react-table, recharts ditunda). Token CSS halaman 01 lengkap di @theme. Komponen ui (Button, Badge, Field/Input/Select, Modal, StatCard, Pagination) + layout (Sidebar 240px #123C35, Topbar 72px, AppShell) + auth (api client Bearer, AuthContext, route guard, LoginPage) + router + PlaceholderPage per menu. Persediaan = tabel nyata GET /api/medicines dengan chip status (Aman/Menipis/Habis), skeleton/empty/error state. Template default dihapus.

#### F2 — Kasir + Detail Obat ✅ selesai 2026-10-01

Kasir (/penjualan, Figma #18:2917/#18:3173): pencarian debounce, tabel hasil (stok 0 disabled), keranjang 470px stepper + hapus, diskon validasi ≤ subtotal, bayar/kembalian panel danger #FDEBED, submit POST /api/sales, layar Transaksi Berhasil (kartu 570px, nomor TRX, badge Tunai), error 422 stok per item tanpa dismiss keranjang. Detail Obat (/persediaan/:id, #50:1452): kepala + chip status, sorotan stok/batch terdekat expired/min, tabel Stok per Batch urut expiry (chip ≤30/≤60), catatan FEFO, Kartu Stok chip tipe ter-map + saldo + pagination. Keputusan: chip pembayaran hanya Tunai (lainnya disembunyikan), shortcut F2/F9 di-skip, tanpa dep baru. Penandaan Figma tak jelas: lokasi obat tidak ada di API (tidak digambar), nominal bayar tidak di response 201 (kembalian dihitung client).

Pemeriksaan: tsc 0, build sukses, oxlint 0/0 (28 file), route smoke 200. Sale uji via proxy berhasil (TRX-20261001-0006) + 422 stok terbukti.

#### F3 — Dashboard + Laporan ✅ selesai 2026-10-01

Dashboard (/dashboard, #18:2677): 4 StatCard (penjualan + transaksi hari ini, menipis+habis, kedaluwarsa 60 hari), bar chart 7 hari recharts (bar terakhir primer, lainnya primer-lembut — lazy chunk, recharts ~365KB), panel aksi cepat (opname disabled "Segera hadir", bukan link palsu), 2 tabel perhatian dengan klik ke detail obat. Laporan 5 tab sub-route (#45:*): periode default bulan berjalan, ringkasan + tabel nyata, ExportButton fetch-blob Bearer pakai filename Content-Disposition (CSV/XLSX). Laba/Rugi kartu rumus PENJUALAN − HPP = LABA KOTOR + badge margin + warning items_without_cost. Chart tren harian di halaman laporan tidak digambar — API tidak punya array tren (diganti tabel nyata). Dep baru: recharts.

Pemeriksaan: tsc 0, build sukses (code-split), oxlint 0/0 (38 file), smoke 5 route 200, export CSV via proxy 200.

#### F4 — Pembelian + Master Data ✅ selesai 2026-10-02

Pembelian (/pembelian, #50:823 + #42:1290): daftar PO (filter status/supplier, chip Dipesan/Sebagian Diterima/Diterima), form PO baru (modal wide 720px, item dinamis, harga beli opsional "Otomatis" via pivot server), detail PO (/pembelian/:id: items ordered/received + riwayat receipt), form penerimaan per item sisa (qty default sisa, batch, expiry ≥ hari ini, 422 over-receipt per baris). Master Data (/master-data, tab Obat|Supplier): CRUD obat + manager kategori/satuan (mutasi owner-only — tombol disembunyikan untuk apoteker, 403 terbukti), supplier list + form + panel detail. Form di luar Figma disusun dari DS 03 (prop Modal `wide` satu-satunya ekstensi). Gap dicatat: received_quantity null di index PO, pivot harga tidak terekspos.

Pemeriksaan: tsc 0, build sukses, oxlint 0/0 (45 file), smoke 3 route 200, alur nyata via proxy (PO-20261002-0001 terbuat).

#### F6 — Opname + koreksi + penerimaan manual + retur/void ✅ selesai 2026-10-02

Stock Opname wizard 2 langkah (#44:* pola shell D diadopsi, token shell A): sesi POST → Hitung (physical_qty per item, selisih live, alasan wajib saat selisih di-enforce client — server nullable) → Konfirmasi ringkasan selisih + modal DS → confirm. Riwayat opname + detail baca-saja. Koreksi stok dari Detail Obat per batch (radio Masuk/Keluar → signed int, reason ≥5). Penerimaan manual dari halaman Pembelian (item dinamis + batch/expiry, guard batch 422). Retur + riwayat penjualan: sub-nav Kasir|Riwayat, filter tanggal, detail modal dengan returned/returnable, ReturnModal, VoidModal (owner-only dari role context). Dashboard quick action opname di-enable. Infra: container backend sempat gagal start karena image tanpa ext-gd + composer install di setiap start (sejak PhpSpreadsheet M6) → Dockerfile diperbaiki (gd + libpng/jpeg) dan image di-rebuild permanen.

Pemeriksaan: tsc -b 0 (mulai F6; tsc --noEmit ternyata tak mengecek project references — 1 error type lama ikut diperbaiki), build sukses, oxlint 0/0 (50 file), smoke 3 route 200, alur nyata: OPN-20261002-0002 dibuat → adjust 1 item → confirmed.

#### F5 — Pengaturan + User & Hak Akses ✅ selesai 2026-10-02

Backend F5a: GET/PUT /api/settings (singleton, PUT owner-only), user management owner-only (list/create/update/reset-password/toggle-active), migration users.is_active, login user nonaktif → 403 + token dicabut, self-protection + last-owner protection (422). 97 test hijau.

Frontend F5b: /pengaturan 4 tab pill (Profil #45:7995 + footer form DS 03, Transaksi = 4 prefix + expiry 1-90, User & Hak Akses #45:9229 tabel + modal tambah/edit/reset-password/nonaktifkan, Matriks Akses statis informatif). Seluruh /pengaturan owner-only (kartu "Akses dibatasi" untuk apoteker). Yang tidak ada backend-nya tidak digambar: diskon maks/pembulatan/toggle kasir/metode bayar (#45:7800), logo upload/SIA-SIPA, backup (#45:7364/7582).

Pemeriksaan: backend 97 passed / 506 assertions + verifikasi curl mandiri (gating role, toggle-active, login nonaktif). Frontend tsc -b 0, build sukses, oxlint 0/0 (53 file), smoke 5 route 200, alur nyata (user uji.f5b dibuat → nonaktif).

#### M8 — Retur pembelian ✅ selesai 2026-10-02

Backend: migration purchase_returns + purchase_return_items (nomor RTN-{ymd}-{seq4}, prefix hardcoded tidak masuk settings — dicatat), PurchaseReturnService (batas returnable per receipt item 422 terstruktur + rollback, deduct type return_out ke batch asal), detail PO memuat returned/returnable per receipt item. 106 test hijau.

Frontend: modal retur satu item dari detail PO (qty default sisa, validasi client, 422 per item), sub-nav pill Daftar PO | Retur Pembelian, halaman daftar retur. tsc -b 0, oxlint 0/0 (56 file), smoke 3 route 200, alur nyata RTN-20261002-0002.

#### M9 — Impor XLSX obat + stok ✅ selesai 2026-10-02 (scope brief selesai)

Backend: GET /api/medicines/import/template (XLSX 9 kolom + contoh), POST /api/medicines/import — semantic terdokumentasi: impor = penyesuaian stok ke angka fisik di file (opname-like), upsert obat by kode, kategori/satuan match by nama (auto-create), batch find-or-create dengan guard, delta → movement adjustment reason "Impor XLSX". Validasi dua fase all-or-nothing (422 per baris, rollback total). 112 test hijau.

Frontend: modal Impor di Persediaan (unduh template blob, input file ≤2MB, ringkasan hijau / error per baris danger). tsc -b 0, oxlint 0/0 (57 file), smoke 200, alur nyata: template 200 + impor 1 obat sukses + non-xlsx 422.

#### Hardening — code review + perbaikan ✅ selesai 2026-10-03

Review independen penuh (4 reviewer paralel per domain + verifikasi langsung) menemukan 3 critical + 9 major. Diperbaikan semua dengan regression test (122 passed / 634 assertions):
- C1 opname confirm pakai saldo live (bukan snapshot basi) — tolak 422 bila stok bergerak sejak snapshot.
- C2/C3 sanitasi formula injection CSV (RFC-4180 + prefix ') dan XLSX (TYPE_STRING eksplisit).
- M1 impor XLSX owner-only; M2 login throttle 5/menit; M6 Money negatif ("-1.50"); M3/M4/M5 lock + re-check status dalam transaksi (opname/retur jual/retur beli); M7 HPP di-snapshot ke sale_items.cost_price saat jual — laba rugi historis tahan perubahan harga beli batch.
- Frontend: loop reload 401 diperbaiki (clearAuthStorage + event logout paksa, semua jalur fetch konsisten), deskripsi obat preload saat edit, format sen 2 digit, tanggal lokal (bukan UTC).
- DemoSeeder: dataset demo sidang koheren (10 obat, penjualan 7 hari, void, retur jual/beli, opname, 2 PO) — dashboard hidup, profit-loss realistis, reconcile exit 0. Cara pakai di docblock.

Pemeriksaan pasca-fix: 122 passed, reconcile OK 25 batch, profit-loss demo 758000/524350/233650, smoke 200.

## Pemeriksaan yang dijalankan

- 2026-09-30: audit repo/Git/Docker/dependency (manual, lihat "Keadaan awal").
- 2026-09-30 M1: `php artisan migrate:fresh --seed` OK (10 movement dari seeder via StockService); `migrate:rollback --step=8` + migrate ulang OK; `php artisan test` 17 passed / 43 assertions.
- 2026-09-30 M1: API test black-box 27/27 skenario sesuai kontrak (login, otorisasi role, validasi 422, pagination/search, logout, token invalid). Temuan A1–A3 diperbaiki dan diverifikasi ulang.
- 2026-09-30 M2: `php artisan test` 32 passed / 109 assertions. API test black-box 12/12 skenario sesuai (penerimaan atomik + rollback bersih, nomor RCV berurutan, kartu stok + filter, batches FEFO + expiring_within, invarian saldo hold). Dua catatan tester terbukti stale saat diverifikasi ulang langsung (locale id dan APP_DEBUG=false berlaku).
- 2026-09-30 M3: `php artisan test` 44 passed / 179 assertions (3 regression diskon + reference.number). API test black-box 11/12 lalu defect diskon (A1) diperbaiki + terverifikasi curl; FEFO, rollback total, snapshot harga, invarian batch habis terbukti.
- 2026-09-30 F1 frontend: tsc 0 error, vite build sukses, oxlint 0/0, :5173 200, proxy /api terbukti.
- 2026-10-01 F2 frontend: tsc 0, build sukses, oxlint 0/0 (28 file), smoke route 200, sale via proxy berhasil (TRX-20261001-0006).
- 2026-10-01 F3 frontend: tsc 0, build sukses, oxlint 0/0 (38 file), smoke 5 route 200, export CSV via proxy 200. Dep baru: recharts.
- 2026-10-02 F4 frontend: tsc 0, build sukses, oxlint 0/0 (45 file), smoke 3 route 200, alur nyata PO via proxy (PO-20261002-0001).
- 2026-10-02 F6 frontend: tsc -b 0, build sukses, oxlint 0/0 (50 file), smoke 3 route 200, alur nyata opname (OPN-20261002-0002 confirmed). Infra: backend/Dockerfile + gd (ext-gd untuk PhpSpreadsheet), image di-rebuild.
- 2026-10-02 F5: backend 97 passed / 506 assertions + curl mandiri (settings gating, toggle-active, login nonaktif 403); frontend tsc -b 0, oxlint 0/0 (53 file), smoke 5 route 200.
- 2026-10-02 M8: backend 106 passed / 558 assertions; frontend tsc -b 0, oxlint 0/0 (56 file), smoke 3 route 200, alur nyata RTN-20261002-0002.
- 2026-10-02 M9: backend 112 passed / 596 assertions; frontend tsc -b 0, oxlint 0/0 (57 file), smoke 200, impor nyata 1 obat sukses + non-xlsx 422.
- 2026-10-03 Hardening: code review penuh (3 critical + 9 major) semua diperbaiki + 10 regression test — 122 passed / 634 assertions; DemoSeeder data sidang; reconcile OK; smoke 200.
- 2026-10-03 Delivery docs: README, docs/SETUP.md, docs/ARCHITECTURE.md disinkronkan dengan keadaan aktual (fitur lengkap, DemoSeeder, akun demo, reconcile); sidebar sticky + tombol logout ditambahkan; tombol dummy dihapus (bell, avatar topbar, Cetak Struk).
- 2026-10-03 Sort + filter tabel: util useSort/sortRows/SortHeader; 17 tabel dapat sort (Persediaan, batch, kartu stok, riwayat penjualan, daftar/detail PO, retur beli, opname, master obat/supplier, users, 4 tabel laporan); filter kontekstual baru (status stok, tipe mutasi, status sale, role/status user). Client-side per halaman ter-load (paginasi per 15 tidak diubah — keterbatasan dicatat). tsc -b 0, oxlint 0/0 (59 file).
- 2026-10-01 M4: `php artisan test` 57 passed / 255 assertions. API test black-box 11/12 lalu defect guard batch (duplikat antar obat lolos) diperbaiki via BatchService terpusat + 2 regression; artefak data dev dibersihkan.
- 2026-10-01 M5: `php artisan test` 69 passed / 334 assertions. API test black-box 12/12 sesuai; minor reference.number adjustment diisi ADJ-{id}.
- 2026-10-01 M6: `php artisan test` 78 passed / 398 assertions. API test black-box 9/9 sesuai, rekonsiliasi manual eksak (omzet/pembelian/COGS/nilai stok). Package baru: phpoffice/phpspreadsheet v5.10.
- 2026-10-01 M7: `php artisan test` 88 passed / 456 assertions. API test black-box 13/13 sesuai (void/retur guard, reconcile deteksi+fix). 2 migration. Klaim tester berulang soal "bug diskon M3 masih terbuka" dan "APP_DEBUG bocor" keduanya stale — diskon sudah fix sejak M3 (data TRX-0007 justru bukti fix benar), APP_DEBUG sudah false sejak M1; sudah diverifikasi langsung berkali-kali.
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
