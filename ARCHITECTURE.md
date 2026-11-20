# TriProof AI — System Architecture

> Canonical architecture and data-model reference. Pair it with [README.md](README.md), [CHECKPOINT.md](CHECKPOINT.md), [API reference](docs/API.md), [mobile client](mobile/README.md), and [schema.sql](schema.sql). Every material change must be reflected here.

## 1. Mission

TriProof AI is a supply-chain authenticity platform for global buyers, factories, administrators, station verifiers, and consumers. It addresses eight concrete risks:

1. Outer-box swapping and counterfeiting
2. Unreported ghost overproduction
3. Broad, imprecise product recalls
4. Broken serial lineage during factory rework
5. Verification in internet dead zones
6. Formula / label reverse engineering
7. Smudged or curved-label scanning
8. English-to-Bangla compliance gaps on the factory floor

The design keeps signing authority centralized, verification portable, business rules in one service layer, and all state transitions auditable.

## 2. Technology stack

| Layer | Choice |
|---|---|
| Backend | Laravel 12, PHP 8.2+ (validated on 8.4), Eloquent, FormRequests, Policies |
| Frontend | Blade, Tailwind CSS 4, Vite 7, vanilla JS, Chart.js, Leaflet |
| Database | MySQL 8+ / MariaDB 10.6+, `utf8mb4`, prepared statements via Eloquent/Query Builder |
| Crypto | Ed25519 via native sodium or `paragonie/sodium_compat`; SHA-256; HS256 JWT via `firebase/php-jwt` |
| Exports | Dompdf, `chillerlan/php-qrcode`, streamed CSV |
| AI | OpenAI-compatible / Gemini HTTP clients with deterministic bilingual fallback |
| Mobile | Flutter 3.50+, Dart 3.12+, SQLCipher, ML Kit, geolocator, connectivity_plus |
| Hosting | cPanel shared hosting or VPS; zero-SSH `public/cpanel-setup.php` installer |

No SPA framework is used. Server-rendered Blade views share the same services as the JSON API.

## 3. Request flow

```text
public/index.php
  └─ bootstrap/app.php
      ├─ routes/web.php
      │   ├─ session auth + CSRF + role middleware
      │   ├─ Blade dashboards, admin directory, verifier, public pages
      │   └─ web forms → FormRequests → controllers → services → Eloquent
      └─ routes/api.php  (prefix: /api/v1)
          ├─ public / optional / JWT middleware + throttles
          ├─ JSON controllers → FormRequests → policies → services → Eloquent
          └─ ApiResponse envelope + bootstrap JSON exception mapping
```

`bootstrap/app.php` registers `SetLocale`, `SecurityHeaders`, `AuthenticateApi`, `OptionalApiAuth`, and the `role` middleware. Merchant/live environments enable `DB::prohibitDestructiveCommands()` and force HTTPS URL generation. The scheduler runs `triproof:anchor-merkle` daily at 01:30.

## 4. Directory map (key paths)

```text
app/
  Console/Commands/
    GenerateSigningKeys.php          # triproof:keys
    RotateSigningKey.php             # triproof:keys:rotate
    AnchorMerkle.php                 # triproof:anchor-merkle
  Http/
    Controllers/Api/*                # auth, orders, verification, cartons, blacklist, alerts
    Controllers/Web/*                # dashboard, admin directory, orders, verifier, public pages
    Middleware/AuthenticateApi.php
    Middleware/OptionalApiAuth.php
    Middleware/EnsureRole.php
    Middleware/SetLocale.php
    Middleware/SecurityHeaders.php
    Requests/*                       # web + API validation
  Models/                            # User, Factory, PurchaseOrder, SerialRegistry, ScanLog,
                                     # RecallEvent, SigningKey, CartonAggregation, CartonUnit,
                                     # BlockchainAnchor, DeviceAttestation, SystemSetting, ...
  Policies/PurchaseOrderPolicy.php
  Services/
    Crypto/MasterKeyService.php
    Crypto/SerialCryptoService.php
    Crypto/MerkleService.php
    Auth/JwtService.php
    PurchaseOrderService.php
    BatchGenerationService.php
    PackingService.php
    VerificationService.php
    RecallService.php
    ReworkService.php
    OfflineBlacklistService.php
    DppService.php
    CartonAggregationService.php
    MerkleAnchorService.php
    OwnershipTransferService.php
    Ai/AiClient.php
    Ai/AnomalyAnalysisService.php
  Support/ApiResponse.php
  Support/BloomFilter.php
bootstrap/app.php
config/triproof.php
database/migrations/*
database/seeders/*
resources/
  css/app.css                        # Tailwind 4 + dialog lifecycle CSS
  js/app.js                          # charts, maps, modals, pack suggestions, i18n hooks
  views/layouts/app.blade.php        # guarded global modal auto-open
  views/partials/dialogs.blade.php   # dashboard dialogs + packable JSON
  views/admin/partials/dialogs/*     # admin add/edit dialogs
  views/dashboard/*, verify/*, passport/*, admin/*
routes/web.php, routes/api.php
public/cpanel-setup.php              # self-cleaning zero-SSH installer
schema.sql
docs/API.md
mobile/                              # Flutter verifier
```

## 5. Data model

Migrations are authoritative; `schema.sql` is their DDL mirror. The domain tables are:

| Table | Purpose and key fields |
|---|---|
| `users` | `name`, `email`, custom `password_hash`, `role` (`global_buyer`, `factory_qa`, `admin`), `language_pref`, nullable `factory_id` |
| `factories` | `code` unique, `name_en`, `name_bn`, `location`, `contact_email`, `is_active`; deletion guarded by PO/serial/user references |
| `purchase_orders` | `po_number` unique, `buyer_id` FK, `factory_id`, `authorized_quota`, `generated_count`, `factory_expiry_days`, `retail_expiry_days`, `merkle_root_hash`, `dpp_metadata`, `dpp_digest` |
| `serial_registry` | One signed unit: `outer_serial` unique, `inner_serial`, `unit_hash` unique, factory/machine/batch/unit coordinates, dates, `status`, claim token/time/GPS, `rework_parent_serial` |
| `scan_logs` | Append-only verification ledger keyed by `sync_uuid`; offline/cloud verdicts, GPS, device, scan sequence |
| `notifications` | Bilingual device inbox with severities `info`, `warning`, `critical_clone`, `recall_alert` |
| `ai_anomaly_reports` | Clone/ghost/rework/defect findings with bilingual explanations and affected-unit JSON |
| `recall_events` | Precision recall audit with factory/machine/batch, affected count, reason, trigger user |
| `system_settings` | Key/value platform metadata and compliance notices |
| `signing_keys` | Versioned Ed25519 key ring: public key, encrypted private key, `active|verify_only|revoked`, buyer scope |
| `carton_aggregations` | Sealed carton serial, PO, factory, batch, unit count, Merkle root, signature, logistics status |
| `carton_units` | Unit-to-carton mapping with leaf index; a serial may belong to only one carton |
| `blockchain_anchors` | Date, batch Merkle root, PO IDs, provider, receipt, status |
| `device_attestations` | Device token PK, public key, Play Integrity verdict, monotonic `last_scan_seq` |
| Laravel infrastructure | `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` |

Relationships: buyer `users` 1—N `purchase_orders`; PO 1—N `serial_registry`, `carton_aggregations`, `ai_anomaly_reports`, `recall_events`; serial 1—N `scan_logs` by `outer_serial`; serial 1—1 `carton_units`. `config/auth.php` references a `password_reset_tokens` table that is not created by migrations; no password-reset flow is wired.

Indexes include unique `po_number`, `outer_serial`, `unit_hash`, `sync_uuid`, `carton_serial`, `key_version`, and composite indexes over `(factory_id, machine_id, batch_id)`, `(po_id, status)`, `(device_token, is_read, created_at)`, and `(outer_serial, scanned_at)`.

## 6. Cryptography

### Key lifecycle

- `MasterKeyService` resolves keys from `signing_keys`, preferring buyer-specific active keys and falling back to the global active key.
- Private material is encrypted with Laravel `Crypt`; key pairs are validated by deriving the public key and using `hash_equals`.
- Legacy `K00` uses `HQ_PRIVATE_SIGNING_KEY` / `UNIVERSAL_PUBLIC_VERIFICATION_KEY`.
- `php artisan triproof:keys --write` bootstraps keys and JWT secret; `triproof:keys:rotate --key-version=K02 [--buyer=ID]` retires old keys to `verify_only`.
- Public metadata, fingerprints, rotation policy, and the key ring are published at `GET /.well-known/triproof-key.json`. Login and `/auth/me` return `key_ring` and `active_keys`; `verify_only_keys` are published by well-known.

### Serial contract

```text
K00 / legacy:
  {FACTORY}-{MACHINE}-{BATCH}-{UNIT:06d}|{PROD_DATE}|{EXP_DATE}

Enterprise V2:
  {KNN}-{FACTORY}-{MACHINE}-{BATCH}-{UNIT:06d}|{PROD_DATE}|{EXP_DATE}|DPP:{8-HEX-DIGEST}

inner_serial = Base64( Ed25519 detached signature over the exact outer_serial )
unit_hash    = SHA-256( outer_serial . "|" . inner_serial )
```

Dates accept `YYYY-MM-DD` or `YYYYMMDD`. Factory IDs are 2–16 characters; machine and batch IDs are 1–16. Factory expiry days define the packing SLA; retail expiry days define label expiry.

### Quota-locked signing transaction

1. Begin a MySQL transaction and `SELECT ... FOR UPDATE` the purchase order.
2. Reject when `count > authorized_quota - generated_count`.
3. Allocate explicit or next-free unit numbers for the factory/machine/batch scope.
4. Sign exactly N outer serials and insert rows; unique indexes are a second defense.
5. Recompute the Merkle root over ordered `unit_hash` leaves.
6. Update `generated_count` and `merkle_root_hash`, then commit.

Factories never hold the HQ private key. Expired factory windows create compliance warnings; they never authorize extra production.

### Merkle trees and anchoring

`MerkleService` builds deterministic SHA-256 binary trees in insertion order. Odd nodes promote; parent nodes hash `0x01 || left || right` as binary. `verifyProof()` checks inclusion with `hash_equals`.

`MerkleAnchorService` builds a daily batch root over PO roots and signs `TRIPROOF-ANCHOR|{date}|{root}|{poIds}` with the active local key. Receipts are stored in `blockchain_anchors` and displayed in the Merkle audit modal. External providers remain `pending`; API and web Merkle endpoints still report `anchoring_status: pending_phase_2` for compatibility.

### Offline package and Bloom filter

Login and `GET /offline-blacklist` return a gzip+Base64 JSON package capped at 50,000 bytes. It contains recalled batches, cloned serials, the public key ring, and a binary Bloom filter targeting 30,000 entries. The configured bit cap is `OFFLINE_BLOOM_MAX_BITS=240000`; at 30,000 entries the measured false-positive rate is about 2%, not 0.1%. A Bloom hit is a probable-match warning only; exact cached blacklist lookup decides. `GET /blacklist/delta` returns incremental entries plus a separate `bloom_filter` object.

## 7. Web frontend architecture

- Layout computes `$openDialog` only when validation errors exist and `old('dialog_id')` or `session('open_dialog')` is present; no dialog is hardcoded open.
- `dialog.tp-dialog:not([open])` is hidden; `dialog.tp-dialog[open]` is centered with z-index 60, a backdrop, and a scrollable `.tp-dialog-panel`.
- `resources/js/app.js` uses null-safe `byId`, removes stale `open` attributes, calls `showModal()` with fallback, closes via backdrops/Escape/`data-close-dialog`, and closes forms on submit.
- Triggers populate dynamic state from `data-action`, `data-context`, `data-remaining`, `data-order-id`, `data-factory`, `data-machine`, `data-batch`, and `data-merkle-url`.
- Each form defines `$isCurrentDialog = old('dialog_id') === '<dialog-id>'` and scopes `old()` values to that dialog; sibling dialogs never receive leaked input.
- Admin dialogs include hidden `active_tab` values. Successful admin writes use `back()->with('status')->with('active_tab')` without old input/dialog flags; validation failures use `back()->withErrors()->withInput()`.
- Successful order workflows flash both `status` and `success`; the dashboard flash partial displays `success`.
- Sidebar hash navigation targets dashboard sections and admin tabs; locale is EN / বাংলা through `SetLocale` and session preference.

## 8. Verification state machine

For each scan keyed by `sync_uuid`:

1. Replayed sync UUID returns the stored result with `duplicate: true`.
2. Attested devices provide monotonic `scan_seq`, `device_signature` over `{syncUuid}|{scanSeq}|{outer}`, and optional `contact_hash`. Replay or invalid signatures are rejected.
3. The serial row is locked with `lockForUpdate()`.
4. Ed25519 verification and registry hash comparison decide authenticity. Unknown or forged labels produce `forged_signature`.
5. Verdict precedence: `forged_signature` → `recalled_batch` → `expired` → first claim `verified_clean` → same-device `owner_repeat_ok` → store grace `store_grace_ok` (≤300 seconds and ≤100 metres) → `clone_detected`.
6. Clones flag the serial, notify both devices, create bilingual in-app alerts and an optional webhook, and upsert an AI anomaly report.

Web `/verify` is read-only inspection; it never claims ownership. Cloud cross-check is authoritative for claim, recall, expiry, and clone state.

## 9. API surface

All routes are under `/api/v1` unless noted. Full request/response detail lives in [docs/API.md](docs/API.md).

| Method | Path | Auth / role | Purpose |
|---|---|---|---|
| GET | `/health` | Public | Dependency health |
| POST | `/auth/login` | Public | JWT, key ring, offline blacklist |
| POST | `/auth/logout` | JWT | Revoke JTI |
| GET | `/auth/me` | JWT | Identity and key metadata |
| GET | `/blacklist/delta` | Public | Incremental blacklist + Bloom filter |
| GET | `/notifications/{deviceToken}` | Public | Device inbox |
| POST | `/verify/online-crosscheck` | Optional JWT | Cloud cross-check |
| GET | `/offline-blacklist` | JWT | Fresh offline package |
| POST | `/devices/attest` | JWT | Register device Ed25519 key |
| POST | `/cartons/aggregate` | JWT | Seal master carton |
| GET | `/cartons/{serial}/verify` | JWT | Carton signature, manifest, proofs |
| POST | `/orders`, `/orders/generate-batch` | Buyer/admin | PO creation and signing |
| GET | `/orders`, `/orders/{po}/merkle`, `/orders/{po}/export` | JWT + PO policy | List, audit, CSV/PDF export |
| POST | `/units/pack-batch` | Factory/admin | Mark units packed |
| POST | `/recall/trigger` | Buyer/factory/admin | Precision recall |
| POST | `/rework/reconcile` | Factory/admin | Rework lineage |
| POST | `/notifications/mark-read` | JWT | Mark notices read |
| POST | `/verify/transfer-ticket` | JWT | Ownership transfer ticket |
| POST | `/ai/analyze` | JWT + throttle | AI/Anomaly analysis |
| GET | `/.well-known/triproof-key.json` | Public | Key ring and trust contract |
| GET | `/v/{payload}` | Public | Smart-URL product passport |

Web route groups mirror these capabilities with session auth and role middleware: `/dashboard`, `/verify`, `/admin/manage`, `/orders/*`, `/recall`, and `/rework/*`.

## 10. AI layer

`AiClient` supports OpenAI-compatible chat completions and Gemini `generateContent`. Missing credentials or provider errors fall back to deterministic bilingual templates, marking output as not AI-generated. Vision OCR is optional and only returns candidate serials; Ed25519 verification remains authoritative. `AnomalyAnalysisService` writes and upserts `ai_anomaly_reports` for clone clusters, ghost production attempts, rework gaps, and defect clusters with plain-Bangla supervisor actions.

## 11. Mobile offline verification pipeline

The Flutter client lives in [`mobile/`](mobile/README.md). Its pipeline is:

1. Clock-rollback guard using a persisted high-water mark advanced by GPS time, successful scans, and server sync.
2. Smart-payload parsing for `/v/...` URLs, `KNN-` prefixes, and `|DPP:XXXXXXXX` suffixes.
3. Pure-Dart Ed25519 verification in-isolate using the embedded universal key or provisioned key ring.
4. Expiry and 45-day factory QA threshold checks.
5. Cached blacklist and Bloom-filter warning path.
6. Session duplicate and prior-owner cache checks.

`DatabaseHelper` opens SQLCipher database `triproof_scanner_v1.db` (version 2) with a 256-bit key from `flutter_secure_storage`. Tables are `scan_history`, `cached_blacklist`, `notifications_inbox`, and `app_meta`. The in-memory mirrors are rebuilt on open and invalidated on writes.

- `deleteScanRecord(syncUuid)` deletes one scan and invalidates `_sessionSerialCache` and `_priorOwnerScanCache`.
- `clearScanHistory()` deletes every scan row and invalidates the same caches. The UI exposes this as **Clear All History**.
- Both actions use bilingual `AlertDialog` confirmations in `scan_history_screen.dart`: Cancel/Delete, `confirmDeleteItem`, `confirmClearAll`, `historyDeleted`, and `historyCleared`.

Connectivity transitions trigger store-and-forward sync: upload pending scans with `scan_seq`, provision the key ring, fetch notifications, blacklist deltas, Bloom filter, and advance the clock high-water mark from `server_time`.

## 12. Security controls

- Private keys never leave `.env` / encrypted `signing_keys`; no secrets in API responses, exports, logs, or Markdown.
- Web: CSRF, sessions, password hashing, role middleware, scoped old input, explicit modal reopening.
- API: HS256 JWT with issuer/audience/JTI/expiry checks and revocation cache.
- Authorization centralized in policies and services; resources scoped by buyer/factory/admin.
- Row locks and unique constraints protect quota and serial uniqueness.
- Verification limits, rate limits, export throttles, and a capped offline package protect device/server workloads.
- Errors use a stable JSON envelope and hide internals; exceptions are logged server-side.

## 13. Deployment

Zero-SSH cPanel workflow:

1. Upload `triproof-cpanel-deploy.zip` (web app only; excludes `mobile/` and `node_modules/`).
2. Extract into the web root or an app directory with `public/` as document root.
3. Configure `.env` with placeholders for database credentials, domain, and public key.
4. Make `storage/`, `storage/framework/*`, `bootstrap/cache/`, and `public/uploads/` writable.
5. Open `/cpanel-setup.php` once. It generates keys, writes a locked `.env`, runs migrations and seeders, and self-deletes.
6. Remove setup scripts manually if the host prevents self-deletion, then verify health, key discovery, login, dashboards, and a scan.

VPS deployments run the equivalent Composer, npm, key, migration, seeder, and optimize commands manually. Schedule `php artisan schedule:run` every minute for daily anchoring.

## 14. Decisions and extensions

- Laravel provides hardened PDO usage, validation, CSRF, sessions, rate limiting, and deployment tooling; crypto remains portable through `paragonie/sodium_compat`.
- `users.factory_id` exists because the original user contract lacked factory scoping.
- `recall_events` and `scan_logs` provide durable audit trails.
- Notifications are written synchronously; queues are optional for scaling.
- V2 is additive: legacy `K00` serials, the `logs` cross-check envelope, mobile endpoints, demo trust root, and English `O1/I1` sticker sheets remain compatible.
- Key rotation, DPP digests, carton Merkle proofs, offline Bloom warnings, device attestation, local anchors, webhooks, and smart-URL passports extend V1 without breaking existing clients.
