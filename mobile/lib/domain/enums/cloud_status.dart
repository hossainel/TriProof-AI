/// Store-and-forward cloud cross-check verdict in `scan_history`.
enum CloudStatus {
  /// Scan captured offline and queued for upload upon network restoration.
  pendingSync('pending_sync'),

  /// Confirmed clean by server cross-check with no clone or recall flags.
  verifiedClean('verified_clean'),

  /// Retroactively flagged by cloud as cloned/used on another device.
  retroactiveCloneAlert('retroactive_clone_alert'),

  /// Retroactively flagged by cloud as part of a recalled batch.
  retroactiveRecallAlert('retroactive_recall_alert');

  const CloudStatus(this.dbValue);

  /// Exact SQLite column string stored in `scan_history.cloud_status`.
  final String dbValue;

  /// Whether the cloud cross-check raised a critical retroactive alert.
  bool get isRetroactiveAlert =>
      this == CloudStatus.retroactiveCloneAlert ||
      this == CloudStatus.retroactiveRecallAlert;

  /// Parses [raw] from SQLite back into a [CloudStatus].
  static CloudStatus fromDbValue(String raw) {
    return CloudStatus.values.firstWhere(
      (status) => status.dbValue == raw,
      orElse: () => CloudStatus.pendingSync,
    );
  }
}
