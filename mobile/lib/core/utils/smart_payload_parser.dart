/// A canonical, signed outer-serial payload extracted from a raw QR/barcode/OCR
/// reading (which may be wrapped in a Hybrid Smart URL).
///
/// [canonicalOuter] is the exact string that was signed — the smart URL prefix
/// is removed, but a leading key version (`KNN-`) and trailing DPP digest
/// (`|DPP:XXXXXXXX`) are preserved because they are part of the signature.
class ParsedSmartPayload {
  /// Creates an immutable [ParsedSmartPayload].
  const ParsedSmartPayload({
    required this.canonicalOuter,
    required this.keyId,
    required this.dppDigest,
  });

  /// Exact signed serial string without the `{scheme}://{host}/v/` wrapper.
  final String canonicalOuter;

  /// Ed25519 key version that signed [canonicalOuter] (`K00` when absent).
  final String keyId;

  /// Upper-case 8-hex DPP provenance digest, or an empty string when absent.
  final String dppDigest;

  /// Whether this payload carries an explicit key version prefix.
  bool get isVersioned => keyId != 'K00';

  /// Whether this payload carries a DPP provenance digest.
  bool get hasDppDigest => dppDigest.isNotEmpty;
}

/// Parsed contents of a sealed master-carton QR payload:
/// `CTN-...|{merkleRoot}|{unitCount}|{signature}`.
class MasterCartonPayload {
  /// Creates an immutable [MasterCartonPayload].
  const MasterCartonPayload({
    required this.cartonSerial,
    required this.merkleRoot,
    required this.unitCount,
    required this.signature,
  });

  /// Master carton identifier (`CTN-{KEY_VER}-{Factory}-{Batch}-{Sequence}`).
  final String cartonSerial;

  /// Merkle root over the contained unit hashes.
  final String merkleRoot;

  /// Number of sealed units in the carton.
  final int unitCount;

  /// Base64 Ed25519 signature over [signedMessage].
  final String signature;

  /// Exact UTF-8 message bound by [signature].
  String get signedMessage => '$cartonSerial|$merkleRoot|$unitCount';

  /// Reconstructs the canonical QR payload string.
  String toPayloadString() =>
      '$cartonSerial|$merkleRoot|$unitCount|$signature';

  /// Key version inferred from the carton serial (`K00` when absent).
  String get keyId {
    final parts = cartonSerial.split('-');
    if (parts.length > 1 && RegExp(r'^K\d{2}$').hasMatch(parts[1])) {
      return parts[1].toUpperCase();
    }
    return 'K00';
  }
}

/// Strips Hybrid Smart URL wrappers, key-version prefixes, DPP suffixes, and
/// master-carton QR payloads without ever altering the signed serial string.
abstract final class SmartPayloadParser {
  static final RegExp _smartUrlPattern = RegExp(
    r'^[a-z][a-z0-9+.\-]*://[^/]+/v/(.+)$',
    caseSensitive: false,
    dotAll: true,
  );
  static final RegExp _keyVersionPattern = RegExp(r'^K\d{2}$');
  static final RegExp _dppSuffixPattern =
      RegExp(r'\|DPP:([A-Fa-f0-9]{8})$');
  static final RegExp _cartonSerialPattern =
      RegExp(r'^CTN-[A-Za-z0-9-]{6,60}$');

  /// Removes a leading `{scheme}://{host}/v/` prefix and URL-decodes the
  /// remaining serial. Non-URL inputs are returned trimmed and unchanged.
  static String stripSmartPrefix(String input) {
    final trimmed = input.trim();
    final match = _smartUrlPattern.firstMatch(trimmed);
    if (match == null) return trimmed;
    final raw = match.group(1)!;
    try {
      return Uri.decodeComponent(raw);
    } on FormatException {
      return raw;
    }
  }

  /// Parses [input] into a [ParsedSmartPayload], or `null` when empty.
  ///
  /// Structural validity of the inner serial is intentionally left to
  /// `ParsedOuterSerial.tryParse`/`OcrSerialNormalizer` so this parser can be
  /// used as a cheap preprocessing step for OCR-repair flows as well.
  static ParsedSmartPayload? tryParse(String input) {
    final canonical = stripSmartPrefix(input);
    if (canonical.isEmpty) return null;

    var working = canonical;
    var keyId = 'K00';
    var dppDigest = '';

    final dppMatch = _dppSuffixPattern.firstMatch(working);
    if (dppMatch != null) {
      dppDigest = dppMatch.group(1)!.toUpperCase();
      working = working.substring(0, dppMatch.start);
    }

    final firstDash = working.indexOf('-');
    if (firstDash > 0) {
      final token = working.substring(0, firstDash);
      if (_keyVersionPattern.hasMatch(token)) {
        keyId = token.toUpperCase();
      }
    }

    return ParsedSmartPayload(
      canonicalOuter: canonical,
      keyId: keyId,
      dppDigest: dppDigest,
    );
  }

  /// Returns `true` when [input] looks like a sealed master-carton payload.
  static bool looksLikeMasterCarton(String input) {
    return stripSmartPrefix(input).startsWith('CTN-');
  }

  /// Parses a master-carton QR payload into a [MasterCartonPayload].
  static MasterCartonPayload? tryParseMasterCarton(String input) {
    final canonical = stripSmartPrefix(input);
    final parts = canonical.split('|');
    if (parts.length != 4) return null;

    final serial = parts[0].trim();
    final root = parts[1].trim();
    final count = int.tryParse(parts[2].trim());
    final signature = parts[3].trim();

    if (!_cartonSerialPattern.hasMatch(serial) ||
        root.isEmpty ||
        count == null ||
        count < 1 ||
        signature.isEmpty) {
      return null;
    }

    return MasterCartonPayload(
      cartonSerial: serial,
      merkleRoot: root,
      unitCount: count,
      signature: signature,
    );
  }
}
