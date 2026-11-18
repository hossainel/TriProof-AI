# TriProof AI — Checkpoint / Build Plan

> Live working memory and compacted session state. Read with `ARCHITECTURE.md`.
> Last compacted: implementation, UI, tests, schema, API docs, and local smoke checks complete;
> only final quality gate and Git publication remain.

## Current State

- Repo: `https://github.com/hossainel/TriProof-AI.git`, branch `main`; remote initially had only
  `README.md`. No commit or push has been performed yet.
- Stack: Laravel 12.73 + PHP 8.4.27 + MySQL/MariaDB + native sodium + Blade/Tailwind 4/Vite/
  Chart.js/Leaflet; Composer `firebase/php-jwt` v7.2 and `barryvdh/laravel-dompdf` v3.1.
- SSH auth to GitHub verified as `hossainel`.
- All backend services, models, migrations, policies, requests, middleware, controllers, routes,
  bilingual UI, dashboards, verifier, dialogs, error pages, PDF/CSV exports, and assets are built.
- Local environment is fully provisioned with isolated MariaDB, generated app/signing keys, and
  seeded production/demo data.
- Automated suite is green: 45 tests / 195 assertions across crypto, generation, verification,
  recall/rework, anomaly analysis, web auth, and API flows.
- `schema.sql`, `docs/API.md`, `README.md`, and the root shared-host `.htaccess` exist.
- `php artisan view:cache` and `npm run build` succeed. `public/build` is intentionally ignored by
  Git; rebuild during deployment.

## Phase Checklist

- [x] Phase 0 — Inspect environment, clone repo, write architecture + checkpoint.
- [x] Phase 1 — Laravel scaffold, Composer/npm dependencies.
- [x] Phase 2 — Config/env contract, signing-key command, migrations, models, factories/seeders.
- [x] Phase 3 — Crypto/Merkle/JWT/blacklist services.
- [x] Phase 4 — Purchase order, quota-locked generation, CSV/PDF export service, packing.
- [x] Phase 5 — Verification state machine, notifications, recall, rework, AI analysis.
- [x] Phase 6 — API controllers, FormRequests, routes, throttles, CORS, JSON errors.
- [x] Phase 7 — Web UI: layouts/assets/theme, buyer/factory/admin dashboards, verifier, dialogs,
  PDF view, error views.
- [x] Phase 8 — `schema.sql`, `docs/API.md`, README, deployment notes.
- [x] Phase 9 — Tests: crypto, quota, crosscheck, grace, clone, recall, forged, expired,
  idempotency, notifications, rework, AI fallback/scoping, web auth, API flow. All pass.
- [x] Phase 10 — Isolated MariaDB migration/integration check, asset build, smoke tests.
- [ ] Phase 11 — Final Pint check, fresh test/build confirmation, commit and push to `origin/main`.

## Implemented Contracts

- Crypto: `{FACTORY}-{MACHINE}-{BATCH}-{UNIT:06d}|{ProdYYYYMMDD}|{ExpYYYYMMDD}`;
  Ed25519 detached signature; unit hash `sha256(outer|inner)`; Merkle root persisted.
- Generation: exact-N quota lock via `SELECT ... FOR UPDATE`; unique serial/hash constraints;
  factories cannot sign; over-quota/conflict paths are transactional and write nothing partial.
- Verification precedence: idempotent `sync_uuid` → crypto/registry → recalled → expired →
  first claim → owner repeat → 300 s/100 m grace → clone flag + bilingual alerts/anomaly;
  forged scans are logged but never claim ownership.
- Offline blacklist: gzip+Base64, strictly under 50 KB by trimming cloned/recalled data.
- Recall: factory/machine/batch precision, scoped buyer/factory authorization, affected-device
  notifications, durable `recall_events` audit.
- Rework: parent-child links, gaps, cycles, factory mismatch, signature checks, OCR fallback,
  idempotent relinking, anomaly reports.
- AI: OpenAI-compatible/Gemini chat + vision, deterministic bilingual fallback, scoped anomaly
  detection (including null-PO recall/rework security), factory/machine-aware deduplication.
- Web/API share the service layer and authorization policies. Web sessions use CSRF; API uses
  HS256 JWT with JTI revocation. API prefix is `/api/v1`; token is at `data.token`.

## Local Runtime Notes

- Isolated MariaDB: datadir `/tmp/triproof-mariadb/data`, socket
  `/tmp/triproof-mariadb/run/mysqld.sock`, port `3307`, root has no password. App user `triproof`
  has access only to `triproof_ai` and `triproof_testing`; password is stored in
  `/tmp/triproof-mariadb/db-password`.
- Local `.env` is untracked, mode `0600`; it contains the app key, signing keys, DB credentials,
  and local demo admin credentials. Never commit it.
- Demo seed is local-only (`TRIPROOF_DEMO_SEED=true`) with `TRIPROOF_DEMO_PASSWORD`; factory demo
  user is Bangla-first, so English-only assertions on localized verdict text can fail.
- No AI API key is available. Fallback paths are verified; configured-provider paths are tested
  with `Http::fake`.
- PHP lacks `pdo_sqlite`; tests use MySQL/MariaDB via `phpunit.xml` (`DB_DATABASE=triproof_testing`)
  and inherit DB credentials from the environment/.env.
- Local server command: `php artisan serve --host=127.0.0.1 --port=8000`.
- Stale IDE/LSP diagnostics in `partials/stats.blade.php` and `partials/orders-table.blade.php`
  are false positives; `php artisan view:cache` compiles all Blade successfully.

## Next Actions

1. Run `vendor/bin/pint` (or `--test`), then re-run `php artisan test`.
2. Re-run `php artisan view:cache`, `npm run build`, and a final health/login/verify smoke check.
3. Inspect `git status`/diff, stage only project files (exclude `.env`, `op.sh`, `vendor`,
   `node_modules`, caches, and generated build output), commit, and push `main` to `origin`.
