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

## Batas saat ini

Ini baru fondasi project. Belum ada layar dashboard Pharmalink, modul obat/supplier, transaksi, pembelian, laporan, role bisnis owner/apoteker, atau skema domain apotek. Sanctum hanya menjadi fondasi auth API; konfigurasi permission/role bisnis diputuskan saat pengembangan fitur.

## Arahan domain untuk tahap berikutnya

Rancang model data sebelum membuat migration bisnis. Domain kemungkinan meliputi apotek, pengguna dan role, obat/satuan/kategori, supplier, batch dan tanggal kedaluwarsa, mutasi stok, penjualan dan item penjualan, purchase order dan item pembelian, serta stock opname. Catat keputusan tentang nomor transaksi, pembatalan/retur, audit perubahan stok, dan aturan harga di dokumen kebutuhan sebelum implementasi.
