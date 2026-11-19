/// Cached recalled batch or known cloned serial entry (<50KB budget).
class BlacklistEntry {
  /// Creates a [BlacklistEntry].
  const BlacklistEntry({
    required this.serialOrBatch,
    required this.reason,
    required this.updatedAt,
  });

  /// Either a `BatchID` (e.g. `B202609-RECALL`) or a full `outer_serial`.
  final String serialOrBatch;

  /// Human-readable recall or revocation reason.
  final String reason;

  /// Timestamp of last update in UTC ISO-8601.
  final DateTime updatedAt;

  /// Converts this entry into a SQLite row map for `cached_blacklist`.
  Map<String, Object?> toMap() {
    return {
      'serial_or_batch': serialOrBatch,
      'reason': reason,
      'updated_at': updatedAt.toUtc().toIso8601String(),
    };
  }

  /// Constructs a [BlacklistEntry] from a SQLite row or API JSON map.
  factory BlacklistEntry.fromMap(Map<String, Object?> map) {
    return BlacklistEntry(
      serialOrBatch: map['serial_or_batch'] as String,
      reason: (map['reason'] as String?) ?? 'Recalled or revoked batch',
      updatedAt: DateTime.tryParse(
            (map['updated_at'] as String?) ?? '',
          )?.toUtc() ??
          DateTime.now().toUtc(),
    );
  }
}
