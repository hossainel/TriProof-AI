import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/core/utils/smart_payload_parser.dart';

/// Parsed components of an outer box public serial string.
///
/// Canonical format:
/// `{FactoryID}-{MachineID}-{BatchID}-{UnitNumber}|{ProdDate}|{ExpDate}`
///
/// Versioned/DPP format (v2):
/// `{KNN}-{FactoryID}-{MachineID}-{BatchID}-{UnitNumber}|{ProdDate}|{ExpDate}|DPP:{8HEX}`
///
/// Dates follow ISO-8601 `YYYY-MM-DD` (UTC calendar dates). A leading
/// `{scheme}://{host}/v/` smart-URL wrapper is stripped without changing the
/// exact signed string stored in [rawSerial].
class ParsedOuterSerial {
  /// Creates an immutable [ParsedOuterSerial].
  const ParsedOuterSerial({
    required this.rawSerial,
    required this.factoryId,
    required this.machineId,
    required this.batchId,
    required this.unitNumber,
    required this.prodDateIso,
    required this.expDateIso,
    required this.prodDateUtc,
    required this.expDateUtc,
    this.keyId = 'K00',
    this.dppDigest = '',
  });

  /// Exact raw outer serial string verified against the Ed25519 signature.
  final String rawSerial;

  /// Originating factory identifier (e.g. `BD01`).
  final String factoryId;

  /// Packaging line / printer machine identifier (e.g. `M04`).
  final String machineId;

  /// Production batch identifier (e.g. `B202611`).
  final String batchId;

  /// Unit sequence number within the batch (e.g. `000482`).
  final String unitNumber;

  /// Production date string in `YYYY-MM-DD`.
  final String prodDateIso;

  /// Expiry date string in `YYYY-MM-DD`.
  final String expDateIso;

  /// Parsed UTC start-of-day for production date.
  final DateTime prodDateUtc;

  /// Parsed UTC end-of-day (`23:59:59.999Z`) for expiry checks.
  final DateTime expDateUtc;

  /// Ed25519 key version embedded in the serial (`K00` for v1 labels).
  final String keyId;

  /// Upper-case 8-hex DPP provenance digest, or empty when absent.
  final String dppDigest;

  /// Decoded bilingual factory display name resolved from [factoryId].
  ({String en, String bn}) get factoryDisplayName =>
      AppConstants.resolveFactoryName(factoryId);

  /// Whether the serial carries an explicit v2 key version prefix.
  bool get isVersioned => keyId != 'K00';

  /// Whether the serial carries a DPP provenance digest suffix.
  bool get hasDppDigest => dppDigest.isNotEmpty;

  /// Parses [input] into a [ParsedOuterSerial], or returns `null` if malformed.
  static ParsedOuterSerial? tryParse(String input) {
    final smart = SmartPayloadParser.tryParse(input);
    if (smart == null) return null;

    final canonical = smart.canonicalOuter;
    final pipeParts = canonical.split('|');
    if (pipeParts.length != 3 && pipeParts.length != 4) return null;

    final dppPart = pipeParts.length == 4 ? pipeParts[3].trim() : '';
    if (pipeParts.length == 4) {
      final dppMatch = RegExp(r'^DPP:([A-Fa-f0-9]{8})$').firstMatch(dppPart);
      if (dppMatch == null) return null;
      final embeddedDigest = dppMatch.group(1)!.toUpperCase();
      if (smart.dppDigest.isNotEmpty && smart.dppDigest != embeddedDigest) {
        return null;
      }
    }

    var identityPart = pipeParts[0].trim();
    final prodStr = pipeParts[1].trim();
    final expStr = pipeParts[2].trim();

    var keyId = smart.keyId;
    final firstDash = identityPart.indexOf('-');
    if (firstDash > 0) {
      final prefix = identityPart.substring(0, firstDash);
      if (RegExp(r'^K\d{2}$').hasMatch(prefix)) {
        keyId = prefix.toUpperCase();
        identityPart = identityPart.substring(firstDash + 1);
      }
    }

    final segments = identityPart.split('-');
    if (segments.length < 4) return null;

    final factoryId = segments[0].trim();
    final machineId = segments[1].trim();
    final unitNumber = segments.last.trim();
    final batchId = segments.sublist(2, segments.length - 1).join('-').trim();

    final tokenPattern = RegExp(r'^[A-Za-z0-9_.-]+$');
    if (factoryId.isEmpty ||
        machineId.isEmpty ||
        batchId.isEmpty ||
        unitNumber.isEmpty ||
        !tokenPattern.hasMatch(factoryId) ||
        !tokenPattern.hasMatch(machineId) ||
        !tokenPattern.hasMatch(batchId) ||
        !tokenPattern.hasMatch(unitNumber)) {
      return null;
    }

    final prodDate = _parseStrictIsoDate(prodStr, endOfDay: false);
    final expDate = _parseStrictIsoDate(expStr, endOfDay: true);
    if (prodDate == null || expDate == null) return null;

    if (expDate.isBefore(prodDate)) return null;

    return ParsedOuterSerial(
      rawSerial: canonical,
      factoryId: factoryId,
      machineId: machineId,
      batchId: batchId,
      unitNumber: unitNumber,
      prodDateIso: prodStr,
      expDateIso: expStr,
      prodDateUtc: prodDate,
      expDateUtc: expDate,
      keyId: keyId,
      dppDigest: smart.dppDigest,
    );
  }

  static DateTime? _parseStrictIsoDate(String raw, {required bool endOfDay}) {
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(raw);
    if (match == null) return null;

    final year = int.tryParse(match.group(1)!);
    final month = int.tryParse(match.group(2)!);
    final day = int.tryParse(match.group(3)!);
    if (year == null || month == null || day == null) return null;
    if (month < 1 || month > 12 || day < 1 || day > 31) return null;

    final check = DateTime.utc(year, month, day);
    if (check.year != year || check.month != month || check.day != day) {
      return null;
    }

    if (endOfDay) {
      return DateTime.utc(year, month, day, 23, 59, 59, 999);
    }
    return check;
  }
}
