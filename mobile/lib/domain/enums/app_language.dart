/// Supported UI display languages for TriProof AI Scanner.
enum AppLanguage {
  /// English UI language.
  english('en', 'EN'),

  /// Bangla (বাংলা) UI language.
  bangla('bn', 'বাংলা');

  const AppLanguage(this.code, this.chipLabel);

  /// ISO 639-1 language code.
  final String code;

  /// Compact toggle chip label displayed in the top bar.
  final String chipLabel;
}
