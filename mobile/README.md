# TriProof AI Scanner (`tripoof_ai_scanner`)

Offline-first Flutter verifier for **Android and iOS**, located in [`mobile/`](.) inside the TriProof AI monorepo. It verifies signed labels in Airplane Mode, stores encrypted audit history with SQLCipher, synchronizes with the Laravel API when connectivity returns, and supports individual scan deletion plus full bilingual Clear History.

Related docs: [root README](../README.md) · [API reference](../docs/API.md) · [Architecture](../ARCHITECTURE.md) · [Checkpoint](../CHECKPOINT.md)

## Capabilities

- **Offline Ed25519 verification:** pure-Dart verification of the detached Base64 signature over the exact canonical outer serial, using an embedded universal key or provisioned versioned key ring. Target latency is under 50 ms.
- **Versioned key ring:** reads `KNN-` serial prefixes, `|DPP:XXXXXXXX` digests, smart `/v/...` URLs, and `/.well-known/triproof-key.json`; retired keys remain verifiable.
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
    models/                              # ParsedOuterSerial, ScanRecord, BloomFilter, AppNotification
  data/
    repositories/
      verification_repository.dart       # 6-stage offline verification pipeline
      sync_repository.dart               # connectivity listener and retroactive alerts
    services/
      crypto_service.dart                # Ed25519, SHA-256 unit_hash, carton signature check
      database_helper.dart               # SQLCipher DB, caches, delete/clear history
      secure_keystore_service.dart       # keystore keys and key-ring provisioning
      api_client.dart                    # REST client for cross-check, notifications, blacklist, keys
      location_time_service.dart         # GPS + high-water clock
      notification_service.dart          # local OS notifications
      feedback_service.dart              # audio/vibration
      demo_simulator_service.dart        # signed Airplane Mode scenarios
  ui/
    view_models/                         # AppViewModel (ChangeNotifier)
    core/widgets/                        # status bar, result cards, transfer sheet, alert banner
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

Build a release APK:

```bash
flutter build apk --release --split-per-abi
```

Build with explicit production configuration:

```bash
flutter build apk --release --split-per-abi \
  --dart-define=API_BASE_URL=https://<YOUR_DOMAIN> \
  --dart-define=UNIVERSAL_PUBLIC_KEY=<UNIVERSAL_PUBLIC_KEY> \
  --dart-define=BUILD_EPOCH_MS=1763510400000
```

| Define | Default | Purpose |
|---|---|---|
| `API_BASE_URL` | `http://triproof.local` | Base URL for API and public endpoints |
| `UNIVERSAL_PUBLIC_KEY` | embedded legacy `K00` key in `app_constants.dart` | Fallback public key before the key ring is provisioned |
| `BUILD_EPOCH_MS` | `0` | Floor for the clock high-water mark to reject stale builds |

Use `--dart-define=UNIVERSAL_PUBLIC_KEY=<UNIVERSAL_PUBLIC_KEY>` with the deployed public key. The universal public key is not a secret, but it must match the signing backend.

## Offline verification pipeline

1. Clock-rollback guard using the persisted high-water mark.
2. Smart-payload parsing: `/v/...` wrappers, `KNN-` prefixes, and `|DPP:XXXXXXXX` suffix.
3. Ed25519 signature verification with the active or serial-selected key.
4. Expiry and 45-day factory QA shelf-life threshold.
5. Cached blacklist check; Bloom miss is a definitive negative, Bloom hit requires exact lookup.
6. Session duplicate and prior-owner cache checks.

Every result is stored as a `pending_sync` scan record with GPS, latency, device token, `scan_seq`, and both offline and cloud verdict fields.

## SQLCipher database and history management

`DatabaseHelper` opens `triproof_scanner_v1.db` at version 2 with a password from `SecureKeystoreService.getOrCreateDatabaseKey()`.

| Table | Contents |
|---|---|
| `scan_history` | Outer/inner serials, unit hash, batch scope, verdicts, bilingual messages, GPS, device token, `scan_seq`, timestamps |
| `cached_blacklist` | Serial/batch reason codes, strict 50 KB budget |
| `notifications_inbox` | Bilingual retroactive clone/recall notices |
| `app_meta` | Key/value metadata including the stored Bloom filter |

Cache mirrors: `_blacklistCache`, `_bloomFilterCache`, `_sessionSerialCache`, and `_priorOwnerScanCache`.

- `deleteScanRecord(syncUuid)` removes one row and invalidates session/prior-owner caches.
- `clearScanHistory()` removes all `scan_history` rows and invalidates the same caches. The UI label is **Clear All History**.
- Both actions are confirmed through bilingual `AlertDialog`s in `lib/ui/features/history/scan_history_screen.dart` (`confirmDeleteItem`, `confirmClearAll`, Cancel/Delete actions) and report success via localized snackbars.

## Cloud endpoints used by the app

- `POST {API_BASE_URL}/api/v1/verify/online-crosscheck` — uploads `records` (also accepted as `logs`) with `scan_seq`; reads verdicts from `results`.
- `GET {API_BASE_URL}/api/v1/notifications/{device_token}` — public bilingual inbox.
- `GET {API_BASE_URL}/api/v1/blacklist/delta` — public `<50KB` payload with `entries` and `bloom_filter`.
- `GET {API_BASE_URL}/.well-known/triproof-key.json` — public key ring and trust contract.

Full request/response contracts are in [docs/API.md](../docs/API.md).

## Testing offline without hardware labels

1. Enable Airplane Mode and confirm the **OFFLINE READY** pill.
2. Use **Quick-Test Demo Simulator (Airplane Mode)** for genuine, repeat owner, expired, short-dated, swapped tag, duplicate box, recalled batch, and degraded OCR scenarios.
3. Open the Alerts tab and run **Demo: Simulate Retroactive Clone/Recall Sync** to preview queue drain, SQLite status upgrade, OS notification, and bilingual warning banner.
