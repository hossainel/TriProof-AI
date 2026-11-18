# TriProof AI Scanner

Native Android 8.0+ client (Kotlin, XML Views, ViewBinding, target API 35). A fresh installation can scan in airplane mode: the Ed25519 verification key and both ML Kit recognition models ship in the APK. An offline success verifies the label pair, dates and the last available warning list. A copied **valid** label on a different phone requires an authenticated cloud cross-check to detect.

## Run

Open **this directory** in Android Studio, or use JDK 17, an Android SDK with platform 35, and the checked-in Gradle wrapper:

```sh
./gradlew :app:assembleDebug :app:testDebugUnitTest
./gradlew :app:installDebug
```

The APK is `app/build/outputs/apk/debug/app-debug.apk`. Offline consumers can skip sign-in. Factory and buyer accounts use the same credentials as Laravel; no passwords are bundled or saved. Tap **Sign in** on the scanner toolbar to upload queued scans later. An expired JWT leaves the outbox intact for the next sign-in.

To download a build from GitHub, open a successful **Actions → Android scanner** run for `main` and download `triproof-ai-scanner-<commit>` under **Artifacts**. The archive contains the installable debug APK and the unsigned release AAB and is retained for 14 days. The bundle defaults to the local HTTPS host and the bundled public key; set the `TRIPROOF_API_BASE_URL` and `TRIPROOF_PUBLIC_KEY` repository variables to build it for another deployment.

The default API is `http://triproof.local/api/v1/`. Android does not inherit the workstation’s `/etc/hosts`. For the standard Android emulator and the existing name-based Apache site:

```sh
./gradlew :app:installDebug -PAPI_BASE_URL=http://10.0.2.2/api/v1/ -PDEBUG_VHOST=triproof.local
```

A USB phone can instead use `adb reverse tcp:8080 tcp:80` and `-PAPI_BASE_URL=http://127.0.0.1:8080/api/v1/ -PDEBUG_VHOST=triproof.local`. A networked phone needs DNS pointing at the server’s reachable LAN address (not the phone’s loopback). Deploy the Laravel routes from this checkout for periodic blacklist refresh and handoff tickets.

For release, set `-PAPI_BASE_URL=https://your-deployment.example/api/v1/` and `-PTRIPROOF_PUBLIC_KEY=...` to the deployment’s independently verified, Base64-encoded **public** key. Release disables cleartext; the debug virtual-host override is not used. Generate a signed release in Android Studio or your signing pipeline. The repository contains no release keystore or HQ signing secret.

`./gradlew :app:bundleRelease` produces `app/build/outputs/bundle/release/app-release.aab`. The R8-minified bundle build was verified with an HTTPS base URL; the local bundle has no distribution signing credentials.

## Scan, sync, hand off

1. Choose consumer or factory mode. Point at the outer text/barcode, inspect the physical seal, and confirm it was intact before scanning the inner QR/DataMatrix. Flashlight and manual input are available. Location is opt-in, foreground-only, and stale or mock fixes are not attached.
2. Verification runs locally: monotonic high-water check, strict serial/calendar parsing, SHA-256 and raw detached Ed25519, inclusive expiry (and production through production + 45 days for packing), cached recalls/clones, same-session duplicates, then same-device repeats. The decision and history insert share one Room transaction. **New packing session** resets only the session scope.
3. WorkManager sends `logs` batches with stable `sync_uuid`s, not a naked JSON array. It matches results by UUID even if the server sorts them by time. Connected work also drains offline read receipts, paginates the device inbox and refreshes the gzip+Base64 blacklist. Truncated snapshots retain older warnings. Clock-rollback records stay local: an invalid time must not claim ownership. Network/429 failures retain work and back off.
4. Clone/recall updates are persistent in History and Alerts. Android notifications require permission on Android 13+; declining it does not suppress the in-app warnings. Marking the inbox read works offline. The language toggle applies English or বাংলা to the current activity and system alerts.
5. For resale, the **cloud-confirmed owner** opens a positive result (including from History) and requests a **Transfer ownership QR** online. It contains the outer serial and a one-time ticket, never the private label, device token or JWT. The recipient scans this QR as step one and scans the physical inner label as step two. The ownership change takes effect only after successful sync within 24 hours. Generating a new ticket invalidates the previous one.

**Demo simulator:** the three vectors use a separate public key and a fixed training date (15 January 2026). They run real signature/date checks without adding history, issuing handoffs or sending synthetic claims. Normal scanning never trusts the demo key.

## Project map

```text
android/
├── settings.gradle.kts, build.gradle.kts, gradle.properties, gradlew, gradlew.bat
├── gradle/{libs.versions.toml,wrapper/}
└── app/
    ├── build.gradle.kts, proguard-rules.pro, schemas/
    └── src/
        ├── main/AndroidManifest.xml
        ├── main/java/ai/triproof/scanner/
        │   ├── ScannerApp.kt                    # graph, network callback, periodic work
        │   ├── crypto/{Label,KeystoreManager,OfflineVerificationEngine}.kt
        │   ├── data/ScannerRepository.kt
        │   ├── data/local/TriProofDatabase.kt   # 3 entities, DAOs and indexes
        │   ├── data/remote/{TriProofApi,BlacklistCodec}.kt
        │   ├── scanner/{CameraXAnalyzer,Demo,Feedback}.kt
        │   ├── sync/SyncScansWorker.kt
        │   ├── ui/{Models,Ui,LoginActivity,MainActivity,ScannerFragment,Sheets,Lists}.kt
        │   └── util/Alerts.kt
        ├── main/res/
        │   ├── layout/{activity_login,activity_main,fragment_scanner,fragment_history,fragment_notifications}.xml
        │   ├── layout/{item_scan_history,item_notification,bottom_sheet_demo_simulator,bottom_sheet_verification_result}.xml
        │   ├── layout/{dialog_manual,dialog_transfer}.xml
        │   ├── values/strings.xml, values-bn/strings.xml, values/styles.xml
        │   └── drawable/, menu/navigation.xml, xml/{locales_config,no_backup}.xml
        ├── test/                                # libsodium vectors, dates, parser, blacklist limits
        └── androidTest/                         # real Room/Keystore, sync, UI and bundled vision
```

## Trust, latency and verification

Login requires the login key **and** `/.well-known/triproof-key.json` to match the APK pin and its SHA-256 fingerprint. The blacklist digest covers the compressed bytes; the encoded input and expanded output are bounded. Its authenticity in transit relies on TLS. Rotate the pin with a signed app update. Raw signature checks use Bouncy Castle’s lightweight `Ed25519Signer`, reusing the decoded public key; no global JCA provider is replaced.

Keystore-backed encrypted preferences hold the JWT, public key, install identifier and high-water timestamp. Scans advance the in-memory/encrypted watermark; the Room ledger commits before returning a verdict. Startup reconciles the last non-rollback ledger time with the encrypted watermark before enabling verification, including if the previous process died during an asynchronous preferences write. The device handle combines a random installation UUID and an HMAC under a non-exportable Android Keystore key. StrongBox is preferred when available; hardware backing depends on the device. The Laravel protocol uses the resulting handle as a bearer identifier, not remote hardware attestation. Clearing app data creates a new identity. Room is app-private and excluded from backups.

```sh
./gradlew :app:testDebugUnitTest
./gradlew :app:connectedDebugAndroidTest        # connected test phone/emulator
./gradlew :app:scannerApi35DebugAndroidTest -Pandroid.testInstrumentationRunnerArguments.airplane=true
```

The last command uses a disposable API 35 managed emulator with KVM. The vision and local verification tests put that emulator into airplane mode and assert it has no active network. `OfflineDeviceTest` logs warm p50/p95 (`TriProofTiming`) for 45 distinct packing labels and 30 repeats, including the encrypted clock update and Room transaction. It excludes frame acquisition, OCR, UI animation, network and work scheduling. **50 ms is a device acceptance target, not a guarantee**. Before field release, measure a release build on the intended QA phones, including cold start; try curved, scuffed and low-contrast labels in airplane mode; then reconnect to check late alerts. A display of “0ms network” means the decision made no network request.

The API 35 debug acceptance run passed **5 JVM tests and 7 instrumented tests**. With radios off, distinct packing labels measured **18.4 ms median / 36.5 ms p95 / 62.6 ms maximum**; same-device repeats measured **11.5 ms median / 28.5 ms p95**. These are measurements from the emulator, not a worst-case guarantee for a handset. Android lint passed. The API suite passed 47 tests (207 assertions), including blacklist refresh, owner handoff, replay and clone/recall behavior.
