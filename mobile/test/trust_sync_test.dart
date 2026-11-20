import 'dart:convert';
import 'dart:typed_data';

import 'package:cryptography/dart.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:triproof_ai_scanner/data/services/crypto_service.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/data/services/trust_sync_service.dart';

import 'test_fakes.dart';

class _FakeTrustHttpClient implements TrustHttpClient {
  _FakeTrustHttpClient({required this.publicKeyBase64});

  final String publicKeyBase64;
  final List<Uri> requested = <Uri>[];

  @override
  Future<http.Response> get(Uri uri, {Map<String, String>? headers}) async {
    requested.add(uri);
    if (uri.path == '/.well-known/triproof-key.json') {
      return http.Response(
        jsonEncode({
          'success': true,
          'data': {
            'algorithm': 'Ed25519',
            'active_keys': [
              {
                'key_version': 'K07',
                'public_key_base64': publicKeyBase64,
                'status': 'active',
              },
            ],
            'key_ring': [
              {
                'key_version': 'K07',
                'public_key_base64': publicKeyBase64,
                'status': 'active',
              },
            ],
          },
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    if (uri.path == '/api/v1/blacklist/delta') {
      return http.Response(
        jsonEncode({
          'success': true,
          'server_time': DateTime.utc(2026, 11, 20, 12).toIso8601String(),
          'entries': [
            {
              'serial_or_batch': 'DHK04-M01-B202611',
              'reason': 'Recalled batch',
              'updated_at': DateTime.utc(2026, 11, 20, 12).toIso8601String(),
            },
          ],
          'bloom_filter': {},
          'meta': {'limit_bytes': 50000},
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    return http.Response('not found', 404);
  }
}

void main() {
  group('Website trust sync', () {
    late SecureKeystoreService keystore;
    late DatabaseHelper dbHelper;
    late _FakeTrustHttpClient httpClient;
    late TrustSyncService service;

    setUp(() async {
      keystore = SecureKeystoreService(store: InMemorySecureStore());
      await keystore.initialize(currentUtc: DateTime.utc(2026, 11, 20, 12));
      dbHelper = createInMemoryDatabaseHelper(keystore);
      await dbHelper.database;
      httpClient = _FakeTrustHttpClient(
        publicKeyBase64: base64Encode(List<int>.filled(32, 9)),
      );
      service = TrustSyncService(
        databaseHelper: dbHelper,
        keystoreService: keystore,
        httpClient: httpClient,
      );
    });

    tearDown(() async {
      await dbHelper.close();
    });

    test(
      'normalizes URLs and persists synced keys plus blacklist updates',
      () async {
        final normalized = TrustSyncService.normalizeBaseUrl(
          't.focusautomobiles.com/api/v1/',
        );
        expect(normalized, 'https://t.focusautomobiles.com');
        expect(await service.getServerBaseUrl(), 'https://t.focusautomobiles.com');

        final result = await service.syncFromWebsite(
          overrideBaseUrl: 't.focusautomobiles.com',
        );

        expect(result.baseUrl, 'https://t.focusautomobiles.com');
        expect(result.keys.single.version, 'K07');
        expect((await dbHelper.readSyncedKeyRing()).single.version, 'K07');
        expect(
          await keystore.getPublicKeyRing(),
          containsPair('K07', isNotEmpty),
        );
        expect((await dbHelper.getBlacklistEntries()).length, 1);
        expect(await dbHelper.getLastTrustSyncAt(), isNotNull);
        expect(
          await service.getServerBaseUrl(),
          'https://t.focusautomobiles.com',
        );
      },
    );

    test(
      'verifies a serial with a newly synced key without rebuilding',
      () async {
        final ed25519 = DartEd25519(sha512: const DartSha512());
        final seed = Uint8List.fromList(List<int>.generate(32, (i) => i + 1));
        final keyPair = await ed25519.newKeyPairFromSeed(seed);
        final publicKey = await keyPair.extractPublicKey();
        final publicKeyBase64 = base64Encode(publicKey.bytes);

        httpClient = _FakeTrustHttpClient(publicKeyBase64: publicKeyBase64);
        service = TrustSyncService(
          databaseHelper: dbHelper,
          keystoreService: keystore,
          httpClient: httpClient,
        );
        await service.syncFromWebsite(overrideBaseUrl: 'https://tp.example');

        const outer = 'DHK07-M01-B202611-000001|2026-11-20|2027-11-20';
        final signature = await ed25519.sign(
          utf8.encode(outer),
          keyPair: keyPair,
        );

        final crypto = CryptoService(
          keystoreService: keystore,
          databaseHelper: dbHelper,
        );
        final outcome = await crypto.verifyEd25519(
          outerSerial: outer,
          innerSerialBase64: base64Encode(signature.bytes),
          keyId: 'K07',
        );

        expect(outcome.isValid, isTrue);
      },
    );
  });
}
