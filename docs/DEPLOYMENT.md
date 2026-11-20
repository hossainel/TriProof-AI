# TriProof AI — Setup, Testing & Deployment

This document is the canonical operations runbook for v2.1. All commands assume the repository root unless stated otherwise.

## 1. Prerequisites

- PHP 8.2+ with `pdo_mysql`, `openssl`, `mbstring`, `gd`, `sodium`, `json`, `curl`, `zip`
- Composer 2.x
- Node.js 20+ and npm
- MySQL 8 / MariaDB 10.6+
- Flutter 3.35+ / Dart 3.12+ with Android SDK for mobile builds
- Optional: Neo4j, Qdrant/pgvector-compatible HTTP endpoint, DuckDB CLI, Ollama, llama.cpp

## 2. Local setup

```bash
composer install --no-interaction
cp .env.example .env
php artisan key:generate
# Set DB_* and APP_URL in .env
php artisan migrate --seed
npm install
npm run build
php artisan storage:link
php artisan serve
```

The seeded defaults publish `/.well-known/triproof-key.json`, the admin docs sections, and the operational team cards. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` before seeding to create the administrator account.

## 3. Quality gates

```bash
php artisan config:clear && php artisan route:clear && php artisan view:clear
./vendor/bin/pint --test
php artisan test
php artisan view:cache
npm run build
```

The suite covers cryptographic signing, quota locks, QR normalization, seven-stage verification, all five MCP tools, docs publishing, RAG fallbacks, HMAC webhooks, and German Tank analytics. Optional services are disabled in tests, so gates pass without Neo4j, vector DBs, DuckDB, Ollama, llama.cpp, or webhooks.

## 4. Flutter APK build

```bash
cd mobile
flutter pub get
flutter analyze
flutter test
flutter build apk --release
```

The universal output is `mobile/build/app/outputs/flutter-apk/app-release.apk`. For split ABIs, add `--split-per-abi`. Production builds should pin the API base URL and universal public key with `--dart-define`. If material icon tree-shaking fails because of a font-cache mismatch, add `--no-tree-shake-icons`.

## 5. Zero-SSH cPanel deployment

1. Download `dist/triproof-ai-cpanel.zip` (or `.tar.gz`).
2. Extract into the hosting application directory. Map the document root to `public/`.
3. Create a MySQL database/user and copy `.env.example` to `.env`; set `APP_URL`, `DB_*`, `APP_KEY`, admin credentials, and the public universal key.
4. Ensure PHP 8.2+ with the extensions listed above. `vendor/` is preinstalled, so no SSH Composer access is required.
5. Make `storage/`, `storage/framework/*`, `bootstrap/cache/`, and `public/uploads/` writable.
6. Run `php artisan migrate --seed --force`, `php artisan storage:link`, `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache` from the hosting file manager or terminal if available.
7. Schedule `* * * * * php artisan schedule:run >> /dev/null 2>&1` for daily Merkle anchoring.
8. Verify `/up`, `/.well-known/triproof-key.json`, `/docs`, `/verify`, and an admin login.

`CPANEL_DEPLOY.txt` is included in the archive with the same checklist.

## 6. Optional services

All optional integrations fail open and never affect deterministic verification:

| Service | Environment | Fallback |
|---|---|---|
| Neo4j provenance graph | `NEO4J_URI`, `NEO4J_USER`, `NEO4J_PASSWORD` | SQL recursive CTE |
| Vector search | `VECTOR_DB_URL`, `VECTOR_DB_COLLECTION` | Local BM25 + dense vectors |
| DuckDB analytics | `DUCKDB_PATH`, `ANALYTICS_SNAPSHOT_DIR` | JSON snapshots + SQL script |
| Ollama / llama.cpp | `LOCAL_AI_PROVIDER`, `OLLAMA_URL`, `LLAMA_CPP_URL`, `LOCAL_AI_MODEL` | Deterministic bilingual templates |
| Webhooks | `ALERT_WEBHOOK_URL`, `ALERT_WEBHOOK_SECRET` | Skipped silently |
| MCP | `MCP_API_KEY`, `MCP_API_KEY_USER_ID`, `RATE_LIMIT_MCP` | JWT with `mcp:read` scope |
