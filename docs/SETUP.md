# Setup lokal

## Prasyarat

- Docker Desktop aktif, Docker Compose tersedia
- Git
- Port `3306`, `5173`, dan `8000` tidak sedang dipakai

PHP, Composer, Node.js, dan MySQL lokal tidak wajib; service berjalan di container.

## Jalankan pertama kali

1. Dari root repo, jalankan `make up` (atau `docker compose up --build`).
2. Compose menyalin `backend/.env.example` menjadi `.env` pada first run, membuat app key, memasang dependency Composer dan npm, menjalankan migrasi standar, lalu menjalankan MySQL, Laravel, dan Vite.
3. Buka `http://localhost:5173` untuk frontend dan `http://localhost:8000` untuk backend.
4. Migrasi standar Laravel berjalan otomatis saat backend start. Untuk menjalankannya lagi: `docker compose exec backend php artisan migrate`.

> Catatan: DB masih kosong untuk domain apotek. Belum ada migration/schema bisnis, seed data, maupun akun demo.

## Perintah harian

```bash
make up       # jalankan semua service
make down     # hentikan service, data MySQL tetap disimpan
make logs     # ikuti log semua service
make rebuild  # bangun ulang image
```

Command Artisan dapat dijalankan di container:

```bash
docker compose exec backend php artisan <command>
```

Command npm dapat dijalankan di container frontend:

```bash
docker compose exec frontend npm <command>
```

## Database lokal

Compose membuat database `pharmalink` dengan kredensial development yang ada di `docker-compose.yml` dan `backend/.env`. Aplikasi backend memakai hostname service `mysql`; dari host gunakan `localhost:3306`.

Data persisten ada di Docker volume `mysql_data`. `make down` menjaga volume. `docker compose down -v` menghapus volume dan seluruh data lokal secara permanen.

## API

Backend Laravel dipisah dari frontend. Vite meneruskan request `/api/*` ke backend. Atur `VITE_API_PROXY_TARGET` untuk target lain; Compose sudah mengarahkannya ke service backend.

Endpoint awal Laravel tersedia setelah scaffold API (lihat `routes/api.php`). Tidak ada endpoint bisnis Pharmalink yang dibuat pada tahap setup ini.

## Troubleshooting

- Port bentrok: ubah port sisi kiri pada bagian `ports` di `docker-compose.yml`.
- Perubahan `.env` tidak terbaca: restart service backend dengan `docker compose restart backend`.
- Dependency frontend bermasalah: jalankan `docker compose run --rm frontend npm install`.
- Reset DB development: `docker compose down -v`, lalu `make up`. Ini menghapus semua data database lokal.
