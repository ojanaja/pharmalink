# Backend instructions

Follow repository-level `../AGENTS.md` and `../docs/PRODUCT_BRIEF.md`.

- This service is Laravel 13 API + Sanctum. React frontend lives in `../frontend/`.
- Run PHP/Composer/Artisan commands through Docker Compose from repo root. Do not install a separate host PHP runtime.
- Use the MySQL Compose service for local database work. Do not use SQLite as the project database.
- Put schema changes in reversible Laravel migrations. Protect transaction and stock consistency.
- Do not install Laravel Boost or unrelated dependencies unless the user specifically asks.
