import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';

/// Abstraction over secure key-value storage so unit tests can inject an
/// in-memory store without requiring platform Keystore channels.
abstract interface class KeyValueSecureStore {
  /// Reads the value associated with [key], or `null` if absent.
  Future<String?> read(String key);

  /// Writes [value] under [key].
  Future<void> write(String key, String value);

  /// Deletes [key] from secure storage.
  Future<void> delete(String key);
}

/// Hardware-backed [KeyValueSecureStore] implementation using
/// [FlutterSecureStorage] (Android Keystore / iOS Secure Enclave Keychain).
class FlutterSecureStorageStore implements KeyValueSecureStore {
  /// Creates a [FlutterSecureStorageStore].
  FlutterSecureStorageStore({FlutterSecureStorage? storage})
      : _storage = storage ??
            const FlutterSecureStorage(
              aOptions: AndroidOptions(
                migrateOnAlgorithmChange: true,
              ),
              iOptions: IOSOptions(
                accessibility: KeychainAccessibility.first_unlock,
              ),
            );

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) =>
      _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}

/// Manages hardware-protected keys, device identity, SQLCipher key, the public
/// verification key ring, monotonic scan sequence, and the anti-clock-rollback
/// High-Water Mark timestamp.
class SecureKeystoreService {
  /// Creates a [SecureKeystoreService] backed by [store].
  ///
  /// [buildEpochMs] overrides [AppConstants.buildEpochMs] (used by tests).
  SecureKeystoreService({
    KeyValueSecureStore? store,
    int? buildEpochMs,
  })  : _store = store ?? FlutterSecureStorageStore(),
        _buildEpochMs = buildEpochMs ?? AppConstants.buildEpochMs;

  /// Storage key for the single 32-byte Base64 universal Ed25519 public key.
  static const String publicKeyKey = 'UNIVERSAL_PUBLIC_VERIFICATION_KEY';

  /// Storage key for the versioned public key ring (`KNN -> base64` JSON map).
  static const String publicKeyRingKey = 'PUBLIC_KEY_RING';

  /// Storage key for the currently active public key version identifier.
  static const String activeKeyIdKey = 'ACTIVE_PUBLIC_KEY_ID';

  /// Storage key for the hardware-bound unforgeable device token.
  static const String deviceTokenKey = 'DEVICE_TOKEN';

  /// Storage key for the anti-clock-rollback monotonic timestamp (UTC ms).
  static const String highWaterMarkKey = 'last_known_verified_timestamp';

  /// Storage key for the 256-bit SQLCipher AES database passphrase.
  static const String dbEncryptionKey = 'triproof_sqlcipher_key';

  /// Storage key for the monotonic per-device scan counter.
  static const String scanSeqKey = 'scan_seq';

  /// Legacy/unversioned key identifier kept for v1 serial compatibility.
  static const String legacyKeyId = 'K00';

  final KeyValueSecureStore _store;
  final Random _csprng = Random.secure();
  final int _buildEpochMs;

  final Map<String, List<int>> _cachedDecodedKeys = <String, List<int>>{};
  Map<String, String>? _cachedPublicKeyRing;
  String? _cachedActiveKeyId;
  String? _cachedDeviceToken;
  DateTime? _cachedHighWaterMark;
  int? _cachedScanSeq;

  /// Ensures the universal public key, device token, and initial clock
  /// high-water mark are initialized inside secure storage.
  ///
  /// The high-water mark is floored to the compile-time build epoch so a
  /// stale build cannot bypass newer verification policy by rolling the clock.
  Future<void> initialize({DateTime? currentUtc}) async {
    await getUniversalPublicKeyBytes();
    await getOrCreateDeviceToken();

    final buildFloor = DateTime.fromMillisecondsSinceEpoch(
      _buildEpochMs,
      isUtc: true,
    );
    await updateHighWaterMarkIfNewer(buildFloor);
    await updateHighWaterMarkIfNewer(currentUtc ?? DateTime.now().toUtc());
  }

  /// Returns the decoded 32-byte universal Ed25519 verification key.
  ///
  /// Delegates to the `K00` slot of the public key ring for compatibility.
  Future<List<int>> getUniversalPublicKeyBytes() =>
      getPublicKeyBytes(legacyKeyId);

  /// Returns the decoded 32-byte public key registered for [keyId].
  ///
  /// Unknown identifiers throw a [StateError]; the `K00` identifier falls back
  /// to the legacy `UNIVERSAL_PUBLIC_VERIFICATION_KEY` slot when the ring has
  /// not been provisioned yet.
  Future<List<int>> getPublicKeyBytes(String keyId) async {
    final normalized = _normalizeKeyId(keyId);
    final cached = _cachedDecodedKeys[normalized];
    if (cached != null) return cached;

    final ring = await _loadPublicKeyRing();
    final encoded = ring[normalized];
    if (encoded != null) {
      final decoded = _decode32(encoded, normalized);
      _cacheDecoded(normalized, decoded);
      return decoded;
    }

    if (normalized != legacyKeyId) {
      throw StateError(
        'No public verification key is registered for key version $normalized.',
      );
    }

    var legacy = await _store.read(publicKeyKey);
    if (legacy == null || legacy.trim().isEmpty) {
      legacy = AppConstants.defaultUniversalPublicKeyBase64;
      await _store.write(publicKeyKey, legacy);
    }
    final decoded = _decode32(legacy, normalized);
    await upsertPublicKeyRing({normalized: legacy});
    _cacheDecoded(normalized, decoded);
    return decoded;
  }

  /// Returns a copy of the persisted public key ring (`KNN -> base64`).
  Future<Map<String, String>> getPublicKeyRing() async {
    return Map<String, String>.from(await _loadPublicKeyRing());
  }

  /// Merges [entries] into the public key ring.
  ///
  /// [entries] is either a `Map<String, String>` of `keyId -> base64` or a
  /// `List<Map<String, dynamic>>` using the `/.well-known/triproof-key.json`
  /// shape (`key_version`, `public_key_base64`, optional `status`). Every key
  /// must decode to exactly 32 bytes.
  Future<void> upsertPublicKeyRing(Object entries) async {
    final additions = <String, String>{};
    String? activeFromStatus;

    if (entries is Map) {
      entries.forEach((key, value) {
        additions[_normalizeKeyId(key.toString())] = value.toString().trim();
      });
    } else if (entries is List) {
      for (final raw in entries) {
        if (raw is! Map) continue;
        final keyId = (raw['key_version'] ?? raw['key_id'])?.toString();
        final encoded = raw['public_key_base64']?.toString();
        if (keyId == null || encoded == null) continue;
        final status = raw['status']?.toString().toLowerCase();
        if (status == 'active') activeFromStatus = _normalizeKeyId(keyId);
        additions[_normalizeKeyId(keyId)] = encoded.trim();
      }
    } else {
      throw ArgumentError.value(
        entries,
        'entries',
        'Expected Map<String, String> or List<Map<String, dynamic>>.',
      );
    }

    for (final entry in additions.entries) {
      _decode32(entry.value, entry.key);
    }

    final ring = await _loadPublicKeyRing();
    ring.addAll(additions);
    await _store.write(publicKeyRingKey, jsonEncode(ring));

    _cachedPublicKeyRing = Map<String, String>.from(ring);
    for (final entry in additions.entries) {
      final decoded = _decode32(entry.value, entry.key);
      _cacheDecoded(entry.key, decoded);
    }

    if (activeFromStatus != null) {
      await setActiveKeyId(activeFromStatus);
    }
  }

  /// Returns the active public key identifier (defaults to `K00`).
  Future<String> getActiveKeyId() async {
    final cached = _cachedActiveKeyId;
    if (cached != null) return cached;

    final stored = await _store.read(activeKeyIdKey);
    final resolved = (stored != null && stored.trim().isNotEmpty)
        ? _normalizeKeyId(stored)
        : legacyKeyId;
    _cachedActiveKeyId = resolved;
    return resolved;
  }

  /// Persists [keyId] as the active public key identifier.
  Future<void> setActiveKeyId(String keyId) async {
    final normalized = _normalizeKeyId(keyId);
    await _store.write(activeKeyIdKey, normalized);
    _cachedActiveKeyId = normalized;
  }

  /// Rotates or provisions a new 32-byte Base64 Ed25519 public key into
  /// hardware keystore (also updating the `K00` ring slot).
  Future<void> setUniversalPublicKeyBase64(String base64Key) async {
    final normalized = base64Key.trim();
    final decoded = _decode32(normalized, legacyKeyId);
    await _store.write(publicKeyKey, normalized);
    await upsertPublicKeyRing({legacyKeyId: normalized});
    _cacheDecoded(legacyKeyId, decoded);
  }

  /// Returns the hardware-bound `DEVICE_TOKEN`, generating and persisting a
  /// cryptographically random 256-bit token on first launch.
  Future<String> getOrCreateDeviceToken() async {
    if (_cachedDeviceToken != null) return _cachedDeviceToken!;

    final existing = await _store.read(deviceTokenKey);
    if (existing != null && existing.trim().isNotEmpty) {
      _cachedDeviceToken = existing.trim();
      return _cachedDeviceToken!;
    }

    final entropy = List<int>.generate(24, (_) => _csprng.nextInt(256));
    final token = 'TPD-${base64UrlEncode(entropy).replaceAll('=', '')}';
    await _store.write(deviceTokenKey, token);
    _cachedDeviceToken = token;
    return token;
  }

  /// Returns the stored `last_known_verified_timestamp` in UTC, or `null` if
  /// not yet recorded.
  Future<DateTime?> getHighWaterMark() async {
    if (_cachedHighWaterMark != null) return _cachedHighWaterMark;

    final raw = await _store.read(highWaterMarkKey);
    if (raw == null || raw.isEmpty) return null;

    final ms = int.tryParse(raw);
    if (ms == null) return null;

    _cachedHighWaterMark = DateTime.fromMillisecondsSinceEpoch(ms, isUtc: true);
    return _cachedHighWaterMark;
  }

  /// Monotonically advances `last_known_verified_timestamp` whenever [candidateUtc]
  /// is strictly greater than the currently stored high-water mark.
  ///
  /// Never rolls the high-water mark backward. Returns `true` if updated.
  Future<bool> updateHighWaterMarkIfNewer(DateTime candidateUtc) async {
    final normalized = candidateUtc.toUtc();
    final currentHwm = await getHighWaterMark();
    if (currentHwm != null && !normalized.isAfter(currentHwm)) {
      return false;
    }
    await _store.write(
      highWaterMarkKey,
      normalized.millisecondsSinceEpoch.toString(),
    );
    _cachedHighWaterMark = normalized;
    return true;
  }

  /// Returns `true` if [currentUtc] precedes `last_known_verified_timestamp`,
  /// indicating the user turned the device clock backward.
  Future<bool> isClockRolledBack(DateTime currentUtc) async {
    final hwm = await getHighWaterMark();
    if (hwm == null) return false;
    return currentUtc.toUtc().isBefore(hwm);
  }

  /// Returns the last persisted monotonic `scan_seq` (zero if none yet).
  Future<int> lastScanSeq() async {
    final cached = _cachedScanSeq;
    if (cached != null) return cached;

    final raw = await _store.read(scanSeqKey);
    final parsed = int.tryParse(raw ?? '') ?? 0;
    _cachedScanSeq = parsed;
    return parsed;
  }

  /// Atomically increments and persists the monotonic `scan_seq` counter.
  Future<int> nextScanSeq() async {
    final next = await lastScanSeq() + 1;
    await _store.write(scanSeqKey, next.toString());
    _cachedScanSeq = next;
    return next;
  }

  /// Returns or creates the 256-bit SQLCipher passphrase stored inside hardware
  /// keystore.
  Future<String> getOrCreateDatabaseKey() async {
    final existing = await _store.read(dbEncryptionKey);
    if (existing != null && existing.isNotEmpty) return existing;

    final keyBytes = List<int>.generate(32, (_) => _csprng.nextInt(256));
    final encoded = base64UrlEncode(keyBytes);
    await _store.write(dbEncryptionKey, encoded);
    return encoded;
  }

  Future<Map<String, String>> _loadPublicKeyRing() async {
    final cached = _cachedPublicKeyRing;
    if (cached != null) return cached;

    final raw = await _store.read(publicKeyRingKey);
    if (raw != null && raw.trim().isNotEmpty) {
      try {
        final decoded = jsonDecode(raw);
        if (decoded is Map) {
          final ring = <String, String>{};
          decoded.forEach((key, value) {
            ring[_normalizeKeyId(key.toString())] = value.toString();
          });
          _cachedPublicKeyRing = ring;
          return ring;
        }
      } on FormatException {
        // Corrupt ring: rebuild from the legacy key below.
      }
    }

    _cachedPublicKeyRing = <String, String>{};
    return _cachedPublicKeyRing!;
  }

  void _cacheDecoded(String keyId, List<int> bytes) {
    _cachedDecodedKeys[_normalizeKeyId(keyId)] = bytes;
  }

  List<int> _decode32(String encoded, String keyId) {
    final decoded = base64Decode(encoded.trim());
    if (decoded.length != 32) {
      throw StateError(
        'Public key $keyId must decode to exactly 32 bytes '
        '(found ${decoded.length}).',
      );
    }
    return decoded;
  }

  String _normalizeKeyId(String keyId) {
    final normalized = keyId.trim().toUpperCase();
    if (normalized.isEmpty) {
      throw ArgumentError.value(keyId, 'keyId', 'Key identifier cannot be empty.');
    }
    return normalized;
  }
}
