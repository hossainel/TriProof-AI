# TriProof AI Scanner (`tripoof_ai_scanner`) — v2.1.1

Offline-first Flutter verifier for **Android and iOS**, located in [`mobile/`](.) inside the TriProof AI monorepo. It verifies signed labels in Airplane Mode, stores encrypted audit history with SQLCipher, synchronizes keys/blacklist/scan data with any deployed TriProof website, and supports individual scan deletion plus full bilingual Clear History.

Related docs: [root README](../README.md) · [API reference](../docs/API.md) · [Architecture](../ARCHITECTURE.md) · [Checkpoint](../CHECKPOINT.md)

## Capabilities

- **Offline Ed25519 verification:** pure-Dart verification of the detached Base64 signature over the exact canonical outer serial, using an embedded universal key or provisioned versioned key ring. Target latency is under 50 ms.
- **Versioned key ring:** reads `KNN-` serial prefixes, `|DPP:XXXXXXXX` digests, smart `/v/...` URLs, and `/.well-known/triproof-key.json`; active and retired keys remain verifiable.
- **Dynamic website trust sync (v2.1.1):** edit the server URL, sync keys and blacklist from any TriProof deployment, and keep working offline. Runs automatically at startup/online and manually from the **Update Offline Data from Website** card.
- **Clock-rollback protection:** a persisted high-water mark advances with GPS time, verified scans, and server sync; stale device clocks are rejected.
- **On-device vision:** ML Kit barcode scanning and text recognition repair curved or smudged labels; no cloud OCR is required.
- **Encrypted local history:** SQLCipher database with `scan_history`, `cached_blacklist`, `notifications_inbox`, and `app_meta`. The 256-bit database key is generated with `Random.secure()` and stored in Android Keystore / iOS Keychain.
- **Duplicate and prior-owner caches:** in-memory mirrors rebuilt from `scan_history`, updated on insert, and invalidated on delete/clear/close.
- **History management:** delete one scan or clear all history with English/বাংল confirmation dialogs and snackbars.
- **GPS evidence:** non-blocking cached coordinates are stored with each scan; location permissions and high-water-mark refresh use `geolocator`.
- **Store-and-forward sync:** uploads pending scans with monotonic `scan_seq`, fetches notifications, blacklist deltas, Bloom filter, and key ring, and raises bilingual retroactive clone/recall alerts.
- **Bilingual UI:** EN / বাংলা toggle, offline/online status, consumer unboxing vs. factory QA modes, ownership-transfer QR, and an Airplane Mode demo simulator.

## Project structure

```text
lib/
  main.dart                              # bootstrap and 3-tab shell
  core/
    constants/                           # API/key/epoch defaults, budgets, theme
    l10n/                                # EN/বাংলা strings, including history confirmations
    utils/                               # OCR serial normalizer, smart payload parser, image helpers
  domain/
    enums/                               # AppLanguage, ScanMode, OfflineStatus, CloudStatus
    models/                              # ParsedOuterSerial, ScanRecord, BloomFilter, TrustKey, AppNotification
  data/
    repositories/
      verification_repository.dart       # offline verification pipeline
      sync_repository.dart               # connectivity listener and retroactive alerts
    services/
      crypto_service.dart                # Ed25519 multi-key verification, SHA-256 unit_hash
      trust_sync_service.dart            # website key-ring + blacklist/Bloom sync
      database_helper.dart               # SQLCipher DB, caches, app_meta, delete/clear history
      secure_keystore_service.dart       # keystore keys and key-ring provisioning
      api_client.dart                    # REST client for cross-check, notifications, blacklist, keys
      location_time_service.dart         # GPS + high-water clock
      notification_service.dart          # local OS notifications
      feedback_service.dart              # audio/vibration
      demo_simulator_service.dart        # signed Airplane Mode scenarios
  ui/
    view_models/                         # AppViewModel (ChangeNotifier)
    core/widgets/                        # status bar, result cards, website sync card, transfer sheet, alert banner
    features/
      home/
      scanner/widgets/                   # scanner overlay and seal inspection dialog
      history/scan_history_screen.dart   # delete / clear-all confirmations
      alerts/
```

## Prerequisites

- Flutter SDK **3.50+** and Dart **>=3.12.0 <4.0.0**
- Android: `compileSdk 37`, `minSdk >= 23`, Java 17 / Kotlin JVM 17, Android Gradle Plugin 9.3.1, Gradle wrapper 9.5.0
- iOS: deployment target 15.0+, Xcode on macOS
- A running TriProof API for online cross-check and sync

## Setup and verification

From the repository root:

```bash
cd mobile
flutter pub get
flutter analyze --no-fatal-infos
flutter test
```

Run on a connected device or emulator:

```bash
flutter run
```

Build the v2.1.1 release APKs:

```bash
flutter build apk --release --split-per-abi --no-tree-shake-icons \
  --build-name=2.1.1 --build-number=2 \
  --dart-define=API_BASE_URL=https://t.focusautomobiles.com
```

Build with explicit production configuration (example uses live t.focusautomobiles.com):

```bash
flutter build apk --release --split-per-abi --no-tree-shake-icons \
  --build-name=2.1.1 --build-number=2 \
  --dart-define=API_BASE_URL=https://t.focusautomobiles.com \
  --dart-define=UNIVERSAL_PUBLIC_KEY=fzJVLyOzjNkU5yO1nunqxJbizPggpPHek3Xctb654Pw= \
  --dart-define=BUILD_EPOCH_MS=$(date +%s000)
```

Artifacts are written to `build/app/outputs/flutter-apk/` as `app-arm64-v8a-release.apk`, `app-armeabi-v7a-release.apk`, and `app-x86_64-release.apk`.

| Define | Default | Purpose |
|---|---|---|
| `API_BASE_URL` | `https://t.focusautomobiles.com` | Base URL for API and public endpoints |
| `UNIVERSAL_PUBLIC_KEY` | live legacy `K00` key in `app_constants.dart` | Fallback public key before the key ring is provisioned |
| `BUILD_EPOCH_MS` | `0` | Floor for the clock high-water mark to reject stale builds |

Use `--dart-define=UNIVERSAL_PUBLIC_KEY=<UNIVERSAL_PUBLIC_KEY>` with the deployed public key. The universal public key is not a secret, but it must match the signing backend. In v2.1.1 the installed app can also change its website URL and refresh the key ring at runtime, so the compile-time key is only the fallback.

## Offline verification pipeline

1. Clock-rollback guard using the persisted high-water mark.
2. Smart-payload parsing: `/v/...` wrappers, `TP1:` inner prefixes, `KNN-` prefixes, and `|DPP:XXXXXXXX` suffix.
3. Ed25519 signature verification across all cached active/verify-only keys, then the embedded fallback key.
4. Expiry and 45-day factory QA shelf-life threshold.
5. Cached blacklist check; Bloom miss is a definitive negative, Bloom hit requires exact lookup.
6. Session duplicate and prior-owner cache checks.

Before scanning, `TrustSyncService` loads persisted trust metadata and may refresh it from the configured website. Every result is stored as a `pending_sync` scan record with canonical outer serial, unit hash, GPS, latency, device token, `scan_seq`, and both offline and cloud verdict fields.

## SQLCipher database and history management

`DatabaseHelper` opens `triproof_scanner_v1.db` at version 2 with a password from `SecureKeystoreService.getOrCreateDatabaseKey()`.

| Table | Contents |
|---|---|
| `scan_history` | Outer/inner serials, unit hash, batch scope, verdicts, bilingual messages, GPS, device token, `scan_seq`, timestamps |
| `cached_blacklist` | Serial/batch reason codes, strict 50 KB budget |
| `notifications_inbox` | Bilingual retroactive clone/recall notices |
| `app_meta` | Key/value metadata including `server_base_url`, `synced_key_ring_json`, `last_trust_sync_at`, and the stored Bloom filter |

Cache mirrors: `_blacklistCache`, `_bloomFilterCache`, `_sessionSerialCache`, and `_priorOwnerScanCache`.

- `deleteScanRecord(syncUuid)` removes one row and invalidates session/prior-owner caches.
- `clearScanHistory()` removes all `scan_history` rows and invalidates the same caches. The UI label is **Clear All History**.
- Both actions are confirmed through bilingual `AlertDialog`s in `lib/ui/features/history/scan_history_screen.dart` (`confirmDeleteItem`, `confirmClearAll`, Cancel/Delete actions) and report success via localized snackbars.

## Dynamic trust sync (v2.1.1)

`TrustSyncService` normalizes the URL (adds `https://` when needed, removes trailing slashes and `/api/v1`), fetches the public key ring, and persists valid 32-byte Ed25519 keys in both SQLCipher and secure storage. It then fetches the blacklist delta, replaces exact cache entries, saves the Bloom filter, and records the server timestamp.

- Automatic: app initialization and online transitions (silent).
- Manual: **Update Offline Data from Website** card on Home and **Sync Keys from Website** on a verification result.
- Bilingual status shows active-key count, last update time, success, or failure.
- No JWT is required; requests time out after 10 seconds and blacklist data stays within the 50 KB budget.

## Cloud endpoints used by the app

- `POST {API_BASE_URL}/api/v1/verify/online-crosscheck` — uploads `records` (also accepted as `logs`) with `scan_seq`; reads verdicts from `results`.
- `GET {API_BASE_URL}/api/v1/notifications/{device_token}` — public bilingual inbox.
- `GET {API_BASE_URL}/api/v1/blacklist/delta` — public `<50KB` payload with `entries`, `bloom_filter`, and optional `server_time`.
- `GET {API_BASE_URL}/.well-known/triproof-key.json` — public key ring and trust contract; accepts `key_ring`, `active_keys`, `verify_only_keys`, and `public_key_base64` / `public_key` / `universal_public_key` aliases.
- The same two public endpoints power **Update Offline Data from Website**; the selected URL is persisted as `server_base_url`.

Full request/response contracts are in [docs/API.md](../docs/API.md).

## Testing offline without hardware labels

1. Enable Airplane Mode and confirm the **OFFLINE READY** pill.
2. Use **Quick-Test Demo Simulator (Airplane Mode)** for genuine, repeat owner, expired, short-dated, swapped tag, duplicate box, recalled batch, and degraded OCR scenarios.
3. Open the Alerts tab and run **Demo: Simulate Retroactive Clone/Recall Sync** to preview queue drain, SQLite status upgrade, OS notification, and bilingual warning banner.

The v2.1.1 suite includes `test/trust_sync_test.dart` for key-ring parsing, URL normalization, SQLCipher persistence, and verification with a newly synced key, plus `test/trust_sync_widget_test.dart` for the bilingual sync card. Current package gates: **29 Flutter tests** and `flutter analyze --no-fatal-infos` with no errors or warnings.
