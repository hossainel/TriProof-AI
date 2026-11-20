# TriProof AI — Project Milestone & Verification Status

## Current milestone

Enterprise V2 plus the operational web-console hardening are complete. V2 was introduced in commit `d719173`; the current milestone adds full admin directory CRUD, stable dialog lifecycle, scoped validation reopening, smart packing suggestions, synchronized documentation, and the Flutter history deletion workflow. This checkpoint tracks the milestone state, not a release tag.

## Delivered web features

- **Admin directory:** complete Add / Edit / Remove flows for Global Buyers, Factories, and Factory QA Operators, plus Platform & Compliance Settings. Factory deletion is guarded by linked POs, serials, and users. Admin success responses redirect back with flashed `status` and `active_tab`; validation failures redirect back with errors and old input.
- **Modal lifecycle:** every `<dialog class="tp-dialog">` is closed by default; triggers use `data-open-dialog`; close buttons are `type="button"`; forms have one CSRF-protected POST, one submit button, and an exact hidden `dialog_id`. `app.js` uses null-safe element lookup, removes stale `open` attributes, supports `showModal()` with fallback, and closes on backdrop, Escape, and close controls.
- **Scoped validation:** every form defines its own `$isCurrentDialog`; inline error summaries and `old()` values render only in the failing dialog. Admin dialogs also carry hidden `active_tab` fields. No sibling dialog receives leaked input.
- **Sidebar navigation:** hash links address dashboard sections and admin tabs; active-tab state survives redirects through flashed session data.
- **Smart packing:** `#pack-dialog` consumes per-order JSON from `DashboardController::$packableSummary`, shows live remaining counts, batch chips, `#min–#max` suggestions, and a full-range shortcut. `PackingService` reports available batch ranges when a selected scope has no unpackaged units.
- **Team page:** `config/team.php`, `resources/views/about.blade.php`, local assets, and both locales agree on Feroz Mahmud (Team Lead), Shafayet Al Hossain, Remon Islam, Masrurul Hasan Maktum, and Golam Rabbani.
- **V2 core:** versioned key ring and rotation, DPP digests, carton Merkle proofs, offline Bloom warnings, device attestation and `scan_seq` replay protection, local Merkle anchor receipts, optional webhook alerts, and public `/v/{payload}` passports.

## Delivered mobile features

- Offline Ed25519 verification in pure Dart with the embedded universal key and provisioned key ring
- SQLCipher `tripoof_scanner_v1.db` with `scan_history`, `cached_blacklist`, `notifications_inbox`, and `app_meta`
- In-memory session-duplicate, prior-owner, blacklist, and Bloom mirrors synchronized with encrypted storage
- Per-row scan deletion and full **Clear All History** with bilingual EN/বাংলা confirmation dialogs; `deleteScanRecord` and `clearScanHistory` invalidate caches
- GPS evidence capture, anti-clock-rollback high-water mark, retroactive clone/recall alerts, and store-and-forward cloud sync
- Flutter widget and service tests under `mobile/test/`; no native Android/JVM/Room suite exists in this repository

## Verification gates

- Laravel: full PHPUnit suite passing with no warnings
- Code style: `vendor/bin/pint --test` passing
- Assets: `npm run build` passing; committed `public/build/manifest.json` refreshed
- Blade: `php artisan view:clear && php artisan view:cache` passing
- Modal contract: `tests/Feature/AllModalsVerificationTest.php` verifies markup, triggers, close buttons, CSRF, exact `dialog_id`, admin `active_tab`, success round trips, error round trips, scoped `old()` values, and body auto-open guards for every modal
- Flutter: `flutter analyze --no-fatal-infos` (no errors or warnings; remaining info-level lints) and `flutter test` passing for the verifier package
- Release artifacts: Android APK and `triproof-cpanel-deploy.zip` regenerated from this milestone

## Bloom / offline-package constraint

The offline blacklist package remains below 50 KB. The configured Bloom cap is `OFFLINE_BLOOM_MAX_BITS=240000`; at the 30,000-entry target this yields roughly 2% false-positive rate, not 0.1%. A 0.1% filter would need about 431,000 bits (~54 KB) before Base64 and cannot fit the wire budget. Bloom hits are warnings only; exact cached blacklist checks decide.

## Production follow-ups

- Physical handset camera and latency acceptance for the release APK
- Secure release signing key and store upload credentials handled outside source control
- Production HTTPS/public-key pin verification after cPanel setup
- Real external anchor provider and `ALERT_WEBHOOK_URL` credentials if enabled
- Confirm API `anchoring_status` compatibility value is acceptable before Phase-2 clients rely on it
