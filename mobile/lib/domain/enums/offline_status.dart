/// Immediate (<50ms) offline verification verdict persisted in `scan_history`.
enum OfflineStatus {
  /// Signature valid, expiry valid, not blacklisted, and first time scanned.
  genuineOffline('genuine_offline'),

  /// Signature valid and previously registered to this same `device_token`.
  repeatOwner('repeat_owner'),

  /// Ed25519 verification failed or outer/inner tag mismatch/malformed.
  fakeSignature('fake_signature'),

  /// Product is past `ExpDate` (or <45 days remaining in Factory QA Mode).
  expired('expired'),

  /// Identical outer serial encountered on another carton in the active session.
  duplicateBox('duplicate_box'),

  /// Batch ID or serial matched the locally cached <50KB blacklist table.
  recalledCached('recalled_cached'),

  /// Device system clock was rolled back prior to `last_known_verified_timestamp`.
  clockRollback('clock_rollback'),

  /// Sealed master-carton QR signature and Merkle payload verified offline.
  masterCartonGenuine('master_carton_genuine'),

  /// Sealed master-carton QR signature was invalid or malformed.
  masterCartonMismatch('master_carton_mismatch');

  const OfflineStatus(this.dbValue);

  /// Exact SQLite column string stored in `scan_history.offline_status`.
  final String dbValue;

  /// Whether this offline verdict represents an authentic pass.
  bool get isGenuinePass =>
      this == OfflineStatus.genuineOffline || this == OfflineStatus.repeatOwner;

  /// Whether this verdict came from a sealed master-carton inspection.
  bool get isMasterCarton =>
      this == OfflineStatus.masterCartonGenuine ||
      this == OfflineStatus.masterCartonMismatch;

  /// Parses [raw] from SQLite back into an [OfflineStatus].
  static OfflineStatus fromDbValue(String raw) {
    return OfflineStatus.values.firstWhere(
      (status) => status.dbValue == raw,
      orElse: () => OfflineStatus.fakeSignature,
    );
  }
}
