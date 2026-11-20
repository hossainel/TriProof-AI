import 'dart:convert';

import 'package:cryptography/cryptography.dart';
import 'package:cryptography/dart.dart';
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/domain/models/blacklist_entry.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';
import 'package:triproof_ai_scanner/data/services/api_client.dart';

/// Offline simulator scenarios available in Airplane Mode for QA, customs,
/// and consumer verification demos.
enum DemoScenario {
  /// Valid Ed25519 signature, fresh prod date, +365 days expiry.
  genuineProduct,

  /// Valid Ed25519 signature, expired 5 days ago.
  expiredProduct,

  /// Valid Ed25519 signature, +20 days expiry (passes Consumer Mode,
  /// fails Factory Packing QA 45-day threshold).
  shortDatedFactoryQaFail,

  /// Valid outer serial from Box A paired with Box B's inner signature.
  swappedTagMismatch,

  /// Valid signature belonging to a batch in the <50KB cached blacklist.
  recalledCachedBatch,

  /// Degraded / curved OCR label string showing on-device repair + Ed25519 pass.
  degradedCurvedOcrLabel,
}

/// Generated test tag pair ready for `VerificationRepository.verifyOffline`.
class DemoTagPair {
  /// Creates a [DemoTagPair].
  const DemoTagPair({
    required this.outerSerialOrOcr,
    required this.innerSerial,
    required this.isDegradedOcrInput,
  });

  /// Outer serial (or raw degraded OCR string when [isDegradedOcrInput] is true).
  final String outerSerialOrOcr;

  /// Base64 64-byte Ed25519 signature from the inner private seal QR.
  final String innerSerial;

  /// Whether [outerSerialOrOcr] simulates smudged OCR needing normalization.
  final bool isDegradedOcrInput;
}

/// Generates genuine Ed25519-signed test vectors completely offline so the
/// Quick-Test Demo Simulator exercises the real cryptographic verification
/// pipeline in Airplane Mode.
///
/// NOTE: In production factory deployments, the private signing seed resides
/// strictly inside the factory Hardware Security Module (HSM), never on client
/// devices. This deterministic simulator pair is test-only and is provisioned
/// into the local key ring solely for offline demo and QA testing.
class DemoSimulatorService {
  /// Deterministic 32-byte test seed used by the offline demo simulator.
  static const String _demoHsmSeedBase64 =
      'rbwO0tAUSJalUz7WXys5GHZzkbnVfcvDq1Uzu/nVINk=';

  /// Public key derived from [_demoHsmSeedBase64]. Used only by the offline
  /// demo simulator; production verification uses the server-provisioned ring.
  static const String demoPublicKeyBase64 =
      '53wO7zDcTFTwVE4Gj43WYrB7WmlJ5KzYvQXc2jPd+54=';

  final Ed25519 _ed25519 = DartEd25519(sha512: const DartSha512());
  int _unitSequence = 482;

  /// Generates a realistic [(outerSerial, innerSerial)] pair for [scenario]
  /// anchored to [referenceUtc] (defaulting to current UTC time).
  Future<DemoTagPair> generateScenario(
    DemoScenario scenario, {
    DateTime? referenceUtc,
    String? fixedOuterSerial,
  }) async {
    final now = (referenceUtc ?? DateTime.now()).toUtc();
    final keyPair = await _ed25519.newKeyPairFromSeed(
      base64Decode(_demoHsmSeedBase64),
    );

    _unitSequence++;
    final seq = _unitSequence.toString().padLeft(6, '0');
    final prodDate = _formatDate(now.subtract(const Duration(days: 10)));

    switch (scenario) {
      case DemoScenario.genuineProduct:
        final outer =
            fixedOuterSerial ??
            'BD01-M04-B202611-$seq|$prodDate|${_formatDate(now.add(const Duration(days: 365)))}';
        final sig = await _signOuter(outer, keyPair);
        return DemoTagPair(
          outerSerialOrOcr: outer,
          innerSerial: sig,
          isDegradedOcrInput: false,
        );

      case DemoScenario.expiredProduct:
        final exp = _formatDate(now.subtract(const Duration(days: 5)));
        final prod = _formatDate(now.subtract(const Duration(days: 370)));
        final outer = 'BD02-M02-B202511-$seq|$prod|$exp';
        final sig = await _signOuter(outer, keyPair);
        return DemoTagPair(
          outerSerialOrOcr: outer,
          innerSerial: sig,
          isDegradedOcrInput: false,
        );

      case DemoScenario.shortDatedFactoryQaFail:
        final exp = _formatDate(now.add(const Duration(days: 20)));
        final prod = _formatDate(now.subtract(const Duration(days: 345)));
        final outer = 'BD03-M01-B202512-$seq|$prod|$exp';
        final sig = await _signOuter(outer, keyPair);
        return DemoTagPair(
          outerSerialOrOcr: outer,
          innerSerial: sig,
          isDegradedOcrInput: false,
        );

      case DemoScenario.swappedTagMismatch:
        final exp = _formatDate(now.add(const Duration(days: 365)));
        final boxAOuter = 'BD01-M04-B202611-$seq|$prodDate|$exp';
        final boxBOther = 'BD01-M04-B202611-999$seq|$prodDate|$exp';
        // Box A outer label paired with Box B's cryptographic inner tag!
        final boxBSig = await _signOuter(boxBOther, keyPair);
        return DemoTagPair(
          outerSerialOrOcr: boxAOuter,
          innerSerial: boxBSig,
          isDegradedOcrInput: false,
        );

      case DemoScenario.recalledCachedBatch:
        final exp = _formatDate(now.add(const Duration(days: 300)));
        final outer = 'BD04-M07-B2026RECALL-$seq|$prodDate|$exp';
        final sig = await _signOuter(outer, keyPair);
        return DemoTagPair(
          outerSerialOrOcr: outer,
          innerSerial: sig,
          isDegradedOcrInput: false,
        );

      case DemoScenario.degradedCurvedOcrLabel:
        final exp = _formatDate(now.add(const Duration(days: 400)));
        final canonicalOuter = 'BD01-M08-B202611-$seq|$prodDate|$exp';
        final sig = await _signOuter(canonicalOuter, keyPair);
        // Simulate curved/smudged dot-matrix OCR read with broken pipes and O/0 glitch.
        final smudgedProd = prodDate.replaceFirst('0', 'O');
        final degradedOcr = 'LOT: BD01-M08-B202611-$seq\nI $smudgedProd l $exp';
        return DemoTagPair(
          outerSerialOrOcr: degradedOcr,
          innerSerial: sig,
          isDegradedOcrInput: true,
        );
    }
  }

  Future<String> _signOuter(String outer, SimpleKeyPair keyPair) async {
    final signature = await _ed25519.sign(utf8.encode(outer), keyPair: keyPair);
    return base64Encode(signature.bytes);
  }

  static String _formatDate(DateTime utcDate) {
    final y = utcDate.year.toString().padLeft(4, '0');
    final m = utcDate.month.toString().padLeft(2, '0');
    final d = utcDate.day.toString().padLeft(2, '0');
    return '$y-$m-$d';
  }
}

/// Optional offline mock implementation of [CloudApiClient] used solely when the
/// user presses "Demo: Simulate Retroactive Clone/Recall Sync" to preview the
/// retroactive clone/recall alert feedback loop without deploying a backend.
class DemoMockCloudApiClient implements CloudApiClient {
  /// Creates a [DemoMockCloudApiClient].
  const DemoMockCloudApiClient();

  @override
  Future<CrossCheckBatchResponse> postOnlineCrossCheck({
    required String deviceToken,
    required List<ScanRecord> records,
  }) async {
    final now = DateTime.now().toUtc();
    final verdicts = <CrossCheckVerdictItem>[];

    for (var i = 0; i < records.length; i++) {
      final rec = records[i];
      if (!rec.offlineStatus.isGenuinePass) {
        verdicts.add(
          CrossCheckVerdictItem(
            syncUuid: rec.syncUuid,
            verdict: 'verified_clean',
          ),
        );
        continue;
      }

      // Flag the newest pending genuine scan as a clone detected elsewhere so
      // the user sees the full retroactive push notification & warning banner!
      if (i == records.length - 1) {
        verdicts.add(
          CrossCheckVerdictItem(
            syncUuid: rec.syncUuid,
            verdict: 'clone_detected',
            titleEn: 'RETROACTIVE CLONE ALERT',
            messageEn:
                'Warning: Serial ${rec.outerSerial} was already redeemed on another device in Chattogram. This product may be a clone.',
            titleBn: 'রেট্রোঅ্যাক্টিভ ক্লোন সতর্কতা',
            messageBn:
                'সতর্কতা: এই সিরিয়ালটি ইতিপূর্বে অন্য ডিভাইসে ব্যবহৃত হয়েছে — এটি একটি ক্লোন পণ্য (${rec.outerSerial})',
          ),
        );
      } else {
        verdicts.add(
          CrossCheckVerdictItem(
            syncUuid: rec.syncUuid,
            verdict: 'verified_clean',
          ),
        );
      }
    }

    return CrossCheckBatchResponse(verdicts: verdicts, serverTimeUtc: now);
  }

  @override
  Future<CloudFeedResponse> fetchNotificationsAndBlacklist({
    required String deviceToken,
  }) async {
    final now = DateTime.now().toUtc();
    return CloudFeedResponse(
      notifications: const [],
      blacklistEntries: [
        BlacklistEntry(
          serialOrBatch: 'B2026RECALL',
          reason: 'DGDA Precision Recall: Packaging seal lot compromised',
          updatedAt: now,
        ),
      ],
      serverTimeUtc: now,
    );
  }

  @override
  Future<Map<String, String>> fetchPublicKeyRing() async {
    return const {'K00': AppConstants.defaultUniversalPublicKeyBase64};
  }
}
