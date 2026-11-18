# TriProof AI — Checkpoint

> Compacted session state. Read with `ARCHITECTURE.md` and `docs/API.md`.
> Status: 100% complete and published. No implementation or test tasks remain.

## Final State

- Branch `main` is clean and synchronized with `origin/main` at commit `4571560`.
- Remote: `git@github.com:hossainel/TriProof-AI.git` (SSH).
- Stack: Laravel 12.73, PHP 8.4.27, MySQL/MariaDB, native sodium, Blade/Tailwind 4/Vite,
  Chart.js, Leaflet, `firebase/php-jwt`, and `barryvdh/laravel-dompdf`.
- Backend, API, web UI, bilingual content, crypto, verification, recall/rework, AI fallback,
  exports, schema, docs, and deployment notes are complete.
- Final gates: 45 tests / 195 assertions pass, Pint passes, Vite production build passes,
  `view:cache`, route caching, and `php artisan optimize` verified.
- GitHub Actions CI runs the MySQL/MariaDB test suite, Pint, and the Vite production build.
- Local smoke checks passed: health, public key, all three role logins/dashboards, verifier,
  API login/orders/CSV export, root redirect, and login page.

## Published Contracts

- Crypto: `{FACTORY}-{MACHINE}-{BATCH}-{UNIT:06d}|{ProdYYYYMMDD}|{ExpYYYYMMDD}`;
  Ed25519 detached signature; `sha256(outer|inner)` unit hash; persisted Merkle root.
- Generation: exact-N row-locked quota; unique serial/hash constraints; factories cannot sign.
- Verification: idempotent `sync_uuid` → crypto/registry → recalled → expired → first claim →
  owner repeat → 300 s/100 m grace → clone flag; forged scans never claim ownership.
- Offline blacklist: gzip+Base64, strictly below 50 KB by trimming.
- Recall: factory/machine/batch precision, scoped authorization, device alerts, audit events.
- Rework: lineage links, gap/cycle/factory/signature checks, OCR fallback, idempotent relinking.
- AI: OpenAI-compatible/Gemini chat+vision with deterministic bilingual fallback and scoped
  anomaly detection/deduplication.
- Web and API share services/policies. Web uses CSRF sessions; API uses HS256 JWT with JTI
  revocation. API prefix `/api/v1`; login token at `data.token`.

## Environment Notes

- Local `.env` is untracked (mode `0600`) with app/signing/JWT keys and local DB/demo credentials.
- Isolated MariaDB runs on port `3307` with socket
  `/tmp/triproof-mariadb/run/mysqld.sock`; databases are `triproof_ai` and `triproof_testing`.
- Demo seed is local-only and the factory demo user is Bangla-first.
- No real AI API key is available; fallback is verified and configured-provider paths are tested
  with `Http::fake`.
- Tests require MySQL/MariaDB; SQLite is not used (`pdo_sqlite` is absent).
- Local server: `php artisan serve --host=127.0.0.1 --port=8000`.
- Stale LSP diagnostics in `partials/stats.blade.php` and `partials/orders-table.blade.php` are
  false positives; Blade compilation succeeds.

## Remaining Work

None. Production deployment, real AI credentials, and production key backups are external
operational actions, not unfinished application work.
