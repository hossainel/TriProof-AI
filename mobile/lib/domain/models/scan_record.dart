import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/enums/offline_status.dart';
import 'package:triproof_ai_scanner/domain/enums/scan_mode.dart';

/// Persisted verification audit record stored in SQLite table `scan_history`.
class ScanRecord {
  /// Creates a [ScanRecord].
  const ScanRecord({
    this.id,
    required this.syncUuid,
    required this.outerSerial,
    required this.innerSerial,
    required this.unitHash,
    required this.factoryId,
    required this.machineId,
    required this.batchId,
    required this.prodDate,
    required this.expDate,
    required this.offlineStatus,
    required this.cloudStatus,
    required this.feedbackMessageEn,
    required this.feedbackMessageBn,
    this.gpsLat,
    this.gpsLng,
    required this.scannedAt,
    required this.deviceToken,
    required this.scanMode,
    required this.sessionId,
    required this.latencyMs,
    this.scanSeq = 0,
  });

  /// Auto-increment SQLite primary key.
  final int? id;

  /// Globally unique UUIDv4 identifier used for idempotent cloud sync.
  final String syncUuid;

  /// Outer box public serial string.
  final String outerSerial;

  /// Inner seal private Base64 Ed25519 signature string.
  final String innerSerial;

  /// Deterministic SHA-256 hex digest binding outer serial and inner signature.
  final String unitHash;

  /// Originating factory ID (`BD01`, etc.).
  final String factoryId;

  /// Production line / printer machine ID.
  final String machineId;

  /// Production batch identifier.
  final String batchId;

  /// Production date (`YYYY-MM-DD`).
  final String prodDate;

  /// Expiry date (`YYYY-MM-DD`).
  final String expDate;

  /// Verdict computed offline in <50ms.
  final OfflineStatus offlineStatus;

  /// Latest cloud cross-check status.
  final CloudStatus cloudStatus;

  /// English user-facing verdict/action guidance.
  final String feedbackMessageEn;

  /// Bangla user-facing verdict/action guidance.
  final String feedbackMessageBn;

  /// Optional GPS latitude captured at scan time.
  final double? gpsLat;

  /// Optional GPS longitude captured at scan time.
  final double? gpsLng;

  /// Scan timestamp in UTC ISO-8601 format.
  final DateTime scannedAt;

  /// Hardware-bound device token that performed the scan.
  final String deviceToken;

  /// Operational mode under which the scan ran.
  final ScanMode scanMode;

  /// Active inspection session identifier for pallet collision checks.
  final String sessionId;

  /// Measured cryptographic verification time in milliseconds.
  final int latencyMs;

  /// Monotonic per-device sequence number assigned by the secure keystore.
  final int scanSeq;

  /// Converts this record into a SQLite row map.
  Map<String, Object?> toMap() {
    return {
      if (id != null) 'id': id,
      'sync_uuid': syncUuid,
      'outer_serial': outerSerial,
      'inner_serial': innerSerial,
      'unit_hash': unitHash,
      'factory_id': factoryId,
      'machine_id': machineId,
      'batch_id': batchId,
      'prod_date': prodDate,
      'exp_date': expDate,
      'offline_status': offlineStatus.dbValue,
      'cloud_status': cloudStatus.dbValue,
      'feedback_message_en': feedbackMessageEn,
      'feedback_message_bn': feedbackMessageBn,
      'gps_lat': gpsLat,
      'gps_lng': gpsLng,
      'scanned_at': scannedAt.toUtc().toIso8601String(),
      'device_token': deviceToken,
      'scan_mode': scanMode.dbValue,
      'session_id': sessionId,
      'latency_ms': latencyMs,
      'scan_seq': scanSeq,
    };
  }

  /// Reconstructs a [ScanRecord] from a SQLite query row.
  factory ScanRecord.fromMap(Map<String, Object?> map) {
    return ScanRecord(
      id: map['id'] as int?,
      syncUuid: map['sync_uuid'] as String,
      outerSerial: map['outer_serial'] as String,
      innerSerial: map['inner_serial'] as String,
      unitHash: map['unit_hash'] as String,
      factoryId: map['factory_id'] as String,
      machineId: map['machine_id'] as String,
      batchId: map['batch_id'] as String,
      prodDate: map['prod_date'] as String,
      expDate: map['exp_date'] as String,
      offlineStatus: OfflineStatus.fromDbValue(map['offline_status'] as String),
      cloudStatus: CloudStatus.fromDbValue(map['cloud_status'] as String),
      feedbackMessageEn: map['feedback_message_en'] as String,
      feedbackMessageBn: map['feedback_message_bn'] as String,
      gpsLat: (map['gps_lat'] as num?)?.toDouble(),
      gpsLng: (map['gps_lng'] as num?)?.toDouble(),
      scannedAt: DateTime.parse(map['scanned_at'] as String).toUtc(),
      deviceToken: (map['device_token'] as String?) ?? '',
      scanMode: ScanMode.fromDbValue(map['scan_mode'] as String?),
      sessionId: (map['session_id'] as String?) ?? '',
      latencyMs: (map['latency_ms'] as int?) ?? 0,
      scanSeq: (map['scan_seq'] as int?) ?? 0,
    );
  }

  /// Serializes this record for `POST /api/v1/verify/online-crosscheck`.
  Map<String, Object?> toCrossCheckJson() {
    return {
      'sync_uuid': syncUuid,
      'device_token': deviceToken,
      'outer_serial': outerSerial,
      'inner_serial': innerSerial,
      'unit_hash': unitHash,
      'factory_id': factoryId,
      'machine_id': machineId,
      'batch_id': batchId,
      'prod_date': prodDate,
      'exp_date': expDate,
      'offline_status': offlineStatus.dbValue,
      'scan_mode': scanMode.dbValue,
      'gps_lat': gpsLat,
      'gps_lng': gpsLng,
      'scanned_at': scannedAt.toUtc().toIso8601String(),
      'scan_seq': scanSeq,
    };
  }

  /// Returns a copy with updated cloud verification fields.
  ScanRecord copyWith({
    CloudStatus? cloudStatus,
    String? feedbackMessageEn,
    String? feedbackMessageBn,
    int? scanSeq,
  }) {
    return ScanRecord(
      id: id,
      syncUuid: syncUuid,
      outerSerial: outerSerial,
      innerSerial: innerSerial,
      unitHash: unitHash,
      factoryId: factoryId,
      machineId: machineId,
      batchId: batchId,
      prodDate: prodDate,
      expDate: expDate,
      offlineStatus: offlineStatus,
      cloudStatus: cloudStatus ?? this.cloudStatus,
      feedbackMessageEn: feedbackMessageEn ?? this.feedbackMessageEn,
      feedbackMessageBn: feedbackMessageBn ?? this.feedbackMessageBn,
      gpsLat: gpsLat,
      gpsLng: gpsLng,
      scannedAt: scannedAt,
      deviceToken: deviceToken,
      scanMode: scanMode,
      sessionId: sessionId,
      latencyMs: latencyMs,
      scanSeq: scanSeq ?? this.scanSeq,
    );
  }
}
