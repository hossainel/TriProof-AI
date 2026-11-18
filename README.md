# TriProof AI

TriProof AI is a production-grade supply-chain authenticity platform for global buyers and
Bangladeshi factories. It signs every unit with an Ed25519 signature under a quota-locked
purchase order, verifies labels offline and online, detects clone scans, runs precision batch
recalls, reconstructs rework lineage, and produces bilingual English/Bangla compliance guidance.

The application is built with Laravel 12, native PHP sodium, MySQL/MariaDB, Blade, Tailwind CSS 4,
Chart.js, Leaflet, and a provider-agnostic AI layer with a deterministic fallback.

The native Kotlin [TriProof AI Scanner](android/README.md) lives in `android/`. It verifies signed
labels offline, stores scan history on the phone, and syncs it to this API when authenticated.

## What it solves

- Quota-locked serial issuance prevents ghost overproduction and label copying.
- Ed25519 labels can be verified without a network connection.
- Cloud cross-checking claims ownership and detects clones with a 5-minute / 100-metre grace rule.
- Batch recall targets only affected factory/machine/batch units and notifies affected devices.
- Rework reconciliation links replacement units to their original parent serials.
- Anomaly analysis detects clone clusters, ghost production, rework gaps, and defect clusters.
- Bilingual dashboards and notifications keep factory-floor and buyer teams aligned.
- CSV/PDF batch exports and Merkle-root auditing make compliance evidence printable and auditable.

## Key components

| Area | Implementation |
|---|---|
| Web UI | Role dashboards for buyers, factory QA and admins; verifier; recall/rework/AI dialogs |
| REST API | Versioned `/api/v1` JWT API with rate limits and a consistent JSON envelope |
| Signing | One HQ Ed25519 keypair; per-unit outer/inner serials; SHA-256 unit hashes |
| Quota control | Row-locked transactions and unique serial constraints |
| Verification | Signature + registry checks, recall/expiry precedence, device claim and clone alerts |
| Offline support | Gzip+Base64 blacklist package capped below 50 KB |
| Audit | Merkle roots, append-only scan ledger, recall events and anomaly reports |
| AI | OpenAI-compatible or Gemini chat/vision with deterministic bilingual fallback |

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full design and [docs/API.md](docs/API.md) for
the endpoint reference and cURL examples. `schema.sql` is the canonical MySQL DDL mirroring the
migrations.

## Requirements

- PHP 8.2+ (validated on PHP 8.4) with `sodium`, `pdo_mysql`, `mbstring`, `openssl`, `curl`,
  `dom`, `fileinfo`, and `zlib`
- MySQL 8.0+ or MariaDB 10.6+ with `utf8mb4`
- Composer 2
- Node.js 20+ and npm for asset builds
- A queue worker if `QUEUE_CONNECTION=database` is used
- An HTTPS reverse proxy or web server in production

The test suite requires a MySQL-compatible database. `pdo_sqlite` is not required and SQLite is
not used.

## Installation

```bash
git clone https://github.com/hossainel/TriProof-AI.git
cd TriProof-AI
composer install
cp .env.example .env
php artisan key:generate
```

Create the database, configure `DB_*` in `.env`, then generate the signing keys:

```bash
php artisan triproof:keys --write
```

`--write` stores `HQ_PRIVATE_SIGNING_KEY`, `UNIVERSAL_PUBLIC_VERIFICATION_KEY`, and a random
`JWT_SECRET` in `.env` and sets the file mode to `0600`. The private key must never be committed,
printed, or shared. Rotating the key with `--force` invalidates previously printed labels.

Run migrations and the production administrator seed:

```bash
php artisan migrate --force
php artisan db:seed --force
```

Set `ADMIN_EMAIL` and a 12+ character `ADMIN_PASSWORD` before seeding. The production seeder
creates/updates only that administrator.

Build frontend assets:

```bash
npm ci
npm run build
```

For local images, omit `--force` and run `php artisan migrate --seed`.

### Local demo data

Demo data is only created when the app is in the `local` environment, `TRIPROOF_DEMO_SEED=true`,
and `TRIPROOF_DEMO_PASSWORD` is at least 12 characters. It creates:

- Buyer: `buyer@triproof.local`
- Factory QA: `factory@triproof.local` (factory `DHK04`, Bangla UI)
- Admin: `admin@triproof.local`
- Two purchase orders and a signed demo batch

`TRIPROOF_SHOW_DEMO_HINTS=true` shows the demo credentials on the login screen. Never enable
demo seeding or demo hints in production.

## Environment

Start from [.env.example](.env.example). The important settings are:

| Variable | Purpose |
|---|---|
| `APP_ENV`, `APP_DEBUG`, `APP_URL` | Deployment identity; use `production`, `false`, and the real HTTPS URL |
| `APP_TIMEZONE` | Display timezone; database timestamps remain UTC |
| `DB_*` | MySQL/MariaDB connection |
| `SESSION_*` | Web session security; use secure cookies and HTTPS in production |
| `HQ_PRIVATE_SIGNING_KEY` | Base64 Ed25519 secret; `.env` only |
| `UNIVERSAL_PUBLIC_VERIFICATION_KEY` | Base64 Ed25519 public key; safe to embed in clients |
| `JWT_SECRET` | HS256 signing secret; at least 32 characters |
| `JWT_TTL_MINUTES` | API token lifetime |
| `OFFLINE_BLACKLIST_MAX_BYTES` | Hard cap for the offline package (default 50,000) |
| `VERIFICATION_GRACE_SECONDS`, `VERIFICATION_GRACE_METERS` | Store-checkout grace window |
| `MAX_BATCH_GENERATE`, `MAX_PDF_UNITS` | Generation and print safety limits |
| `AI_PROVIDER`, `AI_API_KEY`, `AI_*` | `none`, `openai` (OpenAI-compatible), or `gemini` |
| `CORS_ALLOWED_ORIGINS` | Exact comma-separated origins; avoid `*` in production |
| `RATE_LIMIT_*` | Per-minute throttle overrides |
| `ADMIN_*` | Production administrator bootstrap |
| `TRIPROOF_DEMO_*` | Local-only demo data and login hints |

With `AI_PROVIDER=none` or a missing/invalid key, all compliance workflows continue using the
deterministic bilingual fallback. Vision OCR is the only feature that requires a configured AI
provider and returns a clear `vision_ai_unavailable` error without one.

## Running locally

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
```

Open `http://127.0.0.1:8000`. The health endpoint is `/api/v1/health`; public key metadata is at
`/.well-known/triproof-key.json`.

## Tests and quality

Configure a dedicated database (default `triproof_testing`) in the environment and run:

```bash
php artisan test
vendor/bin/pint --test
```

In CI, provide `DB_HOST`, `DB_PORT`, `DB_USERNAME`, and `DB_PASSWORD` for the `triproof_testing`
database. `phpunit.xml` forces the MySQL connection, array cache/session/mail, sync queue, and
disables demo seeding. The suite covers signing/verification, transactional quota enforcement,
claim/grace/clone/recall/expiry/forgery/idempotency, rework lineage, AI fallback/scoping, web
authentication, and the JWT API flow.

## Production deployment (VPS or cPanel)

1. Upload the application outside the web root where possible. Point the document root to
   `public/`. If the host only allows the project root, use the provided root `.htaccess`
   fallback.
2. Install production dependencies and build assets:
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   ```
3. Copy `.env.example` to `.env` and configure the production values. Set `APP_ENV=production`,
   `APP_DEBUG=false`, the real `APP_URL`, secure session cookies, database credentials, CORS
   allow-list, and AI keys if used.
4. Generate the app key and signing keys:
   ```bash
   php artisan key:generate
   php artisan triproof:keys --write
   ```
5. Run migrations and the admin seed:
   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```
6. Make `storage/` and `bootstrap/cache/` writable by the web server user.
7. Cache production metadata:
   ```bash
   php artisan optimize
   ```
8. Configure a queue worker for `QUEUE_CONNECTION=database` if notifications/export work is
   queued in your deployment, and add the scheduler cron:
   ```cron
   * * * * * cd /path/to/TriProof-AI && php artisan schedule:run >> /dev/null 2>&1
   ```
9. Terminate TLS at the web server/proxy, keep HTTPS-only cookies enabled, and restrict access to
   `.env`, storage logs, and version-control metadata.
10. Verify `GET /api/v1/health`, `GET /.well-known/triproof-key.json`, login, dashboard, export,
    and a verification scan after deployment.

The signing private key and `JWT_SECRET` must be backed up securely. Losing the signing key means
new labels cannot be signed for the existing public key; leaking it compromises authenticity.
Rotate with `php artisan triproof:keys --force` only when old labels can be invalidated.

## Database

`php artisan migrate` is the source of truth. For a DBA review or a fresh manual install,
`schema.sql` contains the equivalent table definitions, indexes, and foreign keys. The full table
contract is documented in ARCHITECTURE.md section 5.

## Security notes

- The HQ private key never appears in API responses, logs, exports, or the database.
- The API and web layer share the same authorization policies and service layer.
- JWT logout revokes the token ID in cache until expiry.
- Serial generation uses `SELECT ... FOR UPDATE`, exact-N inserts, and unique constraints.
- Web forms use CSRF, session regeneration, hashed passwords, and role gates.
- Verification rate limits and a capped offline package protect device workloads.
- Error responses hide internals; detailed exceptions are logged server-side.
