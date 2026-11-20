import 'package:flutter_test/flutter_test.dart';
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/core/utils/ocr_serial_normalizer.dart';
import 'package:triproof_ai_scanner/data/repositories/verification_repository.dart';
import 'package:triproof_ai_scanner/data/services/crypto_service.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/demo_simulator_service.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/domain/enums/offline_status.dart';
import 'package:triproof_ai_scanner/domain/enums/scan_mode.dart';
import 'package:triproof_ai_scanner/domain/models/parsed_outer_serial.dart';

import 'test_fakes.dart';

void main() {
  group('ParsedOuterSerial & OcrSerialNormalizer', () {
    test('parses valid canonical outer serial format', () {
      final parsed = ParsedOuterSerial.tryParse(
        'BD01-M04-B202611-000482|2026-11-01|2028-11-01',
      );
      expect(parsed, isNotNull);
      expect(parsed!.factoryId, 'BD01');
      expect(parsed.machineId, 'M04');
      expect(parsed.batchId, 'B202611');
      expect(parsed.unitNumber, '000482');
      expect(parsed.prodDateIso, '2026-11-01');
      expect(parsed.expDateIso, '2028-11-01');
    });

    test('rejects invalid calendar dates and expDate < prodDate', () {
      expect(
        ParsedOuterSerial.tryParse(
          'BD01-M04-B202611-000482|2026-02-30|2028-11-01',
        ),
        isNull,
      );
      expect(
        ParsedOuterSerial.tryParse(
          'BD01-M04-B202611-000482|2028-11-01|2026-11-01',
        ),
        isNull,
      );
    });

    test('repairs curved/smudged OCR label with broken pipes and O/0 confusion',
        () {
      const degraded = 'LOT: BD01-M08-B202611-000489\nI 2O26-11-O1 l 2028-11-01';
      final repaired = OcrSerialNormalizer.extractAndNormalize(degraded);
      expect(repaired, 'BD01-M08-B202611-000489|2026-11-01|2028-11-01');
    });
  });

  group('Offline Ed25519 Cryptography & Verification Pipeline', () {
    late InMemorySecureStore secureStore;
    late SecureKeystoreService keystoreService;
    late CryptoService cryptoService;
    late DatabaseHelper dbHelper;
    late FakeFeedbackService feedbackService;
    late DemoSimulatorService demoSimulator;
    late DateTime currentClockUtc;
    late VerificationRepository repository;

    setUp(() async {
      currentClockUtc = DateTime.utc(2026, 11, 18, 12, 0);
      secureStore = InMemorySecureStore();
      keystoreService = SecureKeystoreService(store: secureStore);
      await keystoreService.initialize(currentUtc: currentClockUtc);

      cryptoService = CryptoService(keystoreService: keystoreService);
      await cryptoService.warmUp();
      dbHelper = createInMemoryDatabaseHelper(keystoreService);
      await dbHelper.database;
      feedbackService = FakeFeedbackService();
      demoSimulator = DemoSimulatorService();

      repository = VerificationRepository(
        keystoreService: keystoreService,
        cryptoService: cryptoService,
        databaseHelper: dbHelper,
        feedbackService: feedbackService,
        locationTimeService: FakeLocationTimeService(),
        clock: () => currentClockUtc,
      );
    });

    tearDown(() async {
      await dbHelper.close();
    });

    test('verifies genuine Ed25519 tag offline in under 50ms', () async {
      final pair = await demoSimulator.generateScenario(
        DemoScenario.genuineProduct,
        referenceUtc: currentClockUtc,
      );

      final result = await repository.verifyOffline(
        outerSerial: pair.outerSerialOrOcr,
        innerSerial: pair.innerSerial,
        mode: ScanMode.consumerUnboxing,
        sessionId: 'S-1',
      );

      expect(result.offlineStatus, OfflineStatus.genuineOffline);
      expect(result.isEffectiveGenuine, isTrue);
      expect(result.latencyMs, lessThan(AppConstants.offlineLatencyBudgetMs));
      expect(feedbackService.genuineCount, 1);
      expect(feedbackService.alertCount, 0);
    });

    test('normalizes a smart URL QR payload to the canonical signed serial and hash',
        () async {
      final pair = await demoSimulator.generateScenario(
        DemoScenario.genuineProduct,
        referenceUtc: currentClockUtc,
      );
      final smartUrl =
          'https://t.focusautomobiles.com/v/${Uri.encodeComponent(pair.outerSerialOrOcr)}';

      final result = await repository.verifyOffline(
        outerSerial: smartUrl,
        innerSerial: pair.innerSerial,
        mode: ScanMode.consumerUnboxing,
        sessionId: 'SMART-URL',
      );

      expect(result.offlineStatus, OfflineStatus.genuineOffline);
      expect(result.record.outerSerial, pair.outerSerialOrOcr);
      expect(
        result.record.unitHash,
        await cryptoService.computeUnitHash(
          pair.outerSerialOrOcr,
          pair.innerSerial,
        ),
      );
    });

    test('detects repeat owner on different session vs duplicate box in same session',
        () async {
      final pair = await demoSimulator.generateScenario(
        DemoScenario.genuineProduct,
        referenceUtc: currentClockUtc,
      );

      final first = await repository.verifyOffline(
        outerSerial: pair.outerSerialOrOcr,
        innerSerial: pair.innerSerial,
        mode: ScanMode.consumerUnboxing,
        sessionId: 'SESSION-A',
      );
      expect(first.offlineStatus, OfflineStatus.genuineOffline);

      // Separate session on same device -> calm Repeat Owner badge.
      final repeat = await repository.verifyOffline(
        outerSerial: pair.outerSerialOrOcr,
        innerSerial: pair.innerSerial,
        mode: ScanMode.consumerUnboxing,
        sessionId: 'SESSION-B',
      );
      expect(repeat.offlineStatus, OfflineStatus.repeatOwner);
      expect(repeat.priorRegisteredDate, isNotNull);
      expect(repeat.isEffectiveGenuine, isTrue);

      // Same session pallet collision -> Duplicate Box Alert.
      final duplicate = await repository.verifyOffline(
        outerSerial: pair.outerSerialOrOcr,
        innerSerial: pair.innerSerial,
        mode: ScanMode.factoryPackingQa,
        sessionId: 'SESSION-A',
      );
      expect(duplicate.offlineStatus, OfflineStatus.duplicateBox);
      expect(duplicate.isEffectiveGenuine, isFalse);
      expect(feedbackService.alertCount, 1);
    });

    test('rejects swapped/counterfeit Ed25519 signature with Bangla warning',
        () async {
      final pair = await demoSimulator.generateScenario(
        DemoScenario.swappedTagMismatch,
        referenceUtc: currentClockUtc,
      );

      final result = await repository.verifyOffline(
        outerSerial: pair.outerSerialOrOcr,
        innerSerial: pair.innerSerial,
        mode: ScanMode.consumerUnboxing,
      );

      expect(result.offlineStatus, OfflineStatus.fakeSignature);
      expect(result.isEffectiveGenuine, isFalse);
      expect(
        result.messageBn,
        contains('বক্স এবং ভেতরের ট্যাগের সিরিয়াল মিলছে না'),
      );
      expect(feedbackService.alertCount, 1);
    });

    test('enforces two-stage expiry: 45-day Factory QA vs full ExpDate Consumer',
        () async {
      final pair = await demoSimulator.generateScenario(
        DemoScenario.shortDatedFactoryQaFail,
        referenceUtc: currentClockUtc,
      );

      final consumerResult = await repository.verifyOffline(
        outerSerial: pair.outerSerialOrOcr,
        innerSerial: pair.innerSerial,
        mode: ScanMode.consumerUnboxing,
        sessionId: 'C-1',
      );
      expect(consumerResult.offlineStatus, OfflineStatus.genuineOffline);

      final qaResult = await repository.verifyOffline(
        outerSerial: pair.outerSerialOrOcr,
        innerSerial: pair.innerSerial,
        mode: ScanMode.factoryPackingQa,
        sessionId: 'QA-1',
      );
      expect(qaResult.offlineStatus, OfflineStatus.expired);
      expect(qaResult.nearExpiryFactoryQa, isTrue);
    });

    test('rejects recalled batch from <50KB local cached_blacklist', () async {
      final pair = await demoSimulator.generateScenario(
        DemoScenario.recalledCachedBatch,
        referenceUtc: currentClockUtc,
      );

      final result = await repository.verifyOffline(
        outerSerial: pair.outerSerialOrOcr,
        innerSerial: pair.innerSerial,
        mode: ScanMode.consumerUnboxing,
      );

      expect(result.offlineStatus, OfflineStatus.recalledCached);
      expect(result.blacklistReason, isNotNull);
      expect(result.isEffectiveGenuine, isFalse);
    });

    test('blocks scan when device clock is rolled back below High-Water Mark',
        () async {
      final pair = await demoSimulator.generateScenario(
        DemoScenario.expiredProduct,
        referenceUtc: currentClockUtc,
      );

      // Simulate user turning phone clock back 30 days to bypass expiry!
      currentClockUtc = currentClockUtc.subtract(const Duration(days: 30));

      final result = await repository.verifyOffline(
        outerSerial: pair.outerSerialOrOcr,
        innerSerial: pair.innerSerial,
        mode: ScanMode.consumerUnboxing,
      );

      expect(result.offlineStatus, OfflineStatus.clockRollback);
      expect(result.isEffectiveGenuine, isFalse);
      // High-Water Mark must never roll backward.
      final hwm = await keystoreService.getHighWaterMark();
      expect(hwm!.isAfter(currentClockUtc), isTrue);
    });
  });
}
