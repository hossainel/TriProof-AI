import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:triproof_ai_scanner/data/repositories/sync_repository.dart';
import 'package:triproof_ai_scanner/data/repositories/verification_repository.dart';
import 'package:triproof_ai_scanner/data/services/crypto_service.dart';
import 'package:triproof_ai_scanner/data/services/demo_simulator_service.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/data/services/trust_sync_service.dart';
import 'package:triproof_ai_scanner/main.dart';
import 'package:triproof_ai_scanner/ui/core/widgets/retroactive_alert_banner.dart';
import 'package:triproof_ai_scanner/ui/view_models/app_view_model.dart';

import 'test_fakes.dart';

class _NoOpTrustSyncService extends TrustSyncService {
  _NoOpTrustSyncService({
    required super.databaseHelper,
    required super.keystoreService,
  });

  @override
  Future<TrustSnapshot> loadSnapshot() async => const TrustSnapshot(
    baseUrl: 'http://triproof.local',
    keys: [],
    lastSyncedAt: null,
  );

  @override
  Future<TrustSyncResult> syncFromWebsite({String? overrideBaseUrl}) async =>
      const TrustSyncResult(
        baseUrl: 'http://triproof.local',
        keys: [],
        lastSyncedAt: null,
        blacklistEntries: 0,
        bloomUpdated: false,
      );
}

void main() {
  testWidgets(
    'Bilingual EN/বাংলা toggle, offline simulator, history & retroactive alert banner',
    (WidgetTester tester) async {
      final store = InMemorySecureStore();
      final keystore = SecureKeystoreService(store: store);
      await keystore.initialize();

      final dbHelper = createInMemoryDatabaseHelper(keystore);
      final feedback = FakeFeedbackService();
      final notif = FakeNotificationService();
      final apiClient = FakeCloudApiClient(
        verdictBuilder: (records) => const [],
      );

      final verificationRepo = VerificationRepository(
        keystoreService: keystore,
        cryptoService: CryptoService(keystoreService: keystore),
        databaseHelper: dbHelper,
        feedbackService: feedback,
        locationTimeService: FakeLocationTimeService(),
      );

      final fakeConnectivity = FakeConnectivity();
      final syncRepo = SyncRepository(
        keystoreService: keystore,
        databaseHelper: dbHelper,
        apiClient: apiClient,
        notificationService: notif,
        feedbackService: feedback,
        connectivity: fakeConnectivity,
      );

      final viewModel = AppViewModel(
        keystoreService: keystore,
        cryptoService: CryptoService(keystoreService: keystore),
        databaseHelper: dbHelper,
        verificationRepository: verificationRepo,
        syncRepository: syncRepo,
        notificationService: notif,
        trustSyncService: _NoOpTrustSyncService(
          databaseHelper: dbHelper,
          keystoreService: keystore,
        ),
      );

      await tester.runAsync(() => viewModel.initialize());

      await tester.pumpWidget(
        TriProofApp(viewModel: viewModel, feedbackService: feedback),
      );
      await tester.pumpAndSettle();

      // 1. Top bar renders offline status pill and EN/বাংলা toggle.
      expect(find.textContaining('OFFLINE READY'), findsOneWidget);

      await tester.tap(find.byKey(const Key('language_toggle_button')));
      await tester.pumpAndSettle();
      expect(find.text('ট্রাইপ্রুফ এআই স্ক্যানার'), findsOneWidget);

      // Toggle back to English for assertions.
      await tester.tap(find.byKey(const Key('language_toggle_button')));
      await tester.pumpAndSettle();

      // 2. Switch to Factory Packing QA Mode.
      await tester.scrollUntilVisible(
        find.byKey(const Key('mode_factory_qa')),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('mode_factory_qa')));
      await tester.pumpAndSettle();
      expect(
        find.textContaining('Active Pallet Session Scans'),
        findsOneWidget,
      );

      // 3. Run offline Genuine Product simulator -> green card + Transfer QR button.
      await tester.runAsync(
        () => viewModel.runDemoScenario(DemoScenario.genuineProduct),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('verification_result_card')), findsOneWidget);
      await tester.scrollUntilVisible(
        find.byKey(const Key('transfer_ownership_qr_button')),
        200,
        scrollable: find.byType(Scrollable).first,
      );
      await tester.pumpAndSettle();
      expect(
        find.byKey(const Key('transfer_ownership_qr_button')),
        findsOneWidget,
      );

      // 4. Switch to History tab -> see Offline + Cloud verdict badges.
      await tester.tap(find.text('History'));
      await tester.pump();
      await tester.runAsync(() => viewModel.refreshHistory());
      await tester.pumpAndSettle();
      expect(find.textContaining('Offline: VERIFIED GENUINE'), findsOneWidget);
      expect(find.textContaining('Cloud: Pending Sync'), findsOneWidget);

      // 5. Switch to Alerts tab -> trigger Demo Retroactive Clone Sync.
      await tester.tap(find.text('Alerts'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('simulate_cloud_recall_button')));
      await tester.pump();
      await tester.runAsync(
        () async => Future<void>.delayed(const Duration(milliseconds: 50)),
      );
      await tester.pumpAndSettle();

      // High-visibility warning banner appears across the app AND notification appears in Alerts Center.
      expect(find.byType(RetroactiveAlertBanner), findsOneWidget);
      expect(
        find.descendant(
          of: find.byType(RetroactiveAlertBanner),
          matching: find.text('RETROACTIVE CLONE ALERT'),
        ),
        findsOneWidget,
      );

      await tester.runAsync(() async {
        await syncRepo.dispose();
        await fakeConnectivity.close();
        await dbHelper.close();
      });
    },
    timeout: const Timeout(Duration(seconds: 30)),
  );
}
