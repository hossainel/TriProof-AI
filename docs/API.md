# TriProof AI REST API (v1 + Enterprise V2/v2.1 additions)

The mobile/partner API is versioned under `/api/v1`. The web console uses Laravel sessions and CSRF separately. This document describes the JSON API, public trust endpoints, and the public smart-URL passport. Cross-reference [Architecture](../ARCHITECTURE.md), the [main README](../README.md), and the [mobile client](../mobile/README.md).

## Base URL and conventions

```text
https://<YOUR_DOMAIN>/api/v1
```

Public trust metadata and passports are served outside the API prefix:

```text
GET /.well-known/triproof-key.json
GET /v/{payload}
```

Send `Accept: application/json` on every API request and `Content-Type: application/json` when a body is present. Times are ISO-8601 UTC. Path parameters `po` are numeric purchase-order IDs. Factory, machine, and batch identifiers are normalized to uppercase where appropriate.

The public trust endpoints (`/.well-known/triproof-key.json` and `/api/v1/blacklist/delta`) require no JWT and allow exact origins configured in `CORS_ALLOWED_ORIGINS`.

## Authentication

```http
Authorization: Bearer <JWT>
```

- Algorithm: HS256 via `firebase/php-jwt`, signed with `JWT_SECRET` (fallback `APP_KEY`).
- TTL: `JWT_TTL_MINUTES`, default 720 minutes.
- Claims: `iss`, `aud`, `sub`, `role`, `iat`, `nbf`, `exp`, `jti`, `typ=access`.
- Logout revokes the current `jti` until expiry.
- Deleted users and role changes invalidate tokens on next use.
- Verification and notification delivery are public or optional-auth surfaces; device tokens identify a scanner and are **not** an authentication factor.

### `POST /auth/login`

Public, throttled at 10 requests/minute/IP.

```json
{
  "email": "buyer@example.com",
  "password": "<YOUR_PASSWORD>",
  "device_token": "scanner-optional-device-token"
}
```

The `device_token` is optional at login and is not stored by this endpoint. It is supplied with scans and notification requests.

Success (`200`) contains JWT metadata, user, verification key material, and the offline package:

```json
{
  "success": true,
  "message": "Authenticated.",
  "data": {
    "token": "<jwt>",
    "token_type": "Bearer",
    "expires_in": 43200,
    "expires_at": "2026-11-20T10:00:00+00:00",
    "user": {
      "id": 4,
      "name": "Global Buyer",
      "email": "buyer@example.com",
      "role": "global_buyer",
      "role_label": "Global Buyer",
      "language_pref": "en",
      "factory_id": null
    },
    "verification": {
      "algorithm": "Ed25519",
      "public_key_base64": "<32-byte-base64-key>",
      "key_fingerprint": "<sha256-hex>",
      "key_id": "<first-32-hex-chars>",
      "configured": true,
      "key_ring": [
        {
          "key_version": "K00",
          "public_key_base64": "<32-byte-base64-key>",
          "status": "active",
          "buyer_id": null,
          "key_fingerprint": "<sha256-hex>",
          "key_id": "<first-32-hex-chars>",
          "algorithm": "Ed25519"
        }
      ],
      "active_keys": [
        {
          "key_version": "K00",
          "public_key_base64": "<32-byte-base64-key>",
          "status": "active"
        }
      ]
    },
    "offline_blacklist": {
      "encoding": "gzip+base64",
      "compression": "gzip",
      "format": "triproof-offline-blacklist/v1",
      "payload": "<base64-gzip-json>",
      "compressed_bytes": 812,
      "uncompressed_bytes": 1540,
      "limit_bytes": 50000,
      "sha256": "<sha256-hex-of-compressed-payload>",
      "generated_at": "2026-11-20T10:00:00+00:00",
      "recalled_batches": 0,
      "cloned_serials": 0,
      "cloned_serials_total": 0,
      "truncated": false,
      "bloom": {
        "bloom_bits_b64": "<base64-bits>",
        "m_bits": 240000,
        "k_hashes": 8,
        "seed": 1406982848,
        "entries": 30000,
        "target_entries": 30000,
        "estimated_fp_rate": 0.0204
      }
    },
    "api_version": "v1"
  }
}
```

Base64-decode `offline_blacklist.payload`, gunzip it, then parse the JSON. The encoded payload never exceeds `limit_bytes` (50,000 by default). `verify_only_keys` are published by the well-known endpoint, not by login.

Invalid credentials return `422` with `error.code = validation_error` and an `email` detail.

### `POST /auth/logout`

Bearer token. Revokes the current token ID.

```json
{"success":true,"message":"Token revoked. Discard it from the device.","data":null}
```

### `GET /auth/me`

Bearer token. Returns the user object plus current key fingerprint and `key_ring`.

## Response conventions

Success:

```json
{"success":true,"message":"Human-readable status.","data":{},"meta":{}}
```

Error:

```json
{
  "success": false,
  "error": {
    "code": "validation_error",
    "message": "Please correct the submitted fields.",
    "details": {"email": ["The provided email or password is incorrect."]}
  }
}
```

Validation failures use `validation_error` with field-keyed details. Business-rule failures use stable snake_case codes.

| HTTP | Meaning |
|---|---|
| 200 | Completed |
| 201 | Resource created |
| 401 | Missing, invalid, expired, revoked, or role-stale token |
| 403 | Authenticated but not authorized |
| 404 | Resource or route not found |
| 409 | Quota/range conflict or retryable scan conflict |
| 422 | Validation or business-rule rejection |
| 429 | Rate limit exceeded |
| 502/503 | AI/vision/crypto dependency unavailable |

Common codes: `insufficient_quota`, `serial_conflict`, `unit_conflict`, `factory_cannot_sign`, `order_not_found`, `batch_not_found`, `nothing_to_pack`, `invalid_batch_scope`, `entries_required`, `vision_ai_unavailable`, `ai_unavailable`, `crypto_unconfigured`, `forbidden`, `scan_seq_replay`, `device_signature_invalid`.

## Rate limits

| Middleware | Default | Applies to |
|---|---:|---|
| `triproof-login` | 10/min | Login |
| `triproof-api` | 120/min | General API and health |
| `triproof-verify` | 600/min | Verification endpoints |
| `triproof-ai` | 20/min | AI analysis |
| `triproof-export` | 30/min | CSV/PDF export |
| `triproof-mcp` | 60/min | MCP JSON-RPC endpoint |

Keys are authenticated user ID when present, otherwise client IP. Overrides: `RATE_LIMIT_LOGIN`, `RATE_LIMIT_API`, `RATE_LIMIT_VERIFY`, `RATE_LIMIT_AI`, `RATE_LIMIT_EXPORT`, `RATE_LIMIT_MCP`.

## Roles and scoping

| Role | Scope |
|---|---|
| `global_buyer` | Own purchase orders; create POs; generate signed batches; export, verify, recall own batches, run scoped AI analysis |
| `factory_qa` | POs for `factory_id`; pack, export, recall own factory batches, reconcile rework, run scoped AI analysis; cannot create POs or sign serials |
| `admin` | All POs, batches, generation, packing, exports, recalls, rework, and analysis |

`PurchaseOrderPolicy` controls `view`, `generate`, `export`, `qrStickers`, and `pack`. Admin may do all. Buyers own by `buyer_id`; factory QA by `factory_id`.

## Endpoint index

| Method | Path | Auth / role | Purpose |
|---|---|---|---|
| GET | `/health` | Public | Dependency health |
| POST | `/auth/login` | Public | JWT, key ring, offline blacklist |
| POST | `/auth/logout` | Bearer | Revoke current token |
| GET | `/auth/me` | Bearer | Identity and key metadata |
| GET | `/blacklist/delta` | Public | Incremental recalled/cloned entries + Bloom filter |
| GET | `/notifications/{device_token}` | Public | Bilingual device inbox |
| POST | `/verify/online-crosscheck` | Optional Bearer | Cloud-authoritative scan cross-check |
| GET | `/offline-blacklist` | Bearer | Fresh offline package |
| POST | `/devices/attest` | Bearer | Register device Ed25519 public key |
| POST | `/cartons/aggregate` | Bearer + PO export rights | Seal master carton |
| GET | `/cartons/{carton_serial}/verify` | Bearer | Carton signature, manifest, proofs |
| POST | `/orders` | Buyer/admin | Create purchase order |
| GET | `/orders` | Bearer | Scoped PO list |
| POST | `/orders/generate-batch` | Buyer/admin | Quota-locked signed batch |
| GET | `/orders/{po}/merkle` | Bearer + PO policy | Merkle audit |
| GET | `/orders/{po}/export` | Bearer + PO policy | CSV or PDF export |
| POST | `/units/pack-batch` | Factory/admin | Mark units packed |
| POST | `/recall/trigger` | Buyer/factory/admin | Precision batch recall |
| POST | `/rework/reconcile` | Factory/admin | Rework lineage |
| POST | `/notifications/mark-read` | Bearer | Mark notices read |
| POST | `/verify/transfer-ticket` | Bearer | Ownership transfer ticket |
| POST | `/ai/analyze` | Bearer + AI throttle | Anomaly analysis and optional OCR |
| POST | `/ai/ask-docs` | Bearer + AI throttle | Grounded compliance/docs Q&A |
| POST | `/mcp` | JWT `mcp:read` or API key | Five read-only MCP tools |
| GET | `/.well-known/triproof-key.json` | Public | Public key ring and trust contract |
| GET | `/v/{payload}` | Public web | Product passport page |

## Orders

### `GET /orders`

Bearer token. Returns up to 50 newest visible orders with counts and pagination.

```json
{
  "success": true,
  "data": {
    "orders": [
      {
        "id": 12,
        "po_number": "PO-2026-0042",
        "buyer": {"id": 4, "name": "Global Buyer", "email": "buyer@example.com"},
        "factory_id": "DHK04",
        "authorized_quota": 10000,
        "generated_count": 300,
        "remaining_quota": 9700,
        "factory_expiry_days": 45,
        "retail_expiry_days": 365,
        "merkle_root_hash": "<64-char-sha256-hex>",
        "counts": {"serials": 300, "packed": 300, "claimed_genuine": 12, "cloned_flagged": 1, "recalled": 0},
        "created_at": "2026-11-20T10:00:00+00:00"
      }
    ],
    "pagination": {"current_page": 1, "per_page": 50, "total": 1, "last_page": 1}
  }
}
```

### `POST /orders`

Buyer or admin.

```json
{
  "po_number": "PO-2026-0042",
  "buyer_id": 4,
  "factory_id": "DHK04",
  "authorized_quota": 10000,
  "factory_expiry_days": 45,
  "retail_expiry_days": 365
}
```

- `po_number` is optional; a unique value is generated when omitted.
- `buyer_id` defaults to the caller for buyers and is required for admins.
- `factory_id` must reference an active factory code.
- `authorized_quota` is the maximum number of units that may ever be signed.

Returns `201` with `data.order`. Duplicate PO numbers return `409 po_exists`.

### `POST /orders/generate-batch`

Buyer who owns the PO or admin.

```json
{
  "po_id": 12,
  "machine_id": "M01",
  "batch_id": "B01",
  "count": 3,
  "unit_start": 1,
  "prod_date": "2026-11-20",
  "include_units": true
}
```

- `count` is 1–`MAX_BATCH_GENERATE` (default 50,000).
- `unit_start` is optional; omitted values allocate the next free number.
- `prod_date` defaults to today and may not be in the future.
- `include_units` defaults to true only for small counts.
- Generation is transactional and quota-locked; over-quota returns `409 insufficient_quota`, overlaps return `409 unit_conflict` / `serial_conflict`.
- Factories receive `403 factory_cannot_sign`.

Returns `201` with `data.generated_count`, `unit_start`, `unit_end`, `merkle_root`, `exports`, and optional `units`.

### `GET /orders/{po}/merkle`

Returns `stored_root`, `recomputed_root`, `matches`, `leaf_count`, `generated_count`, `consistent`, `algorithm`, `blockchain_ready`, `anchoring_status`, and `verified_at`. `anchoring_status` is currently `pending_phase_2` for compatibility even though local anchor receipts exist.

### `GET /orders/{po}/export`

Query parameters: `format=csv|pdf` (default `csv`) and optional `batch`. CSV is streamed; PDF is capped by `MAX_PDF_UNITS`. Binary bodies are returned rather than JSON.

### `POST /units/pack-batch`

Factory QA assigned to the PO factory, or admin.

```json
{"po_id":12,"machine_id":"M01","batch_id":"B01","unit_start":1,"unit_end":3}
```

Only `generated` units are packed. An empty match returns `422 nothing_to_pack` with available batch ranges. Response: `data.po_id`, `data.batch`, `data.packed_count`.

## Verification

### `POST /verify/online-crosscheck`

Public/optional Bearer. Anonymous callers are treated as a `GlobalBuyer` actor. Single scan:

```json
{
  "sync_uuid": "7b4e2f18-4c62-4a4e-9d3f-1f8b4f6f2b7a",
  "outer_serial": "DHK04-M01-B01-000001|20261120|20271120",
  "inner_serial": "<base64-ed25519-signature>",
  "device_token": "scanner-17",
  "scan_mode": "online_live",
  "gps_lat": 23.8103,
  "gps_lng": 90.4125,
  "scanned_at": "2026-11-20T10:05:00+00:00",
  "offline_verdict": "verified_clean",
  "scan_seq": 14,
  "device_signature": "<base64-ed25519-device-signature>",
  "contact_hash": "<sha256-hex-optional>"
}
```

Batched requests use `logs: [...]` with distinct `sync_uuid` values. Responses use `data.results`, `data.feedback_notifications`, and `data.summary`. Replaying a `sync_uuid` returns the stored result with `duplicate: true`.

Payloads are canonicalized before verification: raw `/v/{OUTER_SERIAL}` smart URLs and `TP1:`-prefixed inner payloads are normalized to the canonical outer serial and compared before Ed25519 verification. The offline verifier runs the same 7-stage deterministic state machine: normalize, parse, resolve key ring, verify signature, registry lookup, recall/expiry/clone flags, and scan-history replay check. The MCP `verify_serial` tool exposes this same engine.

Verdict precedence:

| Verdict | Meaning |
|---|---|
| `forged_signature` | Invalid signature, hash mismatch, or unknown serial |
| `recalled_batch` | Unit belongs to a recalled batch |
| `expired` | Expiry date has passed |
| `verified_clean` | Authentic and claimed by this device now |
| `owner_repeat_ok` | Same device scanned again |
| `store_grace_ok` | Different device inside 5-minute / 100-metre grace |
| `clone_detected` | Different device outside grace; serial flagged and both devices notified |

### `POST /verify/transfer-ticket`

Bearer. Current owner submits `outer_serial`, `inner_serial`, and `device_token`. Returns `data.ticket` and `expires_at` (24 hours). Issuing another ticket replaces the prior one. Recipient supplies optional `transfer_ticket` during an ordinary cross-check; redemption is single-use under the registry lock.

## Blacklist and notifications

### `GET /blacklist/delta`

Public. Returns incremental entries and a Bloom filter:

```json
{
  "success": true,
  "data": {
    "entries": [
      {"scope": "batch", "value": "DHK04-M01-B01", "reason": "recalled_batch"}
    ],
    "bloom_filter": {
      "bits_b64": "<base64-bits>",
      "m_bits": 240000,
      "k_hashes": 8,
      "seed": 1406982848,
      "entries": 30000,
      "estimated_fp_rate": 0.0204
    },
    "meta": {"generated_at": "2026-11-20T10:00:00+00:00", "limit_bytes": 50000}
  }
}
```

A Bloom miss is a definitive negative. A Bloom hit is a probable match and must still be checked against the exact cached blacklist.

### `GET /notifications/{device_token}`

Public. Device token must match `^[A-Za-z0-9._:-]{1,128}$`. Query parameters: `per_page` (1–100), `unread_only`. Returns bilingual `title_en/message_en/title_bn/message_bn`, severity, read state, and pagination.

### `POST /notifications/mark-read`

Bearer. Provide `ids` or `mark_all=true`; only unread rows for that device are updated. Response includes `marked_read`.

## Cartons, rework, recall, AI

### `POST /devices/attest`

Bearer. Registers a Base64 Ed25519 device public key and optional Play Integrity verdict. Attested devices must sign scan payloads with monotonic `scan_seq`.

### `POST /cartons/aggregate`

Bearer plus PO export rights. Seals 2–100 units with a deterministic Merkle root and signed `qr_payload`:

```text
CTN-...|{merkle_root}|{unit_count}|{signature}
```

### `GET /cartons/{carton_serial}/verify`

Bearer. Returns signature validity, manifest, and Merkle inclusion proofs for each unit.

### `POST /rework/reconcile`

Factory/admin. Accepts up to 1,000 `entries` with `parent_outer_serial`, `child_outer_serial`, action, line, note, and optional OCR image. Links valid same-factory pairs idempotently. Result statuses: `linked`, `already_linked`, `gap`, `rejected`. Gaps create open anomaly reports.

### `POST /recall/trigger`

Buyer/factory/admin.

```json
{"factory_id":"DHK04","machine_id":"M01","batch_id":"B01","reason":"Seal integrity failure."}
```

Factories are limited to their own factory; buyers to their own POs. Matching units become `recalled`, devices are notified, and a `recall_events` row is written.

### `POST /ai/analyze`

Bearer, AI throttle. Optional `po_id`, `batch_id`, rework `entries`, and `image_base64`. Anomaly analysis always runs; without AI credentials it uses deterministic bilingual templates. Vision OCR requires a configured provider and otherwise returns `503 vision_ai_unavailable`. Reports use types `clone_cluster`, `ghost_production_attempt`, `rework_gap`, and `defect_cluster`.

## Health and trust metadata

### `GET /health`

Public. Returns dependency status for database, sodium compatibility, signing keys, AI mode, and blacklist gzip.

### `GET /.well-known/triproof-key.json`

Public. Publishes the issuer, algorithm, current public key aliases, fingerprint, key ID, full key ring, active/verify-only keys, rotation policy, serial contract, and offline trust model. The configured legacy `K00` key is always present: it is `active` when `signing_keys` has no active row and `verify_only` once a DB-backed key is active.

```json
{
  "success": true,
  "data": {
    "issuer": "TriProof AI",
    "algorithm": "Ed25519",
    "public_key_base64": "<32-byte-base64-key>",
    "public_key": "<32-byte-base64-key>",
    "universal_public_key": "<32-byte-base64-key>",
    "key_fingerprint": "<sha256-hex>",
    "key_id": "<first-32-hex-chars>",
    "key_rotation_months": 6,
    "key_ring": [
      {
        "key_version": "K00",
        "status": "active",
        "buyer_id": null,
        "public_key_base64": "<base64>",
        "key_fingerprint": "<sha256-hex>",
        "key_id": "<first-32-hex-chars>",
        "algorithm": "Ed25519"
      }
    ],
    "active_keys": [
      {"key_version": "K00", "status": "active", "public_key_base64": "<base64>"}
    ],
    "verify_only_keys": [],
    "serial_format": "{KEY_VER}-{FACTORY}-{MACHINE}-{BATCH}-{UNIT:06d}|{PROD_DATE}|{EXP_DATE}|DPP:{DPP_DIGEST}",
    "legacy_serial_format": "{FACTORY}-{MACHINE}-{BATCH}-{UNIT:06d}|{PROD_DATE}|{EXP_DATE}",
    "inner_serial_format": "Base64(Ed25519 detached signature of outer serial)",
    "unit_hash": "SHA-256(outer_serial + \"|\" + inner_serial)",
    "offline_trust_model": "Verify the Ed25519 signature locally using the versioned key ring; cloud cross-check establishes ownership and recall state."
  }
}
```

### Mobile trust sync flow (v2.1)

The Flutter verifier performs this flow without a JWT:

1. Normalize the configured URL (adds `https://` when omitted, removes a trailing slash or `/api/v1`).
2. `GET /.well-known/triproof-key.json`; accept keys from `key_ring`, `active_keys`, `verify_only_keys`, and top-level `public_key_base64` / `public_key` / `universal_public_key` aliases.
3. Persist valid 32-byte Ed25519 keys in SQLCipher `app_meta` (`synced_key_ring_json`) and provision them into Android Keystore / iOS Keychain.
4. `GET /api/v1/blacklist/delta`; replace exact entries and persist `bloom_filter` when present.
5. Persist `server_base_url` and `last_trust_sync_at`; verification then tries all cached active/verify-only keys before the compile-time fallback.

Sync runs automatically at app initialization and when connectivity returns, and manually through the bilingual **Update Offline Data from Website** card. Network requests use a 10-second timeout and the blacklist payload is bounded by the mobile 50 KB budget.

### `GET /v/{payload}`

Public web page. Renders a product passport and DPP scorecard from a signed outer serial delivered by QR or smart URL. The outer QR contains `{APP_URL}/v/{OUTER_SERIAL}`; the inner QR contains only the private signature.

## Enterprise V2 notes

- Legacy `K00` serials remain unprefixed. V2 serials may start with `KNN-` and end with `|DPP:{8-HEX-DIGEST}`; the exact string is signed.
- Rotate non-disruptively with `php artisan triproof:keys:rotate --key-version=K02 [--buyer=ID]` (positional version also accepted). Retired keys remain `verify_only`.
- DPP metadata is canonicalized and `ksort`ed before computing `dpp_digest = strtoupper(substr(sha256(json),0,8))`.
- Carton verification returns Merkle inclusion proofs over unit hashes.
- The offline Bloom filter targets 30,000 entries within 240,000 bits and reports its measured false-positive rate (~2% at the cap). It is a warning, never a final verdict.
- Device attestation adds signed monotonic `scan_seq`, `device_signature`, and optional `contact_hash`; replay is rejected with `409 scan_seq_replay`.
- Local Merkle anchor receipts use `TRIPROOF-ANCHOR|{date}|{root}|{poIds}`; the API compatibility field remains `pending_phase_2`.
- v2.1 mobile trust sync consumes the public key-ring and blacklist-delta endpoints directly; installing a new build is not required when the trusted key ring rotates.
- v2.1 verification accepts raw `/v/{OUTER_SERIAL}` smart URLs and `TP1:`-prefixed inner payloads, then compares the canonical outer serial before Ed25519 verification.

## MCP & grounded documentation Q&A (v2.1)

All endpoints below require either `Authorization: Bearer <JWT>` with the `mcp:read` scope or `X-TriProof-Api-Key`, and are rate limited. They are strictly read-only and never expose private signing material. See [docs/MCP.md](MCP.md) for transports and the standalone bridge.

### `POST /api/v1/mcp`

Native JSON-RPC 2.0 MCP server. Supported methods:

- `initialize` — advertises `triproof-ai-mcp` v2.1.0 and tool capabilities.
- `tools/list` — returns five tools: `verify_serial`, `get_batch_anomalies`, `trace_rework_lineage`, `check_recall_status`, `get_merkle_proof`.
- `tools/call` — invokes a tool with `params: { "name": "…", "arguments": { … } }` and returns an MCP content result with `isError`.

Tools run the deterministic 7-stage verification state machine, query bilingual anomaly reports, traverse rework/carton lineage, check recall/blacklist state, and compute SHA-256 Merkle inclusion proofs with daily anchor receipts.

```bash
curl -sS -X POST "$BASE/mcp" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"verify_serial","arguments":{"payload_or_serial":"K00-DHK04-M01-B01-000001|20261120|20271120"}}}' | jq
```

### `POST /api/v1/ai/ask-docs`

Contextual + semantic-chunked RAG over `README.md`, `ARCHITECTURE.md`, `docs/API.md`, visible `doc_sections`, recent anomaly reports, and recall events. Request body:

```json
{ "question": "How is quota enforced?", "limit": 5 }
```

The response includes `answer_en`, `answer_bn`, `citations`, and the grounded `chunks`. When `AI_PROVIDER=none` (or the provider call fails), a deterministic citation-backed answer is returned with `ai_used: false`.

## cURL quick start

```bash
BASE="https://<YOUR_DOMAIN>/api/v1"

TOKEN=$(curl -sS -X POST "$BASE/auth/login" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"buyer@example.com","password":"<YOUR_PASSWORD>"}' \
  | jq -r '.data.token')

curl -sS "$BASE/orders" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | jq

curl -sS -X POST "$BASE/verify/online-crosscheck" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"sync_uuid":"7b4e2f18-4c62-4a4e-9d3f-1f8b4f6f2b7a","outer_serial":"DHK04-M01-B01-000001|20261120|20271120","inner_serial":"<base64-signature>","device_token":"scanner-17"}' | jq

curl -sS "$BASE/orders/12/export?format=csv&batch=B01" \
  -H "Authorization: Bearer $TOKEN" -o batch-B01.csv
```
