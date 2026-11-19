# TriProof AI — Architecture

> Working memory file. Read this first, then `CHECKPOINT.md` for current build state.
> Every material architectural change must be reflected here.

## 1. Mission

TriProof AI is a production-grade supply-chain authenticity platform for global buyers and
Bangladeshi factories. It combines quota-locked Ed25519 serial signing, offline-first mobile
verification, cloud cross-checking, precision recalls, rework lineage reconstruction and
bilingual (English/Bangla) AI-assisted compliance.

It addresses eight core problems:

1. Outer-box swapping and counterfeiting.
2. Unreported "ghost" overproduction.
3. Broad, imprecise product recalls.
4. Broken serial lineage during factory rework.
5. Verification in internet dead zones.
6. Formula / label reverse-engineering.
7. Smudged or curved-label scanning (Vision AI + signature fallback).
8. English-to-Bangla compliance gaps on the factory floor.

## 2. Technology Stack

| Layer | Choice |
|---|---|
| Language | PHP 8.2+ (validated on PHP 8.4) |
| Framework | Laravel 12 (MVC, Eloquent, migrations, queues, HTTP client) |
| Database | MySQL 8.0+ / MariaDB 10.6+ (utf8mb4), PDO prepared statements via Eloquent/Query Builder |
| Cryptography | PHP native `ext-sodium` — Ed25519 detached signatures; SHA-256 Merkle trees |
| API auth | Stateless JWT (HS256, `firebase/php-jwt`) + Laravel web sessions/CSRF for dashboard |
| Rate limiting | Laravel RateLimiter + database cache |
| Frontend | Blade, Tailwind CSS 4 (Vite production build), vanilla JS, Chart.js, Leaflet |
| PDF export | barryvdh/laravel-dompdf (printable batch packages) |
| AI | Provider-agnostic OpenAI-compatible or Gemini HTTP client via Laravel HTTP, with deterministic bilingual fallback |
| Tests | PHPUnit feature/unit tests against MySQL/MariaDB (`triproof_testing`); no SQLite dependency |
| Hosting | cPanel / VPS / managed MySQL |

No frontend SPA framework. No placeholder TODOs. All user-facing flows are implemented.

## 3. Request Flow

```
public/index.php
  └─ bootstrap/app.php
      ├─ routes/web.php  → session auth + CSRF → Blade dashboards / web verifier
      └─ routes/api.php  → CORS + JWT middleware + throttle → JSON controllers
                            └─ Service layer (purchase orders, signing, verification,
                               notifications, recall, rework, AI, Merkle, blacklist)
                                 └─ Eloquent models → MySQL
```

Both API and web controllers reuse the same service layer; business rules are never duplicated.

## 4. Directory Map (key paths)

```
app/
  Console/Commands/GenerateSigningKeys.php     # php artisan triproof:keys
  Http/
    Controllers/Api/*                          # versioned REST controllers
    Controllers/Web/*                          # dashboard / verifier controllers
    Middleware/AuthenticateApi.php             # JWT authentication
    Middleware/EnsureRole.php                  # role gates for web pages
    Middleware/SetLocale.php                   # en/bn locale from session/user
    Requests/*                                 # validation
  Models/                                      # User, PurchaseOrder, SerialRegistry, ScanLog,
                                               # Notification, AiAnomalyReport, RecallEvent
  Policies/PurchaseOrderPolicy.php
  Services/
    Crypto/MasterKeyService.php
    Crypto/SerialCryptoService.php
    Crypto/MerkleService.php
    Auth/JwtService.php
    PurchaseOrderService.php
    BatchGenerationService.php
    BatchExportService.php
    VerificationService.php
    NotificationService.php
    RecallService.php
    ReworkService.php
    Ai/AiClient.php
    Ai/AnomalyAnalysisService.php
    OfflineBlacklistService.php
  Support/ApiResponse.php
bootstrap/app.php                              # route registration, middleware, JSON errors
config/triproof.php                            # crypto, JWT, rate limits, AI, exports
database/migrations/*                          # full MySQL schema
database/seeders/*                             # production admin + local demo seeder
resources/
  css/app.css                                  # Tailwind 4 + Leaflet styles
  js/app.js                                    # charts, maps, modals, uploads, i18n hooks
  views/layouts/*, auth/*, dashboard/*, verify/*
routes/web.php, routes/api.php
schema.sql                                     # canonical MySQL DDL (mirrors migrations)
docs/API.md                                    # endpoint reference + cURL examples
ARCHITECTURE.md, CHECKPOINT.md, README.md
```

## 5. Data Model

Core tables (exact field contract lives in `schema.sql` and migrations):

- `users` — role (`global_buyer`, `factory_qa`, `admin`), `language_pref`, plus the working
  extension `factory_id` so factory QA users can be scoped to a factory code.
- `purchase_orders` — buyer/factory scope, `authorized_quota`, `generated_count`,
  `factory_expiry_days` (packing SLA), `retail_expiry_days` (label expiry), `merkle_root_hash`.
- `serial_registry` — one row per signed unit: outer/inner serials, SHA-256 `unit_hash`,
  factory/machine/batch/unit coordinates, production/expiry dates, lifecycle status,
  first-claim device/time/GPS, `rework_parent_serial`.
- `scan_logs` — append-only verification ledger, idempotent by `sync_uuid`; stores offline
  verdict, authoritative cloud verdict, GPS and device.
- `notifications` — bilingual device inbox (`info`, `warning`, `critical_clone`, `recall_alert`).
- `ai_anomaly_reports` — bilingual actionable reports, affected unit payload (JSON), status.
- Supporting: `recall_events` (audit of precision recalls) and Laravel infrastructure tables
  (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`).

Relationships: users (buyer) 1—N purchase_orders; purchase_orders 1—N serial_registry and
ai_anomaly_reports; serial_registry 1—N scan_logs by outer serial; recall_events M—1 factory scope.

Indexes: unique `po_number`, `outer_serial`, `unit_hash`, `sync_uuid`; composite
`(factory_id, machine_id, batch_id)`, `(po_id, status)`, `(device_token, is_read)`, and
`(outer_serial, scanned_at)`.

## 6. Cryptography

### Key management

- One Ed25519 master keypair generated by `sodium_crypto_sign_keypair()`.
- `HQ_PRIVATE_SIGNING_KEY` = Base64 64-byte secret key, `.env` only. Never logged, never in API.
- `UNIVERSAL_PUBLIC_VERIFICATION_KEY` = Base64 32-byte public key, embedded in mobile/web.
- Key setup: `php artisan triproof:keys --write` (refuses to overwrite unless `--force`).
- Rotation is out of scope for v1; the public key and SHA-256 fingerprint are exposed at
  `GET /.well-known/triproof-key.json` for transparency.

### Serial contract

```
outer_serial = {FACTORY_ID}-{MACHINE_ID}-{BATCH_ID}-{UNIT:06d}|{ProdYYYYMMDD}|{ExpYYYYMMDD}
inner_serial = Base64( sodium_crypto_sign_detached(outer_serial, HQ_PRIVATE_SIGNING_KEY) )
unit_hash    = SHA-256( outer_serial . "|" . inner_serial )   # hex, unique
```

`Exp` is production date + PO `retail_expiry_days`. `factory_expiry_days` is the packing SLA
shown to buyers/factories (deadline = PO creation + factory days).

### Quota lock

Inside a MySQL transaction:

1. `SELECT ... FOR UPDATE` the PO row.
2. Reject if requested N > `authorized_quota - generated_count`.
3. Allocate factory/machine/batch unit numbers (explicit start or next free).
4. Sign and insert exactly N pairs; unique constraints are a second line of defense.
5. Recompute the PO Merkle root over all unit hashes; update `generated_count` and root.

Factories cannot issue valid signatures because they never hold the HQ private key. Expired
factory deadlines flag compliance warnings; they do not silently authorize extra units.

### Merkle anchoring

`MerkleService` implements deterministic SHA-256 binary Merkle trees over `unit_hash` leaves
in insertion order, with odd-node promotion and domain separation. The root is stored on the
PO and re-computable for audit. The audit viewer compares stored vs recomputed roots and
shows leaf count + verification status; the root is ready for Phase-2 blockchain anchoring.

### Offline package

Login returns a gzip+Base64 JSON package (hard cap 50,000 bytes, target <48 KiB) containing
recent cloned serials and recalled batch keys, plus the public key. The client caches it,
verifies Ed25519 offline, and reports offline verdicts when connectivity returns.

## 7. Verification State Machine (cloud authority)

For each scan (`sync_uuid` idempotency key):

1. Duplicate `sync_uuid` → return stored result (`duplicate: true`).
2. Recompute Ed25519 verification with the public key; also compare outer/inner/unit hash to
   the registry. Failure or unknown serial → `forged_signature` (logged, AI ghost signal).
3. Status `recalled` → `recalled_batch`, queue recall alert if absent.
4. `exp_date` passed → `expired`.
5. `claimed_device_token IS NULL` → atomically claim: store device/time/GPS, status
   `claimed_genuine`, verdict `verified_clean`.
6. Same device → `owner_repeat_ok` (never a false alarm).
7. Different device → Store Checkout Grace Rule: allow `store_grace_ok` only when
   |Δt| ≤ 5 minutes and haversine distance ≤ 100 m from the first claim. Otherwise:
   status `cloned_flagged`, `clone_detected`, critical bilingual notifications to **both**
   devices, AI anomaly report upsert, and immediate `feedback_notifications` in the response.

Logs can be single or batched; results are returned in chronological scan order. Forged scans
are recorded but never claim ownership. Server-side timestamps are used for live scans; offline
scans preserve client `scanned_at` and are documented as a trust boundary.

## 8. API Surface (`/api/v1`)

| Method | Path | Roles | Purpose |
|---|---|---|---|
| POST | `/auth/login` | public (throttled) | JWT, public key, offline blacklist |
| POST | `/auth/logout` | JWT | revoke current JWT ID via cache |
| GET | `/auth/me` | JWT | identity + key fingerprint |
| POST | `/orders` | buyer/admin | create PO (quota contract) |
| GET | `/orders` | JWT | scoped PO list |
| POST | `/orders/generate-batch` | buyer/admin | quota-locked N signs + Merkle root |
| GET | `/orders/{po}/export?format=csv\|pdf` | buyer/admin/factory | printable batch package |
| GET | `/orders/{po}/merkle` | buyer/admin/factory | audit stored vs recomputed root |
| POST | `/units/pack-batch` | factory/admin | mark generated units packed |
| POST | `/verify/online-crosscheck` | JWT | single/batched offline scan cross-check |
| GET | `/notifications/{device_token}` | JWT | bilingual inbox |
| POST | `/notifications/mark-read` | JWT | mark IDs/all read |
| POST | `/rework/reconcile` | factory/admin | rebuild serial lineage, OCR fallback |
| POST | `/ai/analyze` | buyer/factory/admin | clone/ghost/rework/defect intelligence |
| POST | `/recall/trigger` | buyer/factory/admin | precision batch recall + push alerts |
| GET | `/health` | public | liveness/dependency status |
| GET | `/.well-known/triproof-key.json` | public | public verification key/fingerprint |

All JSON errors use a consistent envelope. Rate limits: login 10/min/IP; general API 120/min/
identity; verification 600/min identity; AI/export lower. CORS origins from env.

## 9. AI Layer

`AiClient` supports OpenAI-compatible chat completions and Gemini `generateContent`, chosen by
`.env` (`AI_PROVIDER`, `AI_API_KEY`, `AI_BASE_URL`, `AI_MODEL`, `AI_VISION_MODEL`). Failure or
absence of credentials never breaks compliance workflows: a deterministic bilingual template
engine produces actionable English + Bangla text and the response marks `ai_generated=false`.

Vision OCR reads curved/smudged labels, returns candidate outer/inner serials, then the
Ed25519 verification path decides authenticity. AI never creates signatures or quota.

`AnomalyAnalysisService` detects clone clusters (batch + GPS grid), ghost production attempts
(forged labels grouped by parsed batch), rework gaps and defect clusters (recall/clone density),
then writes/upserts `ai_anomaly_reports` with plain-Bangla supervisor actions.

## 10. Security Controls

- Private key never leaves `.env`; config values never serialized into responses.
- Eloquent/Query Builder prepared statements throughout; validation via FormRequests.
- JWT HS256 with issuer/audience/expiry, unique JTI, logout revocation cache.
- Web: Laravel session regeneration, CSRF on all state-changing forms, password hashing (bcrypt/argon).
- Role policies scope every PO, export, pack, recall, rework and analysis action.
- Rate limiting on login/API/AI/verify/export; CORS allow-list; secure cookie defaults.
- Login blacklist is capped so it can never exceed the 50 KB mobile budget.
- Audit ledgers (`scan_logs`, `recall_events`, `ai_anomaly_reports`) are append-biased.
- Exports are streamed/generated on demand from authorized data; no public serial dumps.
- Error responses hide internals; details are logged server-side.

## 11. Deployment (cPanel / VPS)

1. Upload project; document root → `public/` (root `.htaccess` fallback for shared hosts).
2. PHP 8.2+ with `sodium`, `pdo_mysql`, `mbstring`, `openssl`, `curl`; MySQL 8.
3. `composer install --no-dev --optimize-autoloader`
4. `.env` from `.env.example`; `php artisan key:generate`; `php artisan triproof:keys --write`.
5. `php artisan migrate --force && php artisan db:seed --force` (admin from env).
6. `npm ci && npm run build` locally or deploy committed `public/build`.
7. Cache config/routes/views; cron for `schedule:run` (optional); writable `storage/`, `bootstrap/cache/`.
8. HTTPS + secure session cookies + CORS allow-list + AI keys configured.

## 12. Decisions / Extensions

- **Laravel instead of hand-rolled PHP:** requested by owner; provides hardened PDO usage,
  CSRF, validation, rate limiting, sessions and deployment tooling. Crypto stays native sodium.
- `users.factory_id` extension: the provided users contract lacked factory scoping; needed so
  factory QA authorization cannot be spoofed.
- Supporting `recall_events` table: auditability and durable recall reasons.
- Added pack/list/health/well-known routes beyond the minimum contract to make the dashboard
  and operations complete.
- Redis/queue optional; v1 notifications are written synchronously and AI enrichment may fall
  back deterministically so no worker is required to stay correct.

## 13. Enterprise V2

V2 is additive: v1 unprefixed `K00` serials, the `logs` cross-check envelope, mobile endpoints,
the demo simulator trust root, and the English `O1/I1` sticker sheet remain intact.

- **Key lifecycle:** `signing_keys` stores versioned Ed25519 keys with encrypted private material,
  buyer scoping, and `active|verify_only|revoked` status. `MasterKeyService` resolves the key version
  from the serial; unknown versions fail closed. The key ring is published by well-known/login.
- **DPP:** purchase orders carry canonical `dpp_metadata` and an 8-hex `dpp_digest`. The digest is
  embedded in the signed V2 outer serial, making sustainability claims tamper-evident.
- **Logistics:** cartons aggregate 2-100 units, compute a deterministic Merkle root over
  `unit_hash`, sign `carton_serial|root|count`, and expose unit manifests with inclusion proofs.
- **Offline warning scale:** a binary Bloom filter extends the 50 KB package to a 30,000-entry
  target. It is a probable-match warning, not a verdict. The wire cap forces ~1% FPR; the payload
  reports the estimate rather than claiming a mathematically impossible 0.1% at that budget.
- **Anchoring:** daily `MerkleAnchorService` receipts are signed deterministically with
  `local_ledger` fallback and displayed in the Merkle audit modal.
- **Anti-replay:** device Ed25519 keys, monotonic `scan_seq`, signed sync payloads, and optional
  webhook alerts complement the existing `sync_uuid` idempotency.
- **Smart URLs:** `GET /v/{payload}` gives zero-app consumers a public product/DPP page. Outer QR
  codes contain the URL; inner QR codes contain only the private signature.
