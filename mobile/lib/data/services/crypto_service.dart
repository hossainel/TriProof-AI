import 'dart:convert';
import 'dart:math';

import 'package:cryptography/cryptography.dart';
import 'package:cryptography/dart.dart';
import 'package:triproof_ai_scanner/core/utils/smart_payload_parser.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';

/// Result of a single Ed25519 signature check with measured latency.
class Ed25519VerifyOutcome {
  /// Creates an [Ed25519VerifyOutcome].
  const Ed25519VerifyOutcome({
    required this.isValid,
    required this.elapsedMicroseconds,
    this.dppMismatch = false,
  });

  /// Whether the 64-byte Ed25519 signature is mathematically valid.
  final bool isValid;

  /// Execution duration in microseconds.
  final int elapsedMicroseconds;

  /// Whether failure was caused by a mismatched DPP provenance digest.
  final bool dppMismatch;
}

/// Offline Ed25519 verification, SHA-256 unit hashing, and ownership transfer
/// payload builder.
class CryptoService {
  /// Creates a [CryptoService] bound to [keystoreService].
  CryptoService({required SecureKeystoreService keystoreService})
      : _keystoreService = keystoreService;

  final SecureKeystoreService _keystoreService;
  // In-isolate pure-Dart Ed25519 + SHA-256 avoids MethodChannel / isolate
  // spawn latency so offline verification consistently completes in <50ms.
  final Ed25519 _ed25519 = DartEd25519(sha512: const DartSha512());
  final Sha256 _sha256 = const DartSha256();
  final Random _csprng = Random.secure();

  /// Pre-loads the universal Ed25519 public key and warms up Curve25519
  /// verification & SHA-256 primitives so real product scans stay well <50ms.
  Future<void> warmUp() async {
    final publicKeyBytes = await _keystoreService.getUniversalPublicKeyBytes();
    final key = SimplePublicKey(publicKeyBytes, type: KeyPairType.ed25519);
    await _ed25519.verify(
      const [0],
      signature: Signature(List<int>.filled(64, 0), publicKey: key),
    );
    await _sha256.hash(const [0]);
  }

  /// Verifies that [innerSerialBase64] is a valid 64-byte Ed25519 signature
  /// over UTF-8 bytes of [outerSerial].
  ///
  /// The verification key is selected from the hardware keystore by [keyId]
  /// (`K00` by default), unless [overridePublicKeyBytes] is supplied in tests.
  /// When [expectedDppDigest] is non-empty, the `|DPP:XXXXXXXX` suffix embedded
  /// in [outerSerial] must match it exactly.
  Future<Ed25519VerifyOutcome> verifyEd25519({
    required String outerSerial,
    required String innerSerialBase64,
    String? keyId,
    String? expectedDppDigest,
    List<int>? overridePublicKeyBytes,
  }) async {
    final stopwatch = Stopwatch()..start();

    if (expectedDppDigest != null && expectedDppDigest.isNotEmpty) {
      final embeddedDpp = _extractDppDigest(outerSerial);
      if (embeddedDpp != null &&
          embeddedDpp.toUpperCase() != expectedDppDigest.trim().toUpperCase()) {
        stopwatch.stop();
        return Ed25519VerifyOutcome(
          isValid: false,
          elapsedMicroseconds: stopwatch.elapsedMicroseconds,
          dppMismatch: true,
        );
      }
    }

    final signatureBytes = _tryDecodeBase64Signature(innerSerialBase64);
    if (signatureBytes == null || signatureBytes.length != 64) {
      stopwatch.stop();
      return Ed25519VerifyOutcome(
        isValid: false,
        elapsedMicroseconds: stopwatch.elapsedMicroseconds,
      );
    }

    try {
      final publicKeyBytes = overridePublicKeyBytes ??
          await _keystoreService.getPublicKeyBytes(keyId ?? 'K00');
      final publicKey = SimplePublicKey(
        publicKeyBytes,
        type: KeyPairType.ed25519,
      );

      final messageBytes = utf8.encode(outerSerial);
      final signature = Signature(signatureBytes, publicKey: publicKey);

      final valid = await _ed25519.verify(
        messageBytes,
        signature: signature,
      );
      stopwatch.stop();
      return Ed25519VerifyOutcome(
        isValid: valid,
        elapsedMicroseconds: stopwatch.elapsedMicroseconds,
      );
    } catch (_) {
      stopwatch.stop();
      return Ed25519VerifyOutcome(
        isValid: false,
        elapsedMicroseconds: stopwatch.elapsedMicroseconds,
      );
    }
  }

  /// Verifies the Ed25519 signature of a sealed master carton over the exact
  /// `cartonSerial|merkleRoot|unitCount` message (Dart-only, no platform FFI).
  ///
  /// [keyRing] optionally maps `KNN` identifiers to Base64 public keys; when
  /// omitted the hardware keystore is queried. Any malformed input or key
  /// lookup failure yields an error-safe invalid outcome.
  Future<Ed25519VerifyOutcome> verifyMasterCarton(
    MasterCartonPayload payload, [
    Map<String, String>? keyRing,
  ]) async {
    final stopwatch = Stopwatch()..start();

    final signatureBytes = _tryDecodeBase64Signature(payload.signature);
    if (signatureBytes == null || signatureBytes.length != 64) {
      stopwatch.stop();
      return Ed25519VerifyOutcome(
        isValid: false,
        elapsedMicroseconds: stopwatch.elapsedMicroseconds,
      );
    }

    try {
      final keyId = payload.keyId;
      final encodedKey = keyRing?[keyId];
      final publicKeyBytes = encodedKey != null
          ? _decodePublicKey(encodedKey)
          : await _keystoreService.getPublicKeyBytes(keyId);
      final publicKey = SimplePublicKey(
        publicKeyBytes,
        type: KeyPairType.ed25519,
      );
      final signature = Signature(signatureBytes, publicKey: publicKey);

      final valid = await _ed25519.verify(
        utf8.encode(payload.signedMessage),
        signature: signature,
      );
      stopwatch.stop();
      return Ed25519VerifyOutcome(
        isValid: valid,
        elapsedMicroseconds: stopwatch.elapsedMicroseconds,
      );
    } catch (_) {
      stopwatch.stop();
      return Ed25519VerifyOutcome(
        isValid: false,
        elapsedMicroseconds: stopwatch.elapsedMicroseconds,
      );
    }
  }

  /// Computes a deterministic SHA-256 hex digest binding [outerSerial] and
  /// [innerSerial] into a compact `unit_hash` identifier.
  Future<String> computeUnitHash(String outerSerial, String innerSerial) async {
    final digest = await _sha256.hash(
      utf8.encode('$outerSerial|$innerSerial'),
    );
    return digest.bytes
        .map((b) => b.toRadixString(16).padLeft(2, '0'))
        .join();
  }

  /// Generates a structured JSON string for the "Transfer Ownership QR"
  /// feature when reselling or gifting a verified genuine item.
  Future<String> buildOwnershipTransferPayload({
    required String outerSerial,
    required String unitHash,
    required String currentDeviceToken,
    required DateTime timestampUtc,
  }) async {
    final nonceBytes = List<int>.generate(12, (_) => _csprng.nextInt(256));
    final nonce = base64UrlEncode(nonceBytes).replaceAll('=', '');
    final issuedAt = timestampUtc.toUtc().toIso8601String();

    final claimInput =
        '$outerSerial|$unitHash|$currentDeviceToken|$nonce|$issuedAt';
    final claimDigest = await _sha256.hash(utf8.encode(claimInput));
    final proofTag = base64UrlEncode(claimDigest.bytes.sublist(0, 16))
        .replaceAll('=', '');

    return jsonEncode({
      'protocol': 'TRIPROOF_TRANSFER_V1',
      'outer_serial': outerSerial,
      'unit_hash': unitHash,
      'from_device_token': currentDeviceToken,
      'nonce': nonce,
      'issued_at': issuedAt,
      'proof_tag': proofTag,
    });
  }

  String? _extractDppDigest(String outerSerial) {
    final match = RegExp(r'\|DPP:([A-Fa-f0-9]{8})\s*$')
        .firstMatch(SmartPayloadParser.stripSmartPrefix(outerSerial));
    return match?.group(1);
  }

  List<int> _decodePublicKey(String encoded) {
    final decoded = base64Decode(encoded.trim());
    if (decoded.length != 32) {
      throw StateError('Ed25519 public key must decode to exactly 32 bytes.');
    }
    return decoded;
  }

  List<int>? _tryDecodeBase64Signature(String raw) {
    var cleaned = raw.trim().replaceAll(RegExp(r'\s+'), '');
    if (cleaned.startsWith('TP1:')) {
      cleaned = cleaned.substring(4);
    }
    if (cleaned.isEmpty) return null;

    // Support both standard Base64 and Base64URL without padding.
    cleaned = cleaned.replaceAll('-', '+').replaceAll('_', '/');
    final rem = cleaned.length % 4;
    if (rem == 1) return null;
    if (rem > 0) {
      cleaned = cleaned.padRight(cleaned.length + (4 - rem), '=');
    }

    try {
      return base64Decode(cleaned);
    } on FormatException {
      return null;
    }
  }
}
