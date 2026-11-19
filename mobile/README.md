# TriProof AI Scanner (`tripoof-app`)

Offline-first cross-platform Flutter application for **Android** and **iOS** enabling Bangladeshi factory QA teams, customs inspectors, and global end consumers to cryptographically verify product authenticity in **under 50 milliseconds with zero internet connection (Airplane Mode)**, read degraded or curved packaging labels via on-device ML Kit Vision AI, persist SQLCipher-encrypted audit logs, and automatically cross-check & receive retroactive clone/recall alerts when connectivity returns.

---

## Key Capabilities

- **<50ms Offline Ed25519 Cryptography:** Verifies `{FactoryID}-{MachineID}-{BatchID}-{UnitNumber}|{ProdDate}|{ExpDate}` against the inner private Base64 Ed25519 signature using `UNIVERSAL_PUBLIC_VERIFICATION_KEY` stored in hardware-backed `flutter_secure_storage` (Android Keystore / iOS Secure Enclave). Measured ~**2.5–3ms** in AOT builds.
- **Anti-Clock-Rollback Protection (High-Water Mark):** Persists `last_known_verified_timestamp` in hardware keystore, advancing monotonically on app open, GPS satellite time, verified scans, and cloud syncs. Rejects any scan if `DateTime.now() < last_known_verified_timestamp`.
- **On-Device Vision AI & 2-Step Guided Scanner:** Uses `camera` + `google_mlkit_barcode_scanning` + `google_mlkit_text_recognition` 100% on-device with curved/smudged OCR label repair (`OcrSerialNormalizer`), physical scratch-off & micro-dot seal inspection prompt, chime audio, and double-buzz alarm vibration.
- **Encrypted SQLite (`sqflite_sqlcipher`) & Store-and-Forward Sync:**
  - `scan_history` (offline & cloud cross-check verdicts, GPS, device token, latency)
  - `cached_blacklist` (strict `<50KB` offline recalled batch & clone cache)
  - `notifications_inbox` (bilingual retroactive clone & precision recall notices)
  - Listens to `connectivity_plus` to upload `pending_sync` records to `POST /api/v1/verify/online-crosscheck`, pull `GET /api/v1/notifications/{device_token}` + `<50KB` blacklist deltas, and trigger local OS push notifications (`flutter_local_notifications`) + in-app warning banners.
- **Bilingual UI (English & বাংলা):** Persistent `EN / বাংলা` toggle, `OFFLINE READY` / `ONLINE SYNCED` status pill, Consumer Unboxing vs. Factory Packing QA (`>=45` days shelf life + pallet duplicate guard), Ownership Transfer QR (`qr_flutter`), and an Airplane Mode **Quick-Test Demo Simulator**.

---

## Project Structure

```text
lib/
├── main.dart                         # App bootstrap & 3-tab shell
├── core/
│   ├── constants/                    # Seeded Ed25519 key, 50KB budget, 45d threshold, theme
│   ├── l10n/                         # Full EN / বাংলা localization dictionary
│   └── utils/                        # Curved-label OCR normalizer & CameraImage -> InputImage
├── domain/
│   ├── enums/                        # AppLanguage, ScanMode, OfflineStatus, CloudStatus
│   └── models/                       # ParsedOuterSerial, ScanRecord, BlacklistEntry, AppNotification, VerificationResult
├── data/
│   ├── repositories/
│   │   ├── verification_repository.dart  # <50ms 5-stage verifyOffline(outer, inner) pipeline
│   │   └── sync_repository.dart          # connectivity_plus listener & retroactive alert loop
│   └── services/
│       ├── secure_keystore_service.dart  # Android Keystore / iOS Secure Enclave wrapper
│       ├── crypto_service.dart           # In-isolate Ed25519 verify, SHA-256 unit_hash, Ownership Transfer QR
│       ├── database_helper.dart          # SQLCipher encrypted SQLite (3 tables + RAM mirrors)
│       ├── feedback_service.dart         # Audio chime/alarm + vibration patterns
│       ├── location_time_service.dart    # Non-blocking GPS + satellite clock High-Water Mark
│       ├── notification_service.dart     # flutter_local_notifications OS push alerts
│       ├── api_client.dart               # REST client for cross-check, notifications, blacklist delta
│       └── demo_simulator_service.dart   # Airplane Mode Ed25519 QA test vectors
└── ui/
    ├── view_models/                  # AppViewModel (ChangeNotifier)
    ├── core/widgets/                 # TopStatusBar, RetroactiveAlertBanner, VerificationResultCard, TransferOwnershipSheet
    └── features/                     # HomeScannerScreen, GuidedScannerScreen, ScanHistoryScreen, AlertsCenterScreen
```

---

## Getting Started

### Prerequisites
- **Flutter SDK** `3.50.0+` (Dart `>=3.12.0`)
- **Android:** `compileSdk 37`, `minSdk >= 23` (Android 6.0+), Android Studio / NDK
- **iOS:** `iOS 15.0+` deployment target with Xcode (macOS)

### Setup, Test & Run
```sh
git clone https://github.com/RugalSenpai/tripoof-app.git
cd tripoof-app

flutter pub get
flutter analyze
flutter test
```

Run on an attached Android/iOS device or emulator:
```sh
flutter run
```

Build a release Android APK (split per ABI for smaller downloads):
```sh
flutter build apk --release --split-per-abi
```

### Compile-time configuration (`--dart-define`)

All configuration is baked in at build time via `String.fromEnvironment` /
`int.fromEnvironment` in `lib/core/constants/app_constants.dart`:

| Define | Default | Purpose |
| --- | --- | --- |
| `API_BASE_URL` | `http://triproof.local` | Base URL for cloud cross-check & feeds. |
| `UNIVERSAL_PUBLIC_KEY` | seeded Ed25519 key (Base64, 32 bytes) | Legacy `K00` verification key when the key ring is not yet provisioned. |
| `BUILD_EPOCH_MS` | `0` | Minimum build epoch (UTC milliseconds). The keystore High-Water Mark is floored to this value to block replay of stale builds. |

Example release build:
```sh
flutter build apk --release \
  --dart-define=API_BASE_URL=https://api.triproof.global \
  --dart-define=UNIVERSAL_PUBLIC_KEY=a4dFK0VuPZWbOIMTcz0ZWFt4JMBZkhN9pFcxuzeaji4= \
  --dart-define=BUILD_EPOCH_MS=1763510400000
```

### Cloud endpoints

- `POST {API_BASE_URL}/api/v1/verify/online-crosscheck` — uploads `records` (also accepted as `logs`); each record carries a monotonic `scan_seq`. Response verdicts are read from top-level `results` with `server_time`.
- `GET {API_BASE_URL}/api/v1/notifications/{device_token}` — public endpoint (no JWT); bilingual retroactive alerts.
- `GET {API_BASE_URL}/api/v1/blacklist/delta` — `<50KB` payload with `entries` and an optional `bloom_filter` (`bits_b64`, `m_bits`, `k_hashes`, `seed`). A bloom miss is a definitive negative; a hit still requires the exact `cached_blacklist` check.
- `GET {API_BASE_URL}/.well-known/triproof-key.json` — public versioned Ed25519 key ring (`key_ring`: `key_version`, `public_key_base64`, `status`). Legacy `public_key_base64` responses are mapped to `K00`. No JWT required.

Smart payloads (`{scheme}://{host}/v/...`) are URL-stripped, optional `KNN-` key prefixes and trailing `|DPP:XXXXXXXX` digests are extracted, and the resulting canonical string is the exact Ed25519-signed message. Sealed master-carton QR payloads (`CTN-...|{merkleRoot}|{unitCount}|{signature}`) are verified fully offline against the versioned key ring.

---

## Testing Offline & Retroactive Sync Without Hardware Labels

1. Turn on **Airplane Mode** (or disable Wi-Fi/Mobile data) to verify the `OFFLINE READY` pill.
2. Tap **Quick-Test Demo Simulator (Airplane Mode)** on the Home screen to test real Ed25519 verification across *Genuine*, *Repeat Owner*, *Expired*, *Short-Dated (<45d Factory QA)*, *Swapped Tag*, *Duplicate Box*, *Recalled Batch*, and *Degraded OCR* scenarios.
3. Open the **Alerts** tab and tap **Demo: Simulate Retroactive Clone/Recall Sync** to preview the store-and-forward queue drain, SQLite status upgrade, OS push notification, and bilingual warning banner.
