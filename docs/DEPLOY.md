# Deploy Pharmalink di VPS (103.93.160.145)

Dideploy 2026-10-27. Stack: 3 container Docker (mysql 8.4, backend Laravel, frontend nginx)
di network `pharmalink_default`, di-proxy `keuangan-nginx` untuk host `pharmalink.prambanandigital.com`.

## File penting
- `~/pharmalink/docker-compose.prod.yml` — definisi 3 service (build via `backend/Dockerfile.prod`, `frontend/Dockerfile.prod`)
- `~/pharmalink/.deploy-creds.txt` (chmod 600) — password MySQL + akun login demo
- `~/keuangan_pusbangkom/nginx/nginx.conf` — block server `pharmalink.prambanandigital.com` (exact name, menang atas wildcard regex SaaS)
- Backup pre-deploy: `~/keuangan_pusbangkom/nginx/nginx.conf.bak-pharmalink-20261027091926`

## Operasional
```bash
cd ~/pharmalink
docker compose -f docker-compose.prod.yml ps            # status
docker compose -f docker-compose.prod.yml up -d --build # rebuild + up
docker logs -f pharmalink-backend                       # log (migrate/seed/serve di command)
```

## Catatan penting
1. **Nginx ↔ pharmalink**: `keuangan-nginx` di-connect ke network pharmalink via
   `docker network connect pharmalink_default keuangan-nginx`.
   Jika keuangan-nginx di-recreate (compose `keuangan-pusbangkom-prod`), ulangi perintah itu,
   lalu `docker exec keuangan-nginx nginx -s reload`.
2. **File nginx.conf di-bind ro**: edit harus in-place (jangan `mv`/rename — inode lama
   tetap ter-mount; container harus di-restart untuk melihat file hasil rename).
3. **Backend pakai `php -S 0.0.0.0:8000 -t public`, BUKAN `php artisan serve`**:
   Laravel scrub environment variables saat spawn built-in server lewat artisan,
   sehingga env Docker (DB_*, CACHE_STORE, APP_KEY) tidak sampai ke worker PHP.
4. **APP_KEY** disimpan di `.env` di dalam container, dibuat idempotent oleh command
   (`grep -q ^APP_KEY=. .env || php artisan key:generate --force`).
   HILANG saat image di-rebuild — command akan membuat ulang (token Sanctum lama invalid).
5. **MySQL tidak di-publish ke host** (internal network saja, port 3306/33060 hanya antar-container).
6. DB persist di volume `pharmalink_pharmalink_mysql_data`. Seed DemoSeeder idempotent
   (guard `Sale::exists`); transaksi demo hanya dibuat sekali.

## Rollback
```bash
cd ~/pharmalink && docker compose -f docker-compose.prod.yml down
cp ~/keuangan_pusbangkom/nginx/nginx.conf.bak-pharmalink-20261027091926 ~/keuangan_pusbangkom/nginx/nginx.conf
docker network disconnect pharmalink_default keuangan-nginx || true
docker restart keuangan-nginx   # bind file perlu restart bila file pernah di-rename
```
