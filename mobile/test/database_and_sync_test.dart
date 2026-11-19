import 'package:flutter_test/flutter_test.dart';
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/data/repositories/sync_repository.dart';
import 'package:triproof_ai_scanner/data/repositories/verification_repository.dart';
import 'package:triproof_ai_scanner/data/services/api_client.dart';
import 'package:triproof_ai_scanner/data/services/crypto_service.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/demo_simulator_service.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/enums/scan_mode.dart';
import 'package:triproof_ai_scanner/domain/models/blacklist_entry.dart';

import 'test_fakes.dart';

void main() {
  group('SQLite Storage & Store-and-Forward Retroactive Sync', () {
    late SecureKeystoreService keystoreService;
    late DatabaseHelper dbHelper;
    late FakeFeedbackService feedbackService;
    late FakeNotificationService notificationService;
    late VerificationRepository verificationRepository;
    late DemoSimulatorService demoSimulator;

    setUp(() async {
      final now = DateTime.utc(2026, 11, 18, 12, 0);
      keystoreService = SecureKeystoreService(store: InMemorySecureStore());
      await keystoreService.initialize(currentUtc: now);

      dbHelper = createInMemoryDatabaseHelper(keystoreService);
      feedbackService = FakeFeedbackService();
      notificationService = FakeNotificationService();
      demoSimulator = DemoSimulatorService();

      verificationRepository = VerificationRepository(
        keystoreService: keystoreService,
        cryptoService: CryptoService(keystoreService: keystoreService),
        databaseHelper: dbHelper,
        feedbackService: feedbackService,
        locationTimeService: FakeLocationTimeService(),
        clock: () => now,
      );
    });

    tearDown(() async {
      await dbHelper.close();
    });

    test('enforces strict <50KB limit on cached_blacklist', () async {
      final oversized = List<BlacklistEntry>.generate(
        600,
        (i) => BlacklistEntry(
          serialOrBatch: 'BD01-M04-B202699-${i.toString().padLeft(6, '0')}',
          reason:
              'Oversized recall entry padding description exceeding 50KB budget',
          updatedAt: DateTime.utc(2026, 11, 18),
        ),
      );

      expect(
        () => dbHelper.upsertBlacklistEntries(oversized),
        throwsStateError,
      );
      expect(
        AppConstants.maxBlacklistPayloadBytes,
        50 * 1024,
      );
    });

    test(
        'uploads pending_sync scans and triggers retroactive clone alert + OS push notification',
        () async {
      final genuinePair = await demoSimulator.generateScenario(
        DemoScenario.genuineProduct,
      );
      final offlineResult = await verificationRepository.verifyOffline(
        outerSerial: genuinePair.outerSerialOrOcr,
        innerSerial: genuinePair.innerSerial,
        mode: ScanMode.consumerUnboxing,
      );

      expect(offlineResult.record.cloudStatus, CloudStatus.pendingSync);
      expect(await dbHelper.countPendingSync(), 1);

      final serverTime = DateTime.utc(2026, 11, 18, 15, 30);
      final fakeClient = FakeCloudApiClient(
        serverTimeUtc: serverTime,
        verdictBuilder: (records) => records
            .map(
              (r) => CrossCheckVerdictItem(
                syncUuid: r.syncUuid,
                verdict: 'clone_detected',
              ),
            )
            .toList(),
        incomingBlacklist: [
          BlacklistEntry(
            serialOrBatch: 'B2026NEWRECALL',
            reason: 'Export hold lot',
            updatedAt: serverTime,
          ),
        ],
      );

      final syncRepo = SyncRepository(
        keystoreService: keystoreService,
        databaseHelper: dbHelper,
        apiClient: fakeClient,
        notificationService: notificationService,
        feedbackService: feedbackService,
      );

      final outcome = await syncRepo.syncNow(language: AppLanguage.bangla);
      expect(outcome.syncedCount, 1);
      expect(outcome.retroactiveAlertsTriggered, 1);
      expect(outcome.blacklistUpdatedCount, 1);

      // Verify scan_history transitioned from pending_sync -> retroactive_clone_alert.
      final updatedHistory = await dbHelper.getScanHistory();
      expect(updatedHistory.length, 1);
      expect(
        updatedHistory.first.cloudStatus,
        CloudStatus.retroactiveCloneAlert,
      );
      expect(
        updatedHistory.first.feedbackMessageBn,
        contains('ইতিপূর্বে অন্য ডিভাইসে ব্যবহৃত হয়েছে'),
      );

      // Verify notifications_inbox + OS push notification triggered.
      final inbox = await dbHelper.getNotifications();
      expect(inbox.length, 1);
      expect(notificationService.shown.length, 1);

      // Verify anti-clock-rollback High-Water Mark advanced to serverTime.
      final hwm = await keystoreService.getHighWaterMark();
      expect(hwm, serverTime);

      await syncRepo.dispose();
    });
  });
}
