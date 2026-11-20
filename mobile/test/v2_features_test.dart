import 'dart:convert';

import 'package:cryptography/dart.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:triproof_ai_scanner/core/utils/ocr_serial_normalizer.dart';
import 'package:triproof_ai_scanner/core/utils/smart_payload_parser.dart';
import 'package:triproof_ai_scanner/data/repositories/verification_repository.dart';
import 'package:triproof_ai_scanner/data/services/crypto_service.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/demo_simulator_service.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/enums/offline_status.dart';
import 'package:triproof_ai_scanner/domain/enums/scan_mode.dart';
import 'package:triproof_ai_scanner/domain/models/bloom_filter.dart';
import 'package:triproof_ai_scanner/domain/models/blacklist_entry.dart';
import 'package:triproof_ai_scanner/domain/models/parsed_outer_serial.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';

import 'test_fakes.dart';

const _demoHsmSeedBase64 =
    'ixPzGmiWcpKr66kmMRxcQfBG6acJB+WTlO3GrPXQur0=';

ScanRecord _sampleRecord({int scanSeq = 0}) => ScanRecord(
      syncUuid: 'uuid-$scanSeq',
      outerSerial: 'BD01-M04-B202611-000482|2026-11-01|2028-11-01',
      innerSerial: 'signature',
      unitHash: 'unit-hash',
      factoryId: 'BD01',
      machineId: 'M04',
      batchId: 'B202611',
      prodDate: '2026-11-01',
      expDate: '2028-11-01',
      offlineStatus: OfflineStatus.genuineOffline,
      cloudStatus: CloudStatus.pendingSync,
      feedbackMessageEn: 'ok',
      feedbackMessageBn: 'ok',
      scannedAt: DateTime.utc(2026, 11, 18),
      deviceToken: 'TPD-test',
      scanMode: ScanMode.consumerUnboxing,
      sessionId: '',
      latencyMs: 2,
      scanSeq: scanSeq,
    );

void main() {
  group('Smart URL / key version / DPP parsing', () {
    const versioned =
        'K01-BD01-M04-B202611-000482|2026-11-01|2028-11-01|DPP:A1B2C3D4';

    test('parses K01 + DPP serial and strips /v/ smart URL prefix', () {
      final smart = SmartPayloadParser.tryParse('https://tp.ai/v/$versioned');
      expect(smart, isNotNull);
      expect(smart!.canonicalOuter, versioned);
      expect(smart.keyId, 'K01');
      expect(smart.dppDigest, 'A1B2C3D4');
      expect(smart.isVersioned, isTrue);
      expect(smart.hasDppDigest, isTrue);

      final parsed = ParsedOuterSerial.tryParse('https://tp.ai/v/$versioned');
      expect(parsed, isNotNull);
      expect(parsed!.rawSerial, versioned);
      expect(parsed.keyId, 'K01');
      expect(parsed.dppDigest, 'A1B2C3D4');
      expect(parsed.factoryId, 'BD01');
      expect(parsed.machineId, 'M04');
      expect(parsed.batchId, 'B202611');
      expect(parsed.unitNumber, '000482');
      expect(parsed.expDateIso, '2028-11-01');

      expect(
        OcrSerialNormalizer.extractAndNormalize('https://tp.ai/v/$versioned'),
        versioned,
      );
    });

    test('decodes percent-encoded smart URL payload', () {
      final encoded = Uri.encodeComponent(versioned);
      final smart = SmartPayloadParser.tryParse('https://tp.ai/v/$encoded');
      expect(smart!.canonicalOuter, versioned);
      expect(smart.keyId, 'K01');
      expect(smart.dppDigest, 'A1B2C3D4');
    });

    test('preserves v1 unprefixed serial compatibility', () {
      final parsed = ParsedOuterSerial.tryParse(
        'BD01-M04-B202611-000482|2026-11-01|2028-11-01',
      );
      expect(parsed, isNotNull);
      expect(parsed!.keyId, 'K00');
      expect(parsed.dppDigest, '');
      expect(parsed.isVersioned, isFalse);
    });
  });

  group('Bloom filter parity & negative short-circuit', () {
    late SecureKeystoreService keystore;
    late DatabaseHelper dbHelper;

    setUp(() async {
      keystore = SecureKeystoreService(store: InMemorySecureStore());
      await keystore.initialize(currentUtc: DateTime.utc(2026, 11, 18));
      dbHelper = createInMemoryDatabaseHelper(keystore);
      await dbHelper.database;
    });

    tearDown(() async {
      await dbHelper.close();
    });

    test('positive and negative membership match a rebuilt filter', () {
      const present = 'BD01-M04-B202611-000482|2026-11-01|2028-11-01';
      const present2 = 'CTN-K00-BD01-B202611-0001|root|4';
      final bloom = BloomFilter.build(
        [present, present2],
        mBits: 4096,
        kHashes: 4,
      );
      final restored = BloomFilter.fromMap(bloom.toMap());

      expect(restored.isValid, isTrue);
      expect(restored.mightContain(present), isTrue);
      expect(restored.mightContain(present2), isTrue);
      expect(restored.mightContain('SERIAL-NEVER-ADDED-000001'), isFalse);
      expect(
        BloomFilter.fromJson(restored.toJson()).mightContain(present),
        isTrue,
      );
      expect(BloomFilter.fromMap(const {'bits_b64': ''}).isValid, isFalse);
    });

    test('bloom miss skips exact lookup; bloom hit still requires exact entry',
        () async {
      await dbHelper.upsertBlacklistEntries([
        BlacklistEntry(
          serialOrBatch: 'EXACT-ONLY-SERIAL',
          reason: 'Recall',
          updatedAt: DateTime.utc(2026, 11, 18),
        ),
      ]);

      await dbHelper.saveBloomFilter(
        BloomFilter.build(['UNRELATED-SERIAL']).toMap(),
      );
      expect(
        await dbHelper.findBlacklistMatch(
          outerSerial: 'EXACT-ONLY-SERIAL',
          batchId: '',
        ),
        isNull,
      );

      await dbHelper.saveBloomFilter(
        BloomFilter.build(['EXACT-ONLY-SERIAL']).toMap(),
      );
      expect(
        await dbHelper.findBlacklistMatch(
          outerSerial: 'EXACT-ONLY-SERIAL',
          batchId: '',
        ),
        isNotNull,
      );

      expect(await dbHelper.loadBloomFilter(), isNotNull);
    });
  });

  group('Build epoch floor & monotonic scan sequence', () {
    test('initialize floors high-water mark to build epoch', () async {
      final store = InMemorySecureStore();
      await store.write(
        SecureKeystoreService.highWaterMarkKey,
        DateTime.utc(2020, 1, 1).millisecondsSinceEpoch.toString(),
      );
      final floor = DateTime.utc(2025, 1, 1);
      final keystore = SecureKeystoreService(
        store: store,
        buildEpochMs: floor.millisecondsSinceEpoch,
      );

      await keystore.initialize(currentUtc: DateTime.utc(2020, 1, 2));

      final hwm = await keystore.getHighWaterMark();
      expect(hwm, floor);
      expect(await keystore.isClockRolledBack(DateTime.utc(2024, 6, 1)), isTrue);
    });

    test('nextScanSeq is monotonic and survives a fresh keystore instance',
        () async {
      final store = InMemorySecureStore();
      final first = SecureKeystoreService(store: store);
      expect(await first.lastScanSeq(), 0);
      expect(await first.nextScanSeq(), 1);
      expect(await first.nextScanSeq(), 2);

      final second = SecureKeystoreService(store: store);
      expect(await second.lastScanSeq(), 2);
      expect(await second.nextScanSeq(), 3);

      final record = _sampleRecord(scanSeq: 7);
      expect(record.toMap()['scan_seq'], 7);
      expect(record.toCrossCheckJson()['scan_seq'], 7);
      expect(ScanRecord.fromMap(record.toMap()).scanSeq, 7);
      expect(record.copyWith(scanSeq: 9).scanSeq, 9);
    });
  });

  group('Master carton offline verification', () {
    late InMemorySecureStore store;
    late SecureKeystoreService keystore;
    late CryptoService crypto;
    late DatabaseHelper dbHelper;
    late VerificationRepository repository;
    late DateTime clock;

    setUp(() async {
      clock = DateTime.utc(2026, 11, 18, 12);
      store = InMemorySecureStore();
      keystore = SecureKeystoreService(store: store);
      await keystore.initialize(currentUtc: clock);
      crypto = CryptoService(keystoreService: keystore);
      dbHelper = createInMemoryDatabaseHelper(keystore);
      await dbHelper.database;
      repository = VerificationRepository(
        keystoreService: keystore,
        cryptoService: crypto,
        databaseHelper: dbHelper,
        feedbackService: FakeFeedbackService(),
        locationTimeService: FakeLocationTimeService(),
        clock: () => clock,
      );
    });

    tearDown(() async {
      await dbHelper.close();
    });

    Future<String> signedCartonPayload({
      String root = '9f86d081884c7d659a2feaa0c55ad015',
      int count = 4,
      String serial = 'CTN-K00-BD01-B202611-0001',
      String? seedBase64,
    }) async {
      final ed25519 = DartEd25519(sha512: const DartSha512());
      final keyPair = await ed25519.newKeyPairFromSeed(
        base64Decode(seedBase64 ?? _demoHsmSeedBase64),
      );
      final message = '$serial|$root|$count';
      final signature = await ed25519.sign(
        utf8.encode(message),
        keyPair: keyPair,
      );
      return '$message|${base64Encode(signature.bytes)}';
    }

    test('parses and verifies a genuine signed master carton QR payload',
        () async {
      final payload = await signedCartonPayload();
      final carton = SmartPayloadParser.tryParseMasterCarton(payload);
      expect(carton, isNotNull);
      expect(carton!.cartonSerial, 'CTN-K00-BD01-B202611-0001');
      expect(carton.merkleRoot, '9f86d081884c7d659a2feaa0c55ad015');
      expect(carton.unitCount, 4);
      expect(carton.keyId, 'K00');
      expect(carton.signedMessage, payload.substring(0, payload.lastIndexOf('|')));

      final outcome = await crypto.verifyMasterCarton(
        carton,
        await keystore.getPublicKeyRing(),
      );
      expect(outcome.isValid, isTrue);
    });

    test('master carton branch persists genuine and mismatch verdicts', () async {
      final genuinePayload = await signedCartonPayload();
      final genuine = await repository.verifyOffline(
        outerSerial: genuinePayload,
        innerSerial: '',
        mode: ScanMode.consumerUnboxing,
      );
      expect(genuine.offlineStatus, OfflineStatus.masterCartonGenuine);
      expect(genuine.headlineEn, contains('MASTER CARTON'));
      expect(genuine.messageBn, isNotEmpty);
      expect(genuine.record.factoryId, 'BD01');

      final tampered = await signedCartonPayload(
        root: 'deadbeefdeadbeefdeadbeefdeadbeef',
        seedBase64: 'zzPzGmiWcpKr66kmMRxcQfBG6acJB+WTlO3GrPXQur0=',
      );
      final mismatch = await repository.verifyOffline(
        outerSerial: tampered,
        innerSerial: '',
        mode: ScanMode.consumerUnboxing,
      );
      expect(mismatch.offlineStatus, OfflineStatus.masterCartonMismatch);
      expect(mismatch.isEffectiveGenuine, isFalse);

      final history = await dbHelper.getScanHistory();
      expect(history.length, 2);
      expect(history.first.scanSeq, 2);
      expect(history.last.scanSeq, 1);
    });

    test('verifyMasterCarton rejects malformed and tampered payloads', () async {
      final payload = await signedCartonPayload();
      final original = SmartPayloadParser.tryParseMasterCarton(payload)!;
      final tampered = MasterCartonPayload(
        cartonSerial: original.cartonSerial,
        merkleRoot: '00000000000000000000000000000000',
        unitCount: original.unitCount,
        signature: original.signature,
      );
      expect((await crypto.verifyMasterCarton(tampered)).isValid, isFalse);

      final badSignature = MasterCartonPayload(
        cartonSerial: original.cartonSerial,
        merkleRoot: original.merkleRoot,
        unitCount: original.unitCount,
        signature: 'not-a-signature',
      );
      expect((await crypto.verifyMasterCarton(badSignature)).isValid, isFalse);
      expect(
        SmartPayloadParser.tryParseMasterCarton('NOT-A-CARTON|root|4|sig'),
        isNull,
      );
    });

    test('DPP mismatch is reported distinctly by verifyEd25519', () async {
      final signed = await DemoSimulatorService().generateScenario(
        DemoScenario.genuineProduct,
        referenceUtc: clock,
      );
      final versioned = signed.outerSerialOrOcr.replaceFirst(
        'BD01',
        'K00-BD01',
      );
      final versionedWithDpp = '$versioned|DPP:A1B2C3D4';
      final ed25519 = DartEd25519(sha512: const DartSha512());
      final keyPair = await ed25519.newKeyPairFromSeed(
        base64Decode(_demoHsmSeedBase64),
      );
      final signature = await ed25519.sign(
        utf8.encode(versionedWithDpp),
        keyPair: keyPair,
      );
      final outcome = await crypto.verifyEd25519(
        outerSerial: versionedWithDpp,
        innerSerialBase64: base64Encode(signature.bytes),
        expectedDppDigest: 'DEADBEEF',
      );
      expect(outcome.isValid, isFalse);
      expect(outcome.dppMismatch, isTrue);
    });
  });
}
