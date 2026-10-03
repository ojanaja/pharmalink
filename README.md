# Pharmalink

Sistem manajemen apotek mikro/kecil untuk tugas akhir: pencatatan stok berbasis batch (FEFO) dengan tanggal kedaluwarsa, transaksi penjualan/kasir, purchase order dan penerimaan, koreksi + stock opname, retur, laporan dengan ekspor/impor XLSX, serta manajemen pengguna role owner/apoteker.

Frontend React + TypeScript + Vite (port 5173), backend Laravel 13 API + Sanctum (port 8000), MySQL 8.4 via Docker Compose. Semua mutasi stok melewati satu `StockService` dengan ledger append-only (`stock_movements` + `balance_after`), sehingga setiap perubahan teraudit dan dapat direkonsiliasi (`php artisan stock:reconcile`).

## Jalankan

Butuh Docker Desktop dan Docker Compose. Port yang dipakai: 3306 (MySQL), 5173 (frontend), 8000 (backend).

```bash
make up
```

Migrasi dan seed dasar berjalan otomatis saat backend start. Buka http://localhost:5173.

### Data demo sidang

```bash
docker compose exec backend php artisan migrate:fresh --seed --class=DemoSeeder
```

Memuat skenario koheren: 10 obat dengan batch kedaluwarsa bertingkat, penjualan 7 hari terakhir (dashboard hidup), transaksi void/retur, stock opname, dan purchase order. Lihat docblock `backend/database/seeders/DemoSeeder.php` untuk detail dan alur demo.

Akun (password semua: `password`):

| Role | Email |
| --- | --- |
| Owner | `owner@pharmalink.test` |
| Apoteker | `apoteker@pharmalink.test` |

Catatan keamanan lokal: `POST /api/auth/login` dibatasi 5 percobaan per menit (throttle).

## Dokumentasi

- [docs/PRODUCT_BRIEF.md](docs/PRODUCT_BRIEF.md) — kebutuhan produk.
- [docs/SETUP.md](docs/SETUP.md) — setup lokal dan perintah harian.
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — stack, struktur, dan prinsip domain.
- [docs/IMPLEMENTATION_PLAN.md](docs/IMPLEMENTATION_PLAN.md) — milestone, keputusan, asumsi, dan pemeriksaan yang dijalankan.
- [docs/DOCUMENTATION_STANDARD.md](docs/DOCUMENTATION_STANDARD.md) — standar dokumentasi kode.

Untuk development dengan Kimi Code: `./scripts/start-kimi.sh`, prompt awal di [docs/KIMI_FIRST_PROMPT.md](docs/KIMI_FIRST_PROMPT.md), profil spesialis di `.agents/agents/`.
