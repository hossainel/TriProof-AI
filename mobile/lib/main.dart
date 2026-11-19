import 'dart:async';

import 'package:flutter/material.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/data/repositories/sync_repository.dart';
import 'package:triproof_ai_scanner/data/repositories/verification_repository.dart';
import 'package:triproof_ai_scanner/data/services/api_client.dart';
import 'package:triproof_ai_scanner/data/services/crypto_service.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/feedback_service.dart';
import 'package:triproof_ai_scanner/data/services/location_time_service.dart';
import 'package:triproof_ai_scanner/data/services/notification_service.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/ui/core/widgets/retroactive_alert_banner.dart';
import 'package:triproof_ai_scanner/ui/core/widgets/top_status_bar.dart';
import 'package:triproof_ai_scanner/ui/features/alerts/alerts_center_screen.dart';
import 'package:triproof_ai_scanner/ui/features/history/scan_history_screen.dart';
import 'package:triproof_ai_scanner/ui/features/home/home_scanner_screen.dart';
import 'package:triproof_ai_scanner/ui/view_models/app_view_model.dart';

/// Application entry point bootstrapping hardware keystore, offline Ed25519
/// cryptography, SQLCipher database, ML Kit scanner, and store-and-forward sync.
Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final keystoreService = SecureKeystoreService();
  final cryptoService = CryptoService(keystoreService: keystoreService);
  final databaseHelper = DatabaseHelper(keystoreService: keystoreService);
  final feedbackService = DeviceFeedbackService();
  final locationTimeService =
      GeolocatorTimeService(keystoreService: keystoreService);
  final notificationService = LocalPushNotificationService();
  final apiClient = HttpCloudApiClient();

  final verificationRepository = VerificationRepository(
    keystoreService: keystoreService,
    cryptoService: cryptoService,
    databaseHelper: databaseHelper,
    feedbackService: feedbackService,
    locationTimeService: locationTimeService,
  );

  final syncRepository = SyncRepository(
    keystoreService: keystoreService,
    databaseHelper: databaseHelper,
    apiClient: apiClient,
    notificationService: notificationService,
    feedbackService: feedbackService,
  );

  final viewModel = AppViewModel(
    keystoreService: keystoreService,
    cryptoService: cryptoService,
    databaseHelper: databaseHelper,
    verificationRepository: verificationRepository,
    syncRepository: syncRepository,
    notificationService: notificationService,
    locationTimeService: locationTimeService,
  );

  await viewModel.initialize();

  runApp(
    TriProofApp(
      viewModel: viewModel,
      feedbackService: feedbackService,
    ),
  );
}

/// Root widget for **TriProof AI Scanner**.
class TriProofApp extends StatelessWidget {
  /// Creates [TriProofApp].
  const TriProofApp({
    super.key,
    required this.viewModel,
    required this.feedbackService,
  });

  /// Central reactive application state.
  final AppViewModel viewModel;

  /// Feedback service forwarded to the guided camera scanner.
  final FeedbackService feedbackService;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'TriProof AI Scanner',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.buildLightTheme(),
      home: TriProofShell(
        viewModel: viewModel,
        feedbackService: feedbackService,
      ),
    );
  }
}

/// Primary 3-tab shell hosting Scanner Home, Scan History, and Alerts Center
/// beneath the persistent bilingual status top-bar and retroactive warning banner.
class TriProofShell extends StatefulWidget {
  /// Creates [TriProofShell].
  const TriProofShell({
    super.key,
    required this.viewModel,
    required this.feedbackService,
  });

  /// Central ViewModel.
  final AppViewModel viewModel;

  /// Feedback service.
  final FeedbackService feedbackService;

  @override
  State<TriProofShell> createState() => _TriProofShellState();
}

class _TriProofShellState extends State<TriProofShell> {
  int _selectedIndex = 0;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.viewModel,
      builder: (context, _) {
        final s = widget.viewModel.strings;
        final banner = widget.viewModel.activeBannerAlert;
        final unread = widget.viewModel.unreadNotificationCount;

        return Scaffold(
          appBar: TopStatusBar(viewModel: widget.viewModel),
          body: Column(
            children: [
              if (banner != null)
                RetroactiveAlertBanner(
                  alert: banner,
                  language: widget.viewModel.language,
                  onInspectAlerts: () {
                    setState(() => _selectedIndex = 2);
                  },
                  onDismiss: widget.viewModel.dismissBannerAlert,
                ),
              Expanded(
                child: IndexedStack(
                  index: _selectedIndex,
                  children: [
                    HomeScannerScreen(
                      viewModel: widget.viewModel,
                      feedbackService: widget.feedbackService,
                    ),
                    ScanHistoryScreen(viewModel: widget.viewModel),
                    AlertsCenterScreen(viewModel: widget.viewModel),
                  ],
                ),
              ),
            ],
          ),
          bottomNavigationBar: NavigationBar(
            selectedIndex: _selectedIndex,
            onDestinationSelected: (index) {
              setState(() => _selectedIndex = index);
              if (index == 1) {
                unawaited(widget.viewModel.refreshHistory());
              }
            },
            destinations: [
              NavigationDestination(
                icon: const Icon(Icons.qr_code_scanner_outlined),
                selectedIcon: const Icon(Icons.qr_code_scanner_rounded),
                label: s.tabScanner,
              ),
              NavigationDestination(
                icon: const Icon(Icons.history_outlined),
                selectedIcon: const Icon(Icons.history_rounded),
                label: s.tabHistory,
              ),
              NavigationDestination(
                icon: Badge(
                  isLabelVisible: unread > 0,
                  label: Text('$unread'),
                  child: const Icon(Icons.notifications_outlined),
                ),
                selectedIcon: Badge(
                  isLabelVisible: unread > 0,
                  label: Text('$unread'),
                  child: const Icon(Icons.notifications_rounded),
                ),
                label: s.tabAlerts,
              ),
            ],
          ),
        );
      },
    );
  }
}
