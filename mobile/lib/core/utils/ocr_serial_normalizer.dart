import 'package:triproof_ai_scanner/core/utils/smart_payload_parser.dart';
import 'package:triproof_ai_scanner/domain/models/parsed_outer_serial.dart';

/// On-device Vision AI post-processor that repairs common OCR glitches on
/// degraded, smudged, or curved packaging labels before cryptographic check.
///
/// Note: Normalization never weakens security. Any reconstructed candidate
/// must still pass exact Ed25519 verification against the 64-byte inner tag.
abstract final class OcrSerialNormalizer {
  static final RegExp _strictPattern = RegExp(
    r'([A-Za-z0-9_.-]+-[A-Za-z0-9_.-]+-[A-Za-z0-9_.-]+-[A-Za-z0-9_.-]+)\|(\d{4}-\d{2}-\d{2})\|(\d{4}-\d{2}-\d{2})',
  );

  static final RegExp _fuzzyPattern = RegExp(
    r'([A-Za-z0-9_.-]+[\s\-–—_]+[A-Za-z0-9_.-]+[\s\-–—_]+[A-Za-z0-9_.-]+[\s\-–—_]+[A-Za-z0-9_.-]+)\s*[|Iil/!\\]\s*([0-9OSlI]{4}[\-–—][0-9OSlI]{2}[\-–—][0-9OSlI]{2})\s*[|Iil/!\\]\s*([0-9OSlI]{4}[\-–—][0-9OSlI]{2}[\-–—][0-9OSlI]{2})',
  );

  /// Attempts to extract and repair a canonical outer serial from raw OCR or
  /// barcode text [rawText]. Returns `null` if no valid structure is found.
  static String? extractAndNormalize(String rawText) {
    if (rawText.trim().isEmpty) return null;

    // 0. Sealed master-carton QR payload (CTN-...|root|count|signature).
    final carton = SmartPayloadParser.tryParseMasterCarton(rawText);
    if (carton != null) return carton.toPayloadString();

    // 0b. Hybrid Smart URL / versioned KNN / DPP payloads. The canonical
    // (URL-stripped) string is already exact, so keep it byte-for-byte intact.
    final smart = SmartPayloadParser.tryParse(rawText);
    if (smart != null &&
        ParsedOuterSerial.tryParse(smart.canonicalOuter) != null) {
      return smart.canonicalOuter;
    }

    // 1. Direct match first (clean barcode / crisp OCR).
    final direct = _strictPattern.firstMatch(rawText.trim());
    if (direct != null) {
      final candidate = direct.group(0)!;
      if (ParsedOuterSerial.tryParse(candidate) != null) {
        return candidate;
      }
    }

    // 2. Collapse curved-surface line breaks and normalize Unicode dashes.
    final collapsed = rawText
        .replaceAll('\r\n', ' ')
        .replaceAll('\n', ' ')
        .replaceAll('–', '-')
        .replaceAll('—', '-')
        .replaceAll(RegExp(r'\s+'), ' ')
        .trim();

    final directCollapsed = _strictPattern.firstMatch(collapsed);
    if (directCollapsed != null) {
      final candidate = directCollapsed.group(0)!;
      if (ParsedOuterSerial.tryParse(candidate) != null) {
        return candidate;
      }
    }

    // 3. Fuzzy repair for degraded dot-matrix ink (e.g. | -> I/l, 0 -> O in dates).
    final fuzzy = _fuzzyPattern.firstMatch(collapsed);
    if (fuzzy != null) {
      final identityRaw = fuzzy.group(1)!;
      final prodRaw = _repairDateDigits(fuzzy.group(2)!);
      final expRaw = _repairDateDigits(fuzzy.group(3)!);

      final identityTokens = identityRaw
          .split(RegExp(r'[\s\-_]+'))
          .where((t) => t.isNotEmpty)
          .toList();

      if (identityTokens.length >= 4) {
        final factoryId = identityTokens[0].toUpperCase();
        final machineId = identityTokens[1].toUpperCase();
        final unitNum = identityTokens.last.toUpperCase();
        final batchId =
            identityTokens.sublist(2, identityTokens.length - 1).join('-').toUpperCase();
        final candidate =
            '$factoryId-$machineId-$batchId-$unitNum|$prodRaw|$expRaw';
        if (ParsedOuterSerial.tryParse(candidate) != null) {
          return candidate;
        }
      }
    }

    return null;
  }

  /// Cleans Base64 signature text extracted from inner QR/DataMatrix scans.
  static String normalizeInnerSignature(String rawInner) {
    var cleaned = rawInner.trim().replaceAll(RegExp(r'\s+'), '');
    if (cleaned.startsWith('TP1:')) {
      cleaned = cleaned.substring(4);
    }
    return cleaned;
  }

  static String _repairDateDigits(String dateToken) {
    final normalized = dateToken
        .replaceAll('O', '0')
        .replaceAll('o', '0')
        .replaceAll('I', '1')
        .replaceAll('l', '1')
        .replaceAll('S', '5')
        .replaceAll('s', '5');
    return normalized;
  }
}
