/// Operational verification mode governing expiry and session checks.
enum ScanMode {
  /// Consumer Unboxing mode: checks full `ExpDate` and prior device ownership.
  consumerUnboxing('consumer_unboxing'),

  /// Factory Packing QA mode: enforces >=45 days remaining shelf life and
  /// active pallet/carton session collision detection.
  factoryPackingQa('factory_packing_qa');

  const ScanMode(this.dbValue);

  /// Canonical storage value persisted in SQLite.
  final String dbValue;

  /// Parses a persisted string into a [ScanMode], defaulting to
  /// [consumerUnboxing].
  static ScanMode fromDbValue(String? raw) {
    return ScanMode.values.firstWhere(
      (mode) => mode.dbValue == raw,
      orElse: () => ScanMode.consumerUnboxing,
    );
  }
}
