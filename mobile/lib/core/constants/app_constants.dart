/// Application-wide constants for TriProof AI Scanner.
abstract final class AppConstants {
  /// Default 32-byte Ed25519 public verification key (Base64 encoded).
  ///
  /// On first launch this key is persisted into hardware-backed storage
  /// (`FlutterSecureStorage`) under `SecureKeystoreService.publicKeyKey`.
  /// Production deployments can rotate this key via MDM, signed provisioning,
  /// or `--dart-define=UNIVERSAL_PUBLIC_KEY=<base64>`.
  static const String defaultUniversalPublicKeyBase64 = String.fromEnvironment(
    'UNIVERSAL_PUBLIC_KEY',
    defaultValue:       'fzJVLyOzjNkU5yO1nunqxJbizPggpPHek3Xctb654Pw=',
  );

  /// Base URL for the TriProof Cloud Cross-Check & Recall API.
  ///
  /// Override at build time with `--dart-define=API_BASE_URL=https://...`.
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue:       'https://t.focusautomobiles.com',
  );

  /// Compile-time minimum build epoch (UTC milliseconds). The keystore's
  /// High-Water Mark is floored to this value so an old incompatible build
  /// cannot be replayed against a newer verification policy.
  ///
  /// Override with `--dart-define=BUILD_EPOCH_MS=1763510400000`.
  static const int buildEpochMs = int.fromEnvironment(
    'BUILD_EPOCH_MS',
    defaultValue: 0,
  );

  /// POST endpoint for store-and-forward online verification cross-check.
  static const String onlineCrossCheckPath = '/api/v1/verify/online-crosscheck';

  /// GET path prefix for device notifications (`/api/v1/notifications/{device_token}`).
  static const String notificationsPathPrefix = '/api/v1/notifications';

  /// GET path for the compressed <50KB recalled batch & clone blacklist delta.
  static const String blacklistDeltaPath = '/api/v1/blacklist/delta';

  /// GET path for the public versioned Ed25519 verification key ring
  /// (`/.well-known/triproof-key.json`). No JWT is required.
  static const String keyRingPath = '/.well-known/triproof-key.json';

  /// Strict 50 KB budget for the offline cached blacklist table.
  static const int maxBlacklistPayloadBytes = 50 * 1024;

  /// Minimum remaining shelf life required in Factory Packing QA Mode.
  static const int factoryQaMinShelfLifeDays = 45;

  /// Target offline cryptographic verification latency threshold in milliseconds.
  static const int offlineLatencyBudgetMs = 50;

  /// Bilingual directory mapping Bangladeshi & export factory IDs to readable names.
  static const Map<String, ({String en, String bn})> factoryDirectory = {
    'BD01': (
      en: 'Dhaka Export Pharma & Pack #01 (Gazipur)',
      bn: 'ঢাকা এক্সপোর্ট ফার্মা ও প্যাক #০১ (গাজীপুর)',
    ),
    'BD02': (
      en: 'Chattogram EPZ Packaging Unit #02',
      bn: 'চট্টগ্রাম ইপিজেড প্যাকেজিং ইউনিট #০২',
    ),
    'BD03': (
      en: 'Narayanganj BSCIC Precision Plant #03',
      bn: 'নারায়ণগঞ্জ বিসিক প্রিসিশন প্ল্যান্ট #০৩',
    ),
    'BD04': (
      en: 'Savars Export Agro-Processing Hub #04',
      bn: 'সাভার এক্সপোর্ট অ্যাগ্রো-প্রসেসিং হাব #০৪',
    ),
    'BD05': (
      en: 'Sylhet Tea & Consumer Goods Bottling #05',
      bn: 'সিলেট চা ও কনজিউমার গুডস বটলিং #০৫',
    ),
    'DHK04': (
      en: 'Dhaka Exportpharma GMP Unit #04 (Keraniganj)',
      bn: 'ঢাকা এক্সপোর্টফার্মা জিএমপি ইউনিট #০৪ (কেরানীগঞ্জ)',
    ),
  };

  /// Resolves a [factoryId] into a bilingual factory display name.
  static ({String en, String bn}) resolveFactoryName(String factoryId) {
    final normalized = factoryId.trim().toUpperCase();
    return factoryDirectory[normalized] ??
        (
          en: 'Verified Plant ($normalized)',
          bn: 'নিবন্ধিত কারখানা ($normalized)',
        );
  }
}
