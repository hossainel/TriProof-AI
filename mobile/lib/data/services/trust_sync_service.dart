import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/domain/models/blacklist_entry.dart';
import 'package:triproof_ai_scanner/domain/models/trust_key.dart';

/// Minimal HTTP surface used by [TrustSyncService], injectable in tests.
abstract interface class TrustHttpClient {
  /// Performs a GET request with optional headers.
  Future<http.Response> get(Uri uri, {Map<String, String>? headers});
}

/// Production HTTP implementation backed by `package:http`.
class HttpTrustHttpClient implements TrustHttpClient {
  /// Creates an HTTP-backed client around [client].
  HttpTrustHttpClient({http.Client? client})
    : _client = client ?? http.Client();

  final http.Client _client;

  @override
  Future<http.Response> get(Uri uri, {Map<String, String>? headers}) =>
      _client.get(uri, headers: headers);
}

/// Snapshot of locally persisted trust metadata for UI display.
class TrustSnapshot {
  /// Creates a [TrustSnapshot].
  const TrustSnapshot({
    required this.baseUrl,
    required this.keys,
    required this.lastSyncedAt,
  });

  /// Configured TriProof website base URL.
  final String baseUrl;

  /// Locally cached trusted public keys.
  final List<TrustKey> keys;

  /// Last successful website trust sync, or `null` when never synced.
  final DateTime? lastSyncedAt;

  /// Comma-separated key versions for compact status chips.
  String get keyVersions => keys.map((k) => k.version).join(', ');
}

/// Result of a successful website trust sync.
class TrustSyncResult extends TrustSnapshot {
  /// Creates a [TrustSyncResult].
  const TrustSyncResult({
    required super.baseUrl,
    required super.keys,
    required super.lastSyncedAt,
    required this.blacklistEntries,
    required this.bloomUpdated,
  });

  /// Number of recalled/cloned entries written to local SQLCipher storage.
  final int blacklistEntries;

  /// Whether a Bloom filter was persisted from the response.
  final bool bloomUpdated;
}

/// Fetches public verification keys and blacklist updates from any configured
/// TriProof AI website and persists them for fully offline verification.
class TrustSyncService {
  /// Creates a [TrustSyncService].
  TrustSyncService({
    required DatabaseHelper databaseHelper,
    required SecureKeystoreService keystoreService,
    TrustHttpClient? httpClient,
  }) : _databaseHelper = databaseHelper,
       _keystoreService = keystoreService,
       _httpClient = httpClient ?? HttpTrustHttpClient();

  final DatabaseHelper _databaseHelper;
  final SecureKeystoreService _keystoreService;
  final TrustHttpClient _httpClient;

  static const Duration _timeout = Duration(seconds: 10);

  /// Returns the currently configured server base URL.
  Future<String> getServerBaseUrl() => _databaseHelper.getServerBaseUrl();

  /// Normalizes and persists a user-supplied server base URL.
  Future<String> setServerBaseUrl(String rawUrl) async {
    final normalized = normalizeBaseUrl(rawUrl);
    await _databaseHelper.setServerBaseUrl(normalized);
    return normalized;
  }

  /// Normalizes a website URL to an origin without a trailing slash.
  static String normalizeBaseUrl(String rawUrl) {
    var value = rawUrl.trim();
    if (value.isEmpty) value = AppConstants.apiBaseUrl;
    if (!value.contains('://')) value = 'https://$value';
    value = value.replaceAll(RegExp(r'/+$'), '');
    if (value.endsWith('/api/v1')) {
      value = value.substring(0, value.length - '/api/v1'.length);
    }
    final uri = Uri.parse(value);
    if (!uri.hasScheme || uri.authority.isEmpty) {
      throw StateError('Invalid TriProof website URL.');
    }
    return value;
  }

  /// Loads the locally persisted trust snapshot.
  Future<TrustSnapshot> loadSnapshot() async {
    return TrustSnapshot(
      baseUrl: await getServerBaseUrl(),
      keys: await _databaseHelper.readSyncedKeyRing(),
      lastSyncedAt: await _databaseHelper.getLastTrustSyncAt(),
    );
  }

  /// Fetches keys and blacklist updates from the configured website.
  ///
  /// Pass [overrideBaseUrl] to point an installed APK at a different server.
  Future<TrustSyncResult> syncFromWebsite({String? overrideBaseUrl}) async {
    final rawUrl = overrideBaseUrl?.trim().isNotEmpty == true
        ? overrideBaseUrl!
        : await getServerBaseUrl();
    final baseUrl = await setServerBaseUrl(rawUrl);

    final wellKnownUri = Uri.parse('$baseUrl${AppConstants.keyRingPath}');
    final wellKnownResponse = await _httpClient
        .get(wellKnownUri, headers: {'Accept': 'application/json'})
        .timeout(_timeout);
    if (wellKnownResponse.statusCode < 200 ||
        wellKnownResponse.statusCode >= 300) {
      throw StateError(
        'Key discovery failed (${wellKnownResponse.statusCode}).',
      );
    }

    final decoded = jsonDecode(wellKnownResponse.body);
    if (decoded is! Map<String, dynamic>) {
      throw StateError('Invalid key discovery response.');
    }
    final data = decoded['data'] is Map<String, dynamic>
        ? decoded['data'] as Map<String, dynamic>
        : decoded;
    final keys = _extractKeys(data);
    if (keys.isEmpty) {
      throw StateError('No trusted public keys were published by the website.');
    }

    await _databaseHelper.saveSyncedKeyRing(keys);
    await _keystoreService.upsertPublicKeyRing({
      for (final key in keys) key.version: key.publicKey,
    });

    var blacklistEntries = 0;
    var bloomUpdated = false;
    DateTime? serverTime;

    final blacklistUri = Uri.parse(
      '$baseUrl${AppConstants.blacklistDeltaPath}',
    );
    final blacklistResponse = await _httpClient
        .get(blacklistUri, headers: {'Accept': 'application/json'})
        .timeout(_timeout);
    if (blacklistResponse.statusCode >= 200 &&
        blacklistResponse.statusCode < 300 &&
        blacklistResponse.bodyBytes.length <=
            AppConstants.maxBlacklistPayloadBytes) {
      final blacklistBody = jsonDecode(blacklistResponse.body);
      if (blacklistBody is Map<String, dynamic>) {
        final rawEntries =
            blacklistBody['entries'] as List<dynamic>? ?? const [];
        final entries = rawEntries
            .whereType<Map<String, dynamic>>()
            .map(BlacklistEntry.fromMap)
            .toList();
        await _databaseHelper.upsertBlacklistEntries(entries, replaceAll: true);
        blacklistEntries = entries.length;

        final rawBloom = blacklistBody['bloom_filter'];
        if (rawBloom is Map) {
          await _databaseHelper.saveBloomFilter(
            rawBloom.map(
              (key, value) => MapEntry<String, dynamic>(key.toString(), value),
            ),
          );
          bloomUpdated = true;
        }
        serverTime = DateTime.tryParse(
          (blacklistBody['server_time'] as String?) ?? '',
        )?.toUtc();
      }
    }

    final syncedAt = serverTime ?? DateTime.now().toUtc();
    await _databaseHelper.setLastTrustSyncAt(syncedAt);

    return TrustSyncResult(
      baseUrl: baseUrl,
      keys: keys,
      lastSyncedAt: syncedAt,
      blacklistEntries: blacklistEntries,
      bloomUpdated: bloomUpdated,
    );
  }

  List<TrustKey> _extractKeys(Map<String, dynamic> data) {
    final byVersion = <String, TrustKey>{};

    void add(Map<String, dynamic> raw, {String fallbackStatus = 'active'}) {
      final publicKey = _normalizeKey(
        (raw['public_key_base64'] ?? raw['public_key'] ?? '').toString(),
      );
      if (publicKey == null) return;
      final version = _normalizeVersion(
        (raw['key_version'] ?? raw['version'] ?? 'K00').toString(),
      );
      final status = (raw['status'] ?? fallbackStatus).toString().toLowerCase();
      if (status != 'active' && status != 'verify_only') return;
      byVersion.putIfAbsent(
        version,
        () => TrustKey(
          version: version,
          publicKey: publicKey,
          status: status,
          buyerId: raw['buyer_id']?.toString(),
        ),
      );
    }

    for (final section in ['active_keys', 'verify_only_keys', 'key_ring']) {
      final rawSection = data[section];
      if (rawSection is List) {
        final fallback = section == 'verify_only_keys'
            ? 'verify_only'
            : 'active';
        for (final entry in rawSection) {
          if (entry is Map<String, dynamic>) {
            add(entry, fallbackStatus: fallback);
          }
        }
      }
    }

    for (final field in [
      'public_key_base64',
      'universal_public_key',
      'public_key',
    ]) {
      final value = data[field];
      if (value is String && value.isNotEmpty) {
        add({'key_version': 'K00', 'public_key_base64': value});
      }
    }

    return byVersion.values.toList()
      ..sort((a, b) => a.version.compareTo(b.version));
  }

  String? _normalizeKey(String encodedRaw) {
    var encoded = encodedRaw.trim();
    if (encoded.isEmpty) return null;
    encoded = encoded.replaceAll('-', '+').replaceAll('_', '/');
    final remainder = encoded.length % 4;
    if (remainder == 1) return null;
    if (remainder > 0) {
      encoded = encoded.padRight(encoded.length + (4 - remainder), '=');
    }
    try {
      final bytes = base64Decode(encoded);
      return bytes.length == 32 ? base64Encode(bytes) : null;
    } on FormatException {
      return null;
    }
  }

  String _normalizeVersion(String raw) {
    final upper = raw.trim().toUpperCase();
    if (RegExp(r'^K\d{2}$').hasMatch(upper)) return upper;
    final digits = upper.replaceAll(RegExp(r'\D'), '');
    if (digits.isEmpty) return 'K00';
    return 'K${digits.padLeft(2, '0')}';
  }
}
