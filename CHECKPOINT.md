# TriProof AI — Project Milestone & Verification Status

## Current milestone

Enterprise V2, operational web-console hardening, and the **v2.1 dynamic trust-sync release** are complete. V2 was introduced in commit `d719173`; v2.1 adds editable website trust sync, automatic key-ring/blacklist refresh, hardened smart-URL/`TP1:` QR normalization, and verified offline verification keys. Earlier milestones delivered full admin directory CRUD, stable dialog lifecycle, scoped validation reopening, smart packing suggestions, synchronized documentation, and the Flutter history deletion workflow. This checkpoint tracks the milestone state, not a release tag.

## Delivered web features

- **Admin directory:** complete Add / Edit / Remove flows for Global Buyers, Factories, and Factory QA Operators, plus Platform & Compliance Settings. Factory deletion is guarded by linked POs, serials, and users. Admin success responses redirect back with flashed `status` and `active_tab`; validation failures redirect back with errors and old input.
- **Modal lifecycle:** every `<dialog class="tp-dialog">` is closed by default; triggers use `data-open-dialog`; close buttons are `type="button"`; forms have one CSRF-protected POST, one submit button, and an exact hidden `dialog_id`. `app.js` uses null-safe element lookup, removes stale `open` attributes, supports `showModal()` with fallback, and closes on backdrop, Escape, and close controls.
- **Scoped validation:** every form defines its own `$isCurrentDialog`; inline error summaries and `old()` values render only in the failing dialog. Admin dialogs also carry hidden `active_tab` fields. No sibling dialog receives leaked input.
- **Sidebar navigation:** hash links address dashboard sections and admin tabs; active-tab state survives redirects through flashed session data.
- **Smart packing:** `#pack-dialog` consumes per-order JSON from `DashboardController::$packableSummary`, shows live remaining counts, batch chips, `#min–#max` suggestions, and a full-range shortcut. `PackingService` reports available batch ranges when a selected scope has no unpackaged units.
- **Team page:** `config/team.php`, `resources/views/about.blade.php`, local assets, and both locales agree on Feroz Mahmud (Team Lead), Shafayet Al Hossain, Remon Islam, Masrurul Hasan Maktum, and Golam Rabbani.
- **V2 core:** versioned key ring and rotation, DPP digests, carton Merkle proofs, offline Bloom warnings, device attestation and `scan_seq` replay protection, local Merkle anchor receipts, optional webhook alerts, and public `/v/{payload}` passports.
- **v2.1 public trust contract:** `/.well-known/triproof-key.json` always publishes the configured legacy `K00` key even when `signing_keys` is empty, exposing `public_key_base64` / `public_key` / `universal_public_key` aliases, `key_ring`, `active_keys`, and `verify_only_keys`; public trust endpoints allow configured CORS origins without authentication.
- **v2.1 QR hardening:** verification normalizes smart URLs and `TP1:`-prefixed inner payloads before comparing canonical outer serials; exact web/API QR payload regression coverage is in `tests/Feature/QrStickerTest.php`.
- **AI/MCP layer:** authenticated read-only `POST /api/v1/mcp` JSON-RPC server with five deterministic tools, stdio/HTTP bridge, `mcp.json`, and `docs/MCP.md`; grounded `POST /api/v1/ai/ask-docs` RAG endpoint with semantic chunking, BM25/dense retrieval, citations, and deterministic fallback.
- **Provenance & analytics:** Neo4j graph sync with SQL recursive-CTE fallback, contextual RAG over SOP/GS1 corpora, DuckDB snapshot export with German Tank estimates, HMAC-signed webhooks with n8n workflow, and D3/ECharts dashboard widgets.
- **Edge AI:** `AiExplanationService` supports Ollama and llama.cpp with automatic deterministic bilingual fallback for air-gapped factories.

## Delivered mobile features

- Offline Ed25519 verification in pure Dart across all cached `active` / `verify_only` keys, with the embedded universal key as fallback
- **Dynamic trust sync (v2.1):** editable server URL, automatic sync on startup/online transition, manual **Update Offline Data from Website** card, key-ring + blacklist/Bloom persistence, and bilingual status feedback
- Trust metadata persisted in SQLCipher `app_meta` (`server_base_url`, `synced_key_ring_json`, `last_trust_sync_at`) and keys provisioned into Android Keystore / iOS Keychain
- SQLCipher `tripoof_scanner_v1.db` with `scan_history`, `cached_blacklist`, `notifications_inbox`, and `app_meta`
- In-memory session-duplicate, prior-owner, blacklist, and Bloom mirrors synchronized with encrypted storage
- Per-row scan deletion and full **Clear All History** with bilingual EN/বাংলা confirmation dialogs; `deleteScanRecord` and `clearScanHistory` invalidate caches
- GPS evidence capture, anti-clock-rollback high-water mark, retroactive clone/recall alerts, and store-and-forward cloud sync
- Smart URL / `TP1:` QR normalization, canonical outer serial + unit hash persistence, and demo key provisioning aligned with the live universal key
- Flutter widget and service tests under `mobile/test/`; no native Android/JVM/Room suite exists in this repository

## Verification gates

- Laravel: full PHPUnit suite passing — **142 tests, 1,161 assertions**, no warnings
- Code style: `vendor/bin/pint --test` passing
- Assets: `npm run build` passing; committed `public/build/manifest.json` refreshed before packaging
- Blade: `php artisan view:clear && php artisan view:cache` passing before packaging
- Modal contract: `tests/Feature/AllModalsVerificationTest.php` verifies markup, triggers, close buttons, CSRF, exact `dialog_id`, admin `active_tab`, success round trips, error round trips, scoped `old()` values, and body auto-open guards for every modal
- Trust contract: `tests/Feature/ApiFlowTest.php` verifies public K00 discovery with an empty key table, response aliases, and unauthenticated CORS preflight
- QR contract: `tests/Feature/QrStickerTest.php` verifies exact smart-URL and `TP1:` payloads through both web and API verification paths
- MCP contract: `tests/Feature/McpServerTest.php` verifies `mcp:read` JWT/API-key auth and tools/call for all five deterministic tools
- Intelligence contracts: `tests/Feature/IntelligenceServicesTest.php`, `tests/Feature/WebhookSigningTest.php`, and `tests/Unit/SerialGapAnalyticsTest.php` verify graph/RAG fallbacks, HMAC signing, and German Tank math
- Flutter: `flutter analyze --no-fatal-infos` (no errors or warnings; remaining info-level lints) and **29 tests** passing for the verifier package
- Release artifacts: universal `mobile/build/app/outputs/flutter-apk/app-release.apk`, split ABI APKs, and `dist/triproof-ai-cpanel.zip` / `.tar.gz`, generated from this milestone; cPanel archives include preinstalled production-only `vendor/` and `CPANEL_DEPLOY.txt`

## Bloom / offline-package constraint

The offline blacklist package remains below 50 KB. The configured Bloom cap is `OFFLINE_BLOOM_MAX_BITS=240000`; at the 30,000-entry target this yields roughly 2% false-positive rate, not 0.1%. A 0.1% filter would need about 431,000 bits (~54 KB) before Base64 and cannot fit the wire budget. Bloom hits are warnings only; exact cached blacklist checks decide.

## Production follow-ups

- Physical handset camera and latency acceptance for the v2.1 APK
- Confirm a clean install syncs from `https://<YOUR_DOMAIN>` and retains the key ring/blacklist across restart and Airplane Mode
- Secure release signing key and store upload credentials handled outside source control
- Production HTTPS/public-key pin verification after cPanel setup
- Real external anchor provider and `ALERT_WEBHOOK_URL` credentials if enabled
- Confirm API `anchoring_status` compatibility value is acceptable before Phase-2 clients rely on it
