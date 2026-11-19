import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/enums/offline_status.dart';
import 'package:triproof_ai_scanner/domain/enums/scan_mode.dart';
import 'package:triproof_ai_scanner/domain/models/parsed_outer_serial.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';

/// Detailed result produced by `VerificationRepository.verifyOffline`.
class VerificationResult {
  /// Creates a [VerificationResult].
  const VerificationResult({
    required this.record,
    required this.parsedSerial,
    required this.offlineStatus,
    required this.cloudStatus,
    required this.verificationMicroseconds,
    required this.scanMode,
    required this.headlineEn,
    required this.headlineBn,
    required this.messageEn,
    required this.messageBn,
    this.priorRegisteredDate,
    this.nearExpiryFactoryQa = false,
    this.blacklistReason,
  });

  /// Persisted SQLite [ScanRecord].
  final ScanRecord record;

  /// Parsed outer serial fields, or `null` if the label format was invalid.
  final ParsedOuterSerial? parsedSerial;

  /// Offline cryptographic & policy status.
  final OfflineStatus offlineStatus;

  /// Current cloud cross-check status.
  final CloudStatus cloudStatus;

  /// Exact cryptographic verification duration in microseconds.
  final int verificationMicroseconds;

  /// Mode active during verification.
  final ScanMode scanMode;

  /// Short English banner headline.
  final String headlineEn;

  /// Short Bangla banner headline.
  final String headlineBn;

  /// Detailed English instructions / explanation.
  final String messageEn;

  /// Detailed Bangla instructions / explanation.
  final String messageBn;

  /// First registration date if [offlineStatus] is [OfflineStatus.repeatOwner].
  final DateTime? priorRegisteredDate;

  /// Whether rejection was caused by Factory QA's strict 45-day threshold.
  final bool nearExpiryFactoryQa;

  /// Reason string if matched in `cached_blacklist`.
  final String? blacklistReason;

  /// Elapsed verification time in milliseconds (rounded up to at least 1 ms when > 0).
  int get latencyMs => record.latencyMs;

  /// Whether the item passed offline verification AND has not been retroactively flagged.
  bool get isEffectiveGenuine =>
      offlineStatus.isGenuinePass && !cloudStatus.isRetroactiveAlert;
}
