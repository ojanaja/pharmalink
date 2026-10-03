# Arsitektur awal

## Keputusan stack

| Bagian | Teknologi | Tanggung jawab |
| --- | --- | --- |
| Frontend | React + TypeScript + Vite | UI dan interaksi browser |
| Backend | Laravel 13 API | Validasi, aturan bisnis, autentikasi, dan API |
| Autentikasi API | Laravel Sanctum | Token API untuk klien frontend |
| Database | MySQL 8.4 | Penyimpanan data relasional |
| Local environment | Docker Compose | Menjalankan service dengan konfigurasi seragam |

Laravel 13 membutuhkan PHP 8.3 atau lebih baru. Compose memakai PHP 8.5 agar cocok dengan versi Symfony yang terkunci oleh scaffold Laravel saat ini, Node 22, dan MySQL 8.4. Versi dependency frontend dikunci di `frontend/package-lock.json`; dependency PHP dikunci di `backend/composer.lock`.

## Struktur repo

```text
backend/       Laravel API
frontend/      React + TypeScript + Vite
docs/          Dokumentasi setup dan arsitektur
docker-compose.yml
Makefile
```

## Keadaan saat ini

Fondasi scaffold telah dikembangkan menjadi aplikasi lengkap per brief (rincian milestone, keputusan, dan pemeriksaan ada di `docs/IMPLEMENTATION_PLAN.md`):

- **Backend:** ~28 migration, 17 model, 12 service, 16 controller. Auth Sanctum + role `owner`/`apoteker` via Policy. Modul: master data (obat/kategori/satuan/supplier), penerimaan barang manual dan dari purchase order, penjualan FEFO dengan snapshot harga + HPP, void dan retur penjualan, retur pembelian, koreksi stok, stock opname, dashboard, 5 laporan dengan ekspor CSV/XLSX, impor XLSX, pengaturan apotek, manajemen user. 122 feature test hijau.
- **Frontend:** React + Tailwind v4 dengan token dari design system Figma (shell "Apotek Sehat Sentosa"). Router + auth guard, 7 menu lengkap: Dashboard, Penjualan (kasir + riwayat + retur/void), Persediaan (+ detail obat, koreksi, impor), Pembelian (PO + penerimaan + retur), Master Data, Laporan (5 tab + export), Pengaturan (profil, transaksi, user, matriks akses).

## Prinsip domain yang dijaga

- **Ledger stok append-only** (`stock_movements` + `balance_after`) adalah sumber kebenaran; `batches.quantity_on_hand` adalah snapshot yang ditulis atomik dalam transaksi yang sama. Satu pintu mutasi: `StockService`. Rekonsiliasi: `php artisan stock:reconcile`.
- **FEFO** untuk pemilihan batch penjualan; nomor batch adalah identitas fisik (satu nomor tidak boleh dipakai dua obat atau dua tanggal kedaluwarsa — `BatchService`).
- **Uang** DECIMAL(15,2), aritmetika sen-integer (`App\Support\Money`), harga di-snapshot di detail transaksi (harga jual dan HPP) agar historis tak berubah.
- **Setiap transaksi stok** dalam `DB::transaction` dengan `lockForUpdate` pada baris yang disentuh; nomor dokumen `{prefix}-{ymd}-{seq4}` dengan unique constraint.
- **Zona waktu** Asia/Jakarta di config aplikasi.
