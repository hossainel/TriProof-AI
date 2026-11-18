# TriProof AI REST API (v1)

The mobile/partner API is versioned under `/api/v1`. The web application uses Laravel sessions
and CSRF separately; this document covers the token-authenticated JSON API.

## Base URL

```
https://your-domain.example/api/v1
```

Public trust metadata is also served outside the API prefix:

```
GET /.well-known/triproof-key.json
```

Send `Accept: application/json` on every request and `Content-Type: application/json` when a
body is present.

## Authentication

Obtain a JWT with `POST /auth/login`. Use it as a Bearer token:

```
Authorization: Bearer <token>
```

- Algorithm: HS256, signed with `JWT_SECRET` (falls back to `APP_KEY`).
- TTL: `JWT_TTL_MINUTES` (default 720 minutes).
- Claims include `iss`, `aud`, `sub`, `role`, `iat`, `nbf`, `exp`, `jti`, and `typ=access`.
- Logout revokes the current `jti` in the cache until the token expires.
- A role change or deleted account invalidates the token on its next use.

### Login

`POST /auth/login` — public, throttled at 10 requests/minute/IP.

```json
{
  "email": "buyer@example.com",
  "password": "your-password",
  "device_token": "android-optional-device-token"
}
```

`device_token` is optional at login and is not stored by the login endpoint. It is supplied on
scans and notification requests.

Successful response (`200`):

```json
{
  "success": true,
  "message": "Authenticated.",
  "data": {
    "token": "<jwt>",
    "token_type": "Bearer",
    "expires_in": 43200,
    "expires_at": "2026-11-19T10:00:00+00:00",
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
      "public_key_base64": "<32-byte base64 key>",
      "key_fingerprint": "<sha256 hex>",
      "configured": true
    },
    "offline_blacklist": {
      "encoding": "gzip+base64",
      "compression": "gzip",
      "payload": "<base64 gzip>",
      "compressed_bytes": 812,
      "uncompressed_bytes": 1540,
      "limit_bytes": 50000,
      "sha256": "<sha256 hex of compressed payload>",
      "generated_at": "2026-11-18T10:00:00+00:00",
      "recalled_batches": 0,
      "cloned_serials": 0,
      "cloned_serials_total": 0,
      "truncated": false
    },
    "api_version": "v1"
  }
}
```

To decode the offline package: Base64-decode `data.offline_blacklist.payload`, gunzip it, then
parse the JSON (`format: triproof-offline-blacklist/v1`). The encoded payload is guaranteed to be
at most `limit_bytes` (strictly below 50,000 bytes by default).

Invalid credentials return `422` with `error.code = validation_error` and an `email` detail.

### Logout

`POST /auth/logout` — Bearer token.

```json
{
  "success": true,
  "message": "Token revoked. Discard it from the device.",
  "data": null
}
```

### Identity

`GET /auth/me` — Bearer token.

Returns the same `user` object as login plus the current public key fingerprint. It returns
`401` after the token has been revoked or has expired.

## Response conventions

Success:

```json
{
  "success": true,
  "message": "Human-readable status.",
  "data": {}
}
```

`message`, `data`, and `meta` are omitted or `null` only when the endpoint has nothing to add.

Error:

```json
{
  "success": false,
  "error": {
    "code": "validation_error",
    "message": "Please correct the submitted fields.",
    "details": {
      "email": ["The provided email or password is incorrect."]
    }
  }
}
```

Validation failures always use `code=validation_error` with field-keyed `error.details`.
Business-rule failures use a stable lowercase `snake_case` code and may include structured
details.

| HTTP | Meaning |
|---|---|
| 200 | Completed |
| 201 | Purchase order or serial batch created |
| 401 | Missing, invalid, expired, revoked, or role-stale token |
| 403 | Authenticated but not allowed to act on this resource |
| 404 | Resource or API route not found |
| 409 | Quota/range conflict or retryable scan conflict |
| 422 | Validation or business-rule rejection |
| 429 | Rate limit exceeded (`rate_limited`) |
| 502/503 | AI provider failure, AI/vision unavailable, or crypto unconfigured |

Common business codes include `insufficient_quota`, `serial_conflict`, `unit_conflict`,
`factory_cannot_sign`, `order_not_found`, `batch_not_found`, `nothing_to_pack`,
`invalid_batch_scope`, `entries_required`, `vision_ai_unavailable`, `ai_unavailable`,
`crypto_unconfigured`, and `forbidden`.

## Rate limits

Limits are per minute and keyed by authenticated user ID, falling back to client IP.

| Middleware | Default | Applies to |
|---|---:|---|
| `triproof-login` | 10 | Login |
| `triproof-api` | 120 | General authenticated API and health |
| `triproof-verify` | 600 | `/verify/online-crosscheck` |
| `triproof-ai` | 20 | `/ai/analyze` |
| `triproof-export` | 30 | CSV/PDF export |

Override the defaults with `RATE_LIMIT_LOGIN`, `RATE_LIMIT_API`, `RATE_LIMIT_VERIFY`,
`RATE_LIMIT_AI`, and `RATE_LIMIT_EXPORT`. Throttle responses include the standard
`Retry-After`/`X-RateLimit-*` headers.

## Roles and scoping

| Role | Scope |
|---|---|
| `global_buyer` | Purchase orders where the user is the buyer; can create orders and generate signed batches; can export, verify, recall, perform rework-adjacent AI analysis, and view scoped anomalies |
| `factory_qa` | Purchase orders for the user's `factory_id`; can pack, export, recall own-factory batches, reconcile rework, and run scoped AI analysis; cannot create orders or sign serials |
| `admin` | All purchase orders, batches, recalls, packing, generation, exports, rework, and analysis |

Verification and notification endpoints are available to any authenticated role. Device tokens
are not an authentication factor; they identify a scanner within the authorized API session.

## Endpoint index

| Method | Path | Auth / role | Purpose |
|---|---|---|---|
| POST | `/auth/login` | Public | JWT, public key, offline blacklist |
| GET | `/offline-blacklist` | Bearer | Fresh offline package in `data`, same format as login’s `data.offline_blacklist`; no password storage or repeat login needed |
| POST | `/verify/transfer-ticket` | Bearer | Current owner submits `outer_serial`, `inner_serial`, `device_token`; returns `data.ticket` and `expires_at`. Expires in 24h; issuing another replaces it. Recipient supplies optional `transfer_ticket` with an ordinary cross-check of the physical pair. Redemption is single-use under the registry lock. |
| POST | `/auth/logout` | Bearer | Revoke current token |
| GET | `/auth/me` | Bearer | Identity and key fingerprint |
| GET | `/orders` | Bearer | Scoped purchase-order list |
| POST | `/orders` | Buyer/admin | Create purchase order |
| POST | `/orders/generate-batch` | Buyer/admin | Generate exactly N signed serials |
| GET | `/orders/{po}/export` | Buyer/factory/admin | CSV or PDF batch export |
| GET | `/orders/{po}/merkle` | Buyer/factory/admin | Compare stored and recomputed Merkle roots |
| POST | `/units/pack-batch` | Factory/admin | Mark generated units packed |
| POST | `/verify/online-crosscheck` | Bearer | Cloud-authoritative single or batched scan |
| GET | `/notifications/{device_token}` | Bearer | Device notification inbox |
| POST | `/notifications/mark-read` | Bearer | Mark notification IDs/all read |
| POST | `/rework/reconcile` | Factory/admin | Link rework parent/child serials |
| POST | `/ai/analyze` | Buyer/factory/admin | Analyze anomalies, optional rework/OCR |
| POST | `/recall/trigger` | Buyer/factory/admin | Precision batch recall |
| GET | `/health` | Public | Liveness and dependency status |
| GET | `/.well-known/triproof-key.json` | Public | Ed25519 public key metadata |

Times are ISO-8601 strings in UTC (`...Z` or `+00:00`). `po` path parameters are numeric
purchase-order IDs; `factory_id`, `machine_id`, and `batch_id` are normalized to uppercase.

## Orders

### List orders

`GET /orders` — Bearer token. Returns up to 50 orders per page, newest first.

```json
{
  "success": true,
  "data": {
    "orders": [
      {
        "id": 12,
        "po_number": "PO-2026-0042",
        "buyer": {
          "id": 4,
          "name": "Global Buyer",
          "email": "buyer@example.com"
        },
        "factory_id": "DHK04",
        "authorized_quota": 10000,
        "generated_count": 300,
        "remaining_quota": 9700,
        "factory_expiry_days": 45,
        "retail_expiry_days": 365,
        "factory_deadline": "2027-01-02T10:00:00+00:00",
        "merkle_root_hash": "<64-char sha256 hex>",
        "counts": {
          "serials": 300,
          "packed": 300,
          "claimed_genuine": 12,
          "cloned_flagged": 1,
          "recalled": 0
        },
        "created_at": "2026-11-18T10:00:00+00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 50,
      "total": 1,
      "last_page": 1
    }
  }
}
```

### Create purchase order

`POST /orders` — buyer or admin only.

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

- `po_number` is optional; a unique number is generated when omitted.
- `buyer_id` is optional for buyers (defaults to the caller) and required for admins through
  the web form contract. The API service rejects a missing buyer with `buyer_required`.
- `factory_expiry_days` defaults to the configured factory SLA (45).
- `retail_expiry_days` defaults to 365.
- `authorized_quota` is the maximum number of units that may ever be signed for this PO.

Returns `201` with `data.order` in the same shape as the list (count fields may be `null` until
serials exist). Duplicate PO numbers return `409` with `po_exists`.

### Generate a signed batch

`POST /orders/generate-batch` — buyer who owns the PO or admin.

```json
{
  "po_id": 12,
  "machine_id": "M01",
  "batch_id": "B01",
  "count": 3,
  "unit_start": 1,
  "prod_date": "2026-11-18",
  "include_units": true
}
```

- `count` must be between 1 and `MAX_BATCH_GENERATE` (default 50,000).
- `unit_start` is optional; when omitted the next free unit number is allocated.
- `prod_date` defaults to today and cannot be in the future. Expiry is
  `prod_date + retail_expiry_days`.
- `include_units` defaults to `true` when `count <= 1000`; otherwise only counts and hashes are
  returned.
- Generation is transactional and quota-locked. Over-quota requests return `409`
  `insufficient_quota`; overlapping unit ranges return `409` `unit_conflict` or
  `serial_conflict`.
- Factories cannot call this endpoint (`403` `factory_cannot_sign`).

Returns `201`:

```json
{
  "success": true,
  "message": "Exactly 3 quota-locked Ed25519 serial pair(s) generated.",
  "data": {
    "order": { "...": "purchase order payload" },
    "generated_count": 3,
    "unit_start": 1,
    "unit_end": 3,
    "merkle_root": "<64-char sha256 hex>",
    "units_included": true,
    "exports": {
      "csv": "https://your-domain.example/api/v1/orders/12/export?format=csv&batch=B01",
      "pdf": "https://your-domain.example/api/v1/orders/12/export?format=pdf&batch=B01"
    },
    "units": [
      {
        "outer_serial": "DHK04-M01-B01-000001|20261118|20271118",
        "inner_serial": "<base64 Ed25519 signature>",
        "unit_hash": "<sha256 hex>"
      }
    ]
  }
}
```

### Export a batch

`GET /orders/{po}/export?format=csv|pdf&batch=B01` — buyer/factory/admin with PO access.

- `format` defaults to `csv`; only `csv` and `pdf` are accepted.
- `batch` is optional and restricts the export to one batch ID.
- CSV is streamed as `text/csv; charset=UTF-8`.
- PDF is rendered with Dompdf and capped at `MAX_PDF_UNITS` (default 20,000) per export.
- Requests are throttled by the export limiter (30/minute by default).

### Merkle audit

`GET /orders/{po}/merkle` — buyer/factory/admin with PO access.

Returns `stored_root`, `recomputed_root`, `matches`, `leaf_count`, `generated_count`,
`consistent`, `algorithm`, `blockchain_ready`, `anchoring_status`, and `verified_at`.

### Pack units

`POST /units/pack-batch` — factory QA for the PO's factory or admin.

```json
{
  "po_id": 12,
  "machine_id": "M01",
  "batch_id": "B01",
  "unit_start": 1,
  "unit_end": 3
}
```

- Only units currently in `generated` status are packed.
- `unit_start`/`unit_end` are optional range filters.
- Returns `data.po_id`, `data.batch`, and `data.packed_count`.
- An empty match returns `422` `nothing_to_pack`; a buyer receives `403`.

## Verification

### Online cross-check

`POST /verify/online-crosscheck` — any authenticated role.

Single scan:

```json
{
  "sync_uuid": "7b4e2f18-4c62-4a4e-9d3f-1f8b4f6f2b7a",
  "outer_serial": "DHK04-M01-B01-000001|20261118|20271118",
  "inner_serial": "<base64 Ed25519 signature>",
  "device_token": "android-warehouse-17",
  "scan_mode": "online_live",
  "gps_lat": 23.8103,
  "gps_lng": 90.4125,
  "scanned_at": "2026-11-18T10:05:00+00:00",
  "offline_verdict": "verified_clean"
}
```

Batched scans replace the top-level fields with `logs: [...]`, up to 500 entries. Every entry
needs a distinct `sync_uuid`. Offline entries may include `scanned_at` and an
`offline_verdict`; live scans use server time.

`sync_uuid` is the idempotency key. Replaying it returns the stored result with
`duplicate: true` and does not claim ownership again.

Response:

```json
{
  "success": true,
  "message": "Cloud cross-check completed.",
  "data": {
    "results": [
      {
        "sync_uuid": "7b4e2f18-4c62-4a4e-9d3f-1f8b4f6f2b7a",
        "outer_serial": "DHK04-M01-B01-000001|20261118|20271118",
        "verdict": "verified_clean",
        "verdict_label": "Verified clean",
        "signature_valid": true,
        "registered": true,
        "claimed_now": true,
        "status": "claimed_genuine",
        "unit": {
          "factory_id": "DHK04",
          "machine_id": "M01",
          "batch_id": "B01",
          "unit_number": 1,
          "prod_date": "2026-11-18",
          "exp_date": "2027-11-18"
        },
        "feedback_notification_ids": [],
        "message_en": "...",
        "message_bn": "...",
        "duplicate": false,
        "scan_id": 91,
        "processed_at": "2026-11-18T10:05:01+00:00"
      }
    ],
    "feedback_notifications": [],
    "summary": {
      "verified_clean": 1
    }
  }
}
```

Verdict precedence:

| Verdict | Meaning |
|---|---|
| `forged_signature` | Signature invalid, hash mismatch, or serial unknown to the registry |
| `recalled_batch` | Unit belongs to a recalled batch |
| `expired` | Expiry date has passed |
| `verified_clean` | Authentic and claimed now by this device |
| `owner_repeat_ok` | Scanned again by the same claiming device |
| `store_grace_ok` | Different device inside the 5-minute / 100-metre grace window |
| `clone_detected` | Different device outside grace; serial flagged and both devices alerted |

`feedback_notifications` contains notices created for the device submitting each scan. A second
owner receives their own alert via their inbox; their device token is not returned to the scanning
phone. `summary` counts results by verdict.

## Notifications

### Inbox

`GET /notifications/{device_token}?per_page=50&unread_only=1` — Bearer token.

`device_token` must match `^[A-Za-z0-9._:-]{1,128}$`. `per_page` is clamped to 1–100.

```json
{
  "success": true,
  "data": {
    "device_token": "android-warehouse-17",
    "unread_count": 1,
    "notifications": [
      {
        "id": 7,
        "device_token": "android-warehouse-17",
        "outer_serial": "DHK04-M01-B01-000001|20261118|20271118",
        "severity": "critical_clone",
        "title_en": "Counterfeit risk detected",
        "message_en": "...",
        "title_bn": "...",
        "message_bn": "...",
        "is_read": false,
        "created_at": "2026-11-18T10:05:01+00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "per_page": 50,
      "total": 1,
      "last_page": 1
    }
  }
}
```

Severities are `info`, `warning`, `critical_clone`, and `recall_alert`.

### Mark read

`POST /notifications/mark-read` — Bearer token.

```json
{
  "device_token": "android-warehouse-17",
  "ids": [7, 8],
  "mark_all": false
}
```

Provide `ids` or set `mark_all=true`; otherwise the endpoint returns `422`. Only unread rows
for that device are updated. Response includes `marked_read`.

## Rework

### Reconcile lineage

`POST /rework/reconcile` — factory QA or admin.

```json
{
  "entries": [
    {
      "parent_outer_serial": "DHK04-M01-B01-000001|20261118|20271118",
      "child_outer_serial": "DHK04-M01-B01-000002|20261118|20271118",
      "action": "rework",
      "line_id": "LINE-01",
      "note": "Replacement label verified against the paper rework log.",
      "scanned_at": "2026-11-18T10:10:00+00:00"
    }
  ]
}
```

- Up to 1,000 entries per request.
- At least one of `child_outer_serial` or `image_base64` is required per entry; an image uses
  configured Vision AI OCR to extract the child serial.
- Both serials must parse, exist in the registry, share a factory, and have valid Ed25519
  signatures. Cycles, conflicting parents, and self-links are rejected.
- Results are idempotent: an existing identical link reports `already_linked`.

Response:

```json
{
  "success": true,
  "message": "Rework reconciliation complete: 1 linked, 0 gap(s).",
  "data": {
    "results": [
      {
        "index": 0,
        "parent_outer_serial": "DHK04-M01-B01-000001|20261118|20271118",
        "child_outer_serial": "DHK04-M01-B01-000002|20261118|20271118",
        "status": "linked",
        "reason_en": "Rework lineage linked and ready for audit.",
        "reason_bn": "...",
        "ocr": null
      }
    ],
    "counts": {
      "entries": 1,
      "linked": 1,
      "already_linked": 0,
      "rejected": 0,
      "gaps": 0,
      "ocr_used": 0
    },
    "summary_en": "...",
    "summary_bn": "...",
    "ai_generated": false,
    "ai_configured": false
  }
}
```

Result statuses are `linked`, `already_linked`, `gap`, and `rejected`. A gap also writes an open
anomaly report for the affected batch.

## AI analysis

`POST /ai/analyze` — buyer/factory/admin, optionally scoped to a visible PO and batch.

```json
{
  "po_id": 12,
  "batch_id": "B01",
  "entries": [
    {
      "parent_outer_serial": "DHK04-M01-B01-000001|20261118|20271118",
      "child_outer_serial": "DHK04-M01-B01-000002|20261118|20271118"
    }
  ],
  "image_base64": "<optional base64 or data URI>",
  "image_mime": "image/jpeg"
}
```

- `entries` runs the same rework reconciliation and returns `data.rework`.
- `image_base64` runs Vision OCR and, when both serials are extracted, read-only serial
  verification. Without a configured AI provider this returns `503 vision_ai_unavailable`.
- The anomaly analysis always runs. Without AI credentials it uses deterministic bilingual
  template guidance and reports `ai_used=false` and `ai_generated=false` inside affected data.
- `data.reports` contains up to 40 open anomaly reports; `data.findings` is the cluster count.
- `data.ai_configured` and `data.ai_used` describe enrichment, not whether analysis succeeded.

Report shape:

```json
{
  "id": 15,
  "po_id": 12,
  "batch_id": "B01",
  "anomaly_type": "clone_cluster",
  "explanation_en": "...",
  "explanation_bn": "...",
  "affected_units": {
    "factory_id": "DHK04",
    "machine_id": "M01",
    "serials": [],
    "count": 1,
    "ai_generated": false,
    "recommended_action_en": "...",
    "recommended_action_bn": "..."
  },
  "status": "open",
  "created_at": "2026-11-18T10:15:00+00:00",
  "updated_at": "2026-11-18T10:15:00+00:00"
}
```

`anomaly_type` is one of `clone_cluster`, `ghost_production_attempt`, `rework_gap`, or
`defect_cluster`.

## Recall

`POST /recall/trigger` — buyer/factory/admin.

```json
{
  "factory_id": "DHK04",
  "machine_id": "M01",
  "batch_id": "B01",
  "reason": "Seal integrity failure in the evening shift."
}
```

- Factories may only recall their own `factory_id`.
- Buyers may only recall batches that belong to their purchase orders.
- All matching units are marked `recalled`, affected devices are notified, and a
  `recall_events` audit row is written.
- The batch ID is normalized to uppercase; invalid identifiers return `422`
  `invalid_batch_scope`; an empty match returns `404` `batch_not_found`.

Response:

```json
{
  "success": true,
  "message": "Precision recall applied to 300 unit(s); 2 device(s) notified.",
  "data": {
    "recall_event_id": 9,
    "batch": "DHK04-M01-B01",
    "affected_units": 300,
    "devices_notified": 2,
    "triggered_at": "2026-11-18T10:20:00+00:00"
  }
}
```

## Health and trust metadata

### Health

`GET /health` — public, general API throttle.

Returns `200` when the database, sodium, and signing keys are ready; otherwise `503`.

```json
{
  "success": true,
  "message": "All critical dependencies are available.",
  "data": {
    "status": "ok",
    "version": "1.0.0",
    "php": "8.4.27",
    "database": "up",
    "sodium": "available",
    "signing_keys": "configured",
    "ai": "fallback_only",
    "blacklist_gzip": "available",
    "time": "2026-11-18T10:25:00+00:00"
  }
}
```

`ai` reports the configured provider name or `fallback_only`.

### Public key

`GET /.well-known/triproof-key.json` — public.

```json
{
  "success": true,
  "data": {
    "issuer": "TriProof AI",
    "algorithm": "Ed25519",
    "public_key_base64": "<32-byte base64 key>",
    "key_fingerprint": "<sha256 hex>",
    "key_id": "<first 32 hex characters>",
    "serial_format": "{FactoryID}-{MachineID}-{BatchID}-{UnitNumber:06d}|{ProdYYYYMMDD}|{ExpYYYYMMDD}",
    "inner_serial_format": "Base64(Ed25519 detached signature of outer serial)",
    "unit_hash": "SHA-256(outer_serial + \"|\" + inner_serial)",
    "offline_trust_model": "Verify the Ed25519 signature locally; cloud cross-check establishes ownership and recall state."
  }
}
```

If the public key is missing or malformed, this endpoint returns `503 crypto_unconfigured`.
Mobile clients should pin or cache the public key plus fingerprint, verify the detached
signature offline, and use the online cross-check for claim, recall, expiry, and clone state.

## cURL quick start

```bash
BASE="https://your-domain.example/api/v1"

TOKEN=$(curl -sS -X POST "$BASE/auth/login" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email":"buyer@example.com","password":"your-password"}' \
  | jq -r '.data.token')

curl -sS "$BASE/orders" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' | jq

curl -sS -X POST "$BASE/verify/online-crosscheck" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{
    "sync_uuid": "7b4e2f18-4c62-4a4e-9d3f-1f8b4f6f2b7a",
    "outer_serial": "DHK04-M01-B01-000001|20261118|20271118",
    "inner_serial": "<base64 Ed25519 signature>",
    "device_token": "android-warehouse-17",
    "scan_mode": "online_live",
    "gps_lat": 23.8103,
    "gps_lng": 90.4125
  }' | jq

curl -sS -X POST "$BASE/auth/logout" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Accept: application/json' | jq
```

CSV/PDF exports return binary bodies rather than JSON:

```bash
curl -sS "$BASE/orders/12/export?format=csv&batch=B01" \
  -H "Authorization: Bearer $TOKEN" \
  -o batch-B01.csv
```
