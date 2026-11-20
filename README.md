# TriProof AI

**Enterprise Supply-Chain Integrity & Anti-Counterfeiting Platform**

**Current release: v2.1** — dynamic website trust sync, hardened QR normalization, verified offline keys, a read-only MCP server, contextual/graph RAG, provenance analytics, and air-gapped bilingual AI.

TriProof AI protects high-risk consumer supply chains with quota-locked purchase orders, Ed25519-signed unit labels, offline-first verification, deterministic bilingual anomaly detection, precision recalls, rework lineage, and auditable Merkle roots. The platform serves global buyers, Bangladeshi factories, administrators, station verifiers, and end consumers across a Laravel web console, a versioned JSON API, and a Flutter mobile verifier.

Documentation: [Architecture](ARCHITECTURE.md) · [API reference](docs/API.md) · [MCP server](docs/MCP.md) · [Setup & deployment](docs/DEPLOYMENT.md) · [Factory SOPs](docs/SOP.md) · [GS1 compliance](docs/GS1-COMPLIANCE.md) · [Checkpoint](CHECKPOINT.md) · [Mobile client](mobile/README.md) · [Database schema](schema.sql) · [Environment template](.env.example)

## Cryptographic core

- Ed25519 detached signatures over a canonical outer serial:
  `{FACTORY}-{MACHINE}-{BATCH}-{UNIT:06d}|{PROD_DATE}|{EXP_DATE}`
- Versioned key ring (`K00` legacy plus `K01+` active/verify-only keys), key rotation, buyer-scoped keys, and encrypted private material in `signing_keys`
- Universal public key discovery at `/.well-known/triproof-key.json`; the endpoint always publishes the configured legacy `K00` key, even when `signing_keys` is empty
- Dynamic mobile trust sync from any TriProof website: key ring + blacklist delta, persisted in SQLCipher/Keystore, usable fully offline
- SHA-256 unit hashes and deterministic binary Merkle roots with `0x01` domain separation and odd-node promotion
- Daily local Merkle anchor receipts via `php artisan triproof:anchor-merkle`
- `paragonie/sodium_compat` provides universal PHP 8.2+/8.4+ and cPanel compatibility; native `ext-sodium` is used when available
- V2 serials append `|DPP:{8-HEX-DIGEST}` and may carry a `KNN-` prefix; legacy `K00` serials remain verifiable

## Dynamic trust sync (v2.1)

The Flutter verifier can point at any deployed TriProof website without rebuilding the APK:

1. Enter or confirm the website URL in the **Update Offline Data from Website** card on the Home screen.
2. The app fetches `/.well-known/triproof-key.json` and `/api/v1/blacklist/delta`.
3. Active and verify-only Ed25519 keys are persisted in SQLCipher `app_meta` and provisioned into Android Keystore / iOS Keychain.
4. Exact blacklist entries and the Bloom filter are replaced locally, then `last_trust_sync_at` is updated.
5. Sync runs automatically on app initialization and when connectivity returns; the same operation is available manually from the card and verification-result screen.

No JWT is required for the public trust endpoints. If no dynamic key matches a label, mobile verification still tries the compiled-in `UNIVERSAL_PUBLIC_KEY` fallback.

## Feature matrix

| Surface | Capabilities |
|---|---|
| **Administrator** (`/admin/manage`, `/dashboard`) | Add / edit / remove Global Buyers, Factories, and Factory QA Operators; Factory deletion guardrails for linked POs, serials, and users; Platform & Compliance Settings in `system_settings`; network KPIs; Chart.js unit-state analytics; Leaflet GPS risk geography; key ring and anchor audit cards |
| **Global Buyer** | Quota-locked PO creation with factory packing window and retail expiry; signed batch generation; batch intelligence; deterministic English/বাংলা anomaly explanations; Merkle audit; precision recall; CSV/PDF exports |
| **Factory QA** | Row-locked atomic serial signing (`generate`); smart unit packing through `#pack-dialog` with live unpackaged counts and `#min–#max` range suggestions; QR sticker sheets; CSV/PDF manifests; parent-child rework lineage reconciliation |
| **Verifier / Public** | `/verify` station verification and `GET /v/{payload}` smart-URL product passport pages |
| **API** | `/api/v1` JWT API for login, orders, signing, packing, verification, blacklist deltas, notifications, cartons, rework, recalls, AI analysis, and health |
| **Mobile verifier** | Offline Ed25519 multi-key verification with embedded fallback key; editable website URL; auto/manual key-ring + blacklist sync; SQLCipher-encrypted `scan_history`; duplicate/prior-owner caches; individual deletion and full Clear History with bilingual confirmations; GPS evidence; background cloud sync |

## What it solves

- Prevents ghost overproduction with row-locked quota enforcement and unique serial constraints
- Verifies labels without connectivity and detects forged signatures, expiry, recalls, repeat owners, and clone scans
- Limits recalls to exact factory / machine / batch scopes and notifies affected devices
- Reconstructs rework lineage after replacement labels are issued
- Produces bilingual compliance guidance without requiring an external AI provider
- Keeps evidence auditable through scan logs, recall events, anomaly reports, Merkle roots, and anchor receipts

## Technology stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.2+ (validated on 8.4), Eloquent, queueable services |
| Frontend | Blade, Tailwind CSS 4, Vite 7, vanilla JS, Chart.js, Leaflet |
| Database | MySQL 8+ / MariaDB 10.6+ with `utf8mb4`; migrations are authoritative, `schema.sql` mirrors them |
| Crypto | Ed25519 (`paragonie/sodium_compat`), SHA-256 Merkle trees, HS256 JWT |
| Exports | Dompdf CSV/PDF manifests and `chillerlan/php-qrcode` QR sticker sheets |
| Mobile | Flutter 3.50+, Dart 3.12+, SQLCipher, ML Kit barcode/OCR, GPS, background sync |
| Hosting | cPanel shared hosting, VPS, or managed MySQL; zero-SSH web installer included |

## Quick start (local)

```bash
git clone https://github.com/hossainel/TriProof-AI.git
cd TriProof-AI
composer install
cp .env.example .env
php artisan key:generate
php artisan triproof:keys --write
```

Create a MySQL database and configure `DB_*` in `.env`, then:

```bash
php artisan migrate --force
php artisan db:seed --force
npm install && npm run build
php artisan test
vendor/bin/pint --test
```

Start the app:

```bash
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
```

Open `http://127.0.0.1:8000`. Health is available at `/api/v1/health` and public key metadata at `/.well-known/triproof-key.json`.

Demo data is created only in `local` when `TRIPROOF_DEMO_SEED=true` and `TRIPROOF_DEMO_PASSWORD` is at least 12 characters. Never enable demo seeding or hints in production.

## Environment

Start from [.env.example](.env.example). Use placeholders in documentation; never commit real passwords, private keys, JWT secrets, or tokens.

| Variable | Purpose |
|---|---|
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE` | Deployment identity and `Asia/Dhaka` display timezone |
| `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL/MariaDB connection |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | Database-backed sessions, cache, and queue by default |
| `HQ_PRIVATE_SIGNING_KEY` | Base64 Ed25519 secret; `.env` only |
| `UNIVERSAL_PUBLIC_VERIFICATION_KEY` | Base64 public key; safe for clients and documentation placeholders |
| `JWT_SECRET`, `JWT_TTL_MINUTES`, `JWT_ISSUER`, `JWT_AUDIENCE` | API token signing and claims |
| `KEY_ROTATION_MONTHS` | Key lifecycle advisory metadata |
| `OFFLINE_BLACKLIST_MAX_BYTES` | Offline package cap (default 50,000 bytes) |
| `OFFLINE_BLACKLIST_MAX_CLONED`, `OFFLINE_BLOOM_TARGET_ENTRIES`, `OFFLINE_BLOOM_MAX_BITS` | Offline cache and Bloom filter sizing |
| `VERIFICATION_GRACE_SECONDS`, `VERIFICATION_GRACE_METERS` | Store-checkout grace window |
| `MAX_BATCH_GENERATE`, `MAX_PDF_UNITS`, `QR_PREVIEW_PAIRS` | Generation, print, and preview limits |
| `AI_PROVIDER`, `AI_API_KEY`, `AI_BASE_URL`, `AI_MODEL`, `AI_VISION_MODEL` | `none`, `openai` compatible, or `gemini` |
| `ANCHOR_PROVIDER`, `ALERT_WEBHOOK_URL` | Merkle anchor provider and optional alert webhook |
| `CORS_ALLOWED_ORIGINS`, `RATE_LIMIT_*` | Exact allowed origins and throttle overrides |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Production administrator bootstrap |
| `TRIPROOF_DEMO_*` | Local-only demo data and login hints |

With `AI_PROVIDER=none` or invalid credentials, compliance workflows continue with deterministic bilingual fallbacks. Vision OCR is the only feature that returns `vision_ai_unavailable` without a configured provider.

## Tests and quality gates

```bash
php artisan test
vendor/bin/pint --test
npm run build
php artisan view:clear && php artisan view:cache
```

Use a dedicated MySQL-compatible test database (default `triproof_testing`). The suite covers signing, quota transactions, verification state transitions, admin CRUD, all modal round trips, packing suggestions, rework, AI fallback, QR normalization, public trust key discovery/CORS, API flows, and web authorization. Current gates: **118 PHP tests / 1,006 assertions**, **29 Flutter tests**, Pint, Vite build, and Blade cache.

## Zero-SSH cPanel deployment

The deployment archive `dist/triproof-ai-cpanel.zip` contains the Laravel web application, committed `public/build` assets, and Composer `vendor/` so the one-click installer works without SSH. It excludes `mobile/`, `node_modules/`, `.git/`, local `.env` files, and local test caches.

1. In cPanel, create the database and database user; note the database name, username, and `<YOUR_DB_PASSWORD>`.
2. Upload `dist/triproof-ai-cpanel.zip` to the hosting account and extract it into the web root (or an application directory whose `public/` folder is the document root).
3. Ensure the document root points to `public/`. If the host exposes the project root, use the included root `.htaccess` fallback.
4. Create `.env` from `.env.example` and set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `DB_*`, session/cookie security, CORS origins, and the public verification key placeholder `<UNIVERSAL_PUBLIC_KEY>` if a pre-provisioned key is used.
5. Make `storage/`, `storage/framework/*`, `bootstrap/cache/`, and `public/uploads/` writable by the web server user.
6. Visit `https://<YOUR_DOMAIN>/cpanel-setup.php` once. The setup script checks PHP/extensions, creates directories, clears caches, generates `APP_KEY`, `JWT_SECRET`, and Ed25519 keys, writes a locked-down `.env`, runs `php artisan migrate --force` and `php artisan db:seed --force`, then self-deletes.
7. If the host blocks self-deletion, remove `public/cpanel-setup.php` and any migration-runner helper immediately after setup.
8. Verify `GET /api/v1/health`, `GET /.well-known/triproof-key.json`, admin login, buyer/factory dashboards, QR export, and a verification scan.
9. Add the scheduler cron if not already configured by the host:

```cron
* * * * * cd /path/to/TriProof-AI && php artisan schedule:run >> /dev/null 2>&1
```

For manual VPS deployment, run `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan key:generate`, `php artisan triproof:keys --write`, `php artisan migrate --force`, `php artisan db:seed --force`, `php artisan optimize`, and configure TLS plus secure cookies.

Key rotation is non-disruptive:

```bash
php artisan triproof:keys:rotate --key-version=K02 [--buyer=ID]
```

The previous key remains `verify_only` so existing inventory stays verifiable. Back up the private key and `JWT_SECRET` securely; never place them in Markdown, ZIP metadata, or source control.

## Database

Migrations in `database/migrations/` are the source of truth. [schema.sql](schema.sql) is the DBA-facing DDL mirror. Current domain tables include `users`, `factories`, `purchase_orders`, `serial_registry`, `scan_logs`, `notifications`, `recall_events`, `ai_anomaly_reports`, `system_settings`, `signing_keys`, `carton_aggregations`, `carton_units`, `blockchain_anchors`, and `device_attestations`.

## Mobile client

The offline verifier lives in [`mobile/`](mobile/README.md). It is a Flutter application (package `tripoof_ai_scanner`) for Android and iOS, not a native Kotlin project. **v2.1** adds editable website URLs and automatic/manual key-ring + blacklist sync. Build it with:

```bash
cd mobile
flutter pub get
flutter analyze --no-fatal-infos
flutter test
flutter build apk --release --split-per-abi \
  --build-name=2.1.0 --build-number=1 \
  --dart-define=API_BASE_URL=https://<YOUR_DOMAIN> \
  --dart-define=UNIVERSAL_PUBLIC_KEY=<UNIVERSAL_PUBLIC_KEY> \
  --dart-define=BUILD_EPOCH_MS=1763510400000
```

Versioned APK artifacts are written to `mobile/build/app/outputs/flutter-apk/` as `app-arm64-v8a-release.apk`, `app-armeabi-v7a-release.apk`, and `app-x86_64-release.apk`.

## Project Team

| Member | Role |
|---|---|
| **Feroz Mahmud** | Team Lead |
| **Shafayet Al Hossain** | Full Stack Developer & Domain Expert |
| **Remon Islam** | AI & ML Engineer |
| **Masrurul Hasan Maktum** | Developer & Tester |
| **Golam Rabbani** | DevOps Engineer |

## AI, MCP & grounded documentation

- `POST /api/v1/mcp` exposes a read-only JSON-RPC 2.0 MCP server with five tools: 7-stage `verify_serial`, `get_batch_anomalies`, `trace_rework_lineage`, `check_recall_status`, and `get_merkle_proof`. A stdio/Streamable HTTP bridge and `mcp.json` are included.
- `POST /api/v1/ai/ask-docs` answers documentation and compliance questions using contextual, semantically chunked retrieval over README, architecture, API reference, live docs sections, and recent operational records.
- `ComplianceRagService` uses TF-IDF/keyword overlap with metadata boosting; `ContextualRagService` adds Anthropic-style context headers, semantic chunking, BM25 + dense retrieval over factory SOPs and GS1 guidelines.
- `ProvenanceGraphService` mirrors provenance to Neo4j when configured and falls back to SQL recursive CTEs; `SerialGapAnalyticsService` exports snapshots for DuckDB and computes German Tank estimates without locking MySQL.
- `AiExplanationService` supports Ollama, llama.cpp, and deterministic bilingual fallback; alert webhooks are HMAC-SHA256 signed and an n8n workflow template is provided.
- MCP requires `mcp:read` scope via JWT or API key; all services remain deterministic when external AI/graph/vector/OLAP systems are disabled. Private signing keys are never returned.

## Security notes

- Private key material never appears in API responses, logs, exports, or documentation.
- API and web flows share services and authorization policies; role gates are enforced server-side.
- JWT logout revokes the token ID until expiry; role and account changes invalidate stale tokens.
- Serial generation uses `SELECT ... FOR UPDATE`, exact-N inserts, and unique constraints.
- Web forms use CSRF, validation, hashed passwords, role middleware, and scoped `old()` modal reopening.
- Offline packages are capped, and verification endpoints are rate limited.
- Error responses hide internals; detailed exceptions are logged server-side.
