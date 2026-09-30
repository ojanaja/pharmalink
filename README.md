# Pharmalink

Starter project sistem manajemen apotek untuk tugas akhir. Repo berisi scaffold frontend/backend dan konfigurasi environment; belum ada fitur bisnis aplikasi.

## Stack

- Frontend: React + TypeScript + Vite
- Backend: Laravel 13 API + Sanctum
- Database: MySQL 8.4
- Local runtime: Docker Compose

Lihat [docs/SETUP.md](docs/SETUP.md) untuk menjalankan project dan [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) untuk struktur dan batas tanggung jawab tiap bagian.

## Jalankan

Butuh Docker Desktop dan Docker Compose.

```bash
make up
```

- Frontend: http://localhost:5173
- Backend: http://localhost:8000
- MySQL: localhost:3306

Hentikan dengan `make down`. Untuk setup lebih lengkap, termasuk migrasi awal dan pemeriksaan instalasi, lihat dokumentasi.
