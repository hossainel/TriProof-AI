import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:triproof_ai_scanner/core/l10n/app_strings.dart';
import 'package:triproof_ai_scanner/data/repositories/sync_repository.dart';
import 'package:triproof_ai_scanner/data/repositories/verification_repository.dart';
import 'package:triproof_ai_scanner/data/services/crypto_service.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/demo_simulator_service.dart';
import 'package:triproof_ai_scanner/data/services/location_time_service.dart';
import 'package:triproof_ai_scanner/data/services/notification_service.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/enums/scan_mode.dart';
import 'package:triproof_ai_scanner/domain/models/app_notification.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';
import 'package:triproof_ai_scanner/domain/models/verification_result.dart';
import 'package:uuid/uuid.dart';

/// Central reactive ViewModel coordinating scanning, offline verification,
/// bilingual state, SQLite history, and store-and-forward cloud sync.
class AppViewModel extends ChangeNotifier {
  /// Creates an [AppViewModel].
  AppViewModel({
    required SecureKeystoreService keystoreService,
    required CryptoService cryptoService,
    required DatabaseHelper databaseHelper,
    required VerificationRepository verificationRepository,
    required SyncRepository syncRepository,
    required NotificationService notificationService,
    LocationTimeService? locationTimeService,
    DemoSimulatorService? demoSimulatorService,
    Uuid? uuid,
  })  : _keystoreService = keystoreService,
        _cryptoService = cryptoService,
        _databaseHelper = databaseHelper,
        _verificationRepository = verificationRepository,
        _syncRepository = syncRepository,
        _notificationService = notificationService,
        _locationTimeService = locationTimeService,
        _demoSimulator = demoSimulatorService ?? DemoSimulatorService(),
        _uuid = uuid ?? const Uuid();

  final SecureKeystoreService _keystoreService;
  final CryptoService _cryptoService;
  final DatabaseHelper _databaseHelper;
  final VerificationRepository _verificationRepository;
  final SyncRepository _syncRepository;
  final NotificationService _notificationService;
  final LocationTimeService? _locationTimeService;
  final DemoSimulatorService _demoSimulator;
  final Uuid _uuid;

  StreamSubscription<bool>? _onlineSub;
  StreamSubscription<SyncBatchOutcome>? _syncSub;

  AppLanguage _language = AppLanguage.english;
  ScanMode _scanMode = ScanMode.consumerUnboxing;
  bool _isInitialized = false;
  bool _isOnline = false;
  bool _isSyncing = false;
  int _pendingSyncCount = 0;
  int _lastLatencyMs = 0;
  String _deviceToken = '';
  DateTime? _highWaterMarkUtc;

  late String _qaSessionId = 'QA-${_uuid.v4()}';
  int _qaSessionBoxCount = 0;
  String? _lastDemoGenuineOuterSerial;

  List<ScanRecord> _history = const [];
  String _searchQuery = '';
  List<AppNotification> _notifications = const [];
  int _unreadNotificationCount = 0;
  AppNotification? _activeBannerAlert;
  VerificationResult? _latestResult;

  /// Active UI display language (`EN` or `বাংলা`).
  AppLanguage get language => _language;

  /// Localized string helper for the active [language].
  AppStrings get strings => AppStrings(_language);

  /// Active scanning verification mode.
  ScanMode get scanMode => _scanMode;

  /// Whether startup initialization has completed.
  bool get isInitialized => _isInitialized;

  /// Whether network connectivity (Wi-Fi / Mobile / Ethernet) is active.
  bool get isOnline => _isOnline;

  /// Whether a store-and-forward cloud sync cycle is in flight.
  bool get isSyncing => _isSyncing;

  /// Number of `pending_sync` rows waiting in local SQLite.
  int get pendingSyncCount => _pendingSyncCount;

  /// Measured latency of the latest offline verification in milliseconds (`0` before first scan).
  int get lastLatencyMs => _lastLatencyMs;

  /// Hardware-bound device token persisted in Keystore / Secure Enclave.
  String get deviceToken => _deviceToken;

  /// Monotonic anti-clock-rollback High-Water Mark timestamp.
  DateTime? get highWaterMarkUtc => _highWaterMarkUtc;

  /// Number of boxes verified in the current Factory QA pallet session.
  int get qaSessionBoxCount => _qaSessionBoxCount;

  /// Current active pallet session ID.
  String get qaSessionId => _qaSessionId;

  /// Searchable scan history list (newest first).
  List<ScanRecord> get history => _history;

  /// Current search filter on scan history.
  String get searchQuery => _searchQuery;

  /// Notifications in `notifications_inbox`.
  List<AppNotification> get notifications => _notifications;

  /// Count of unread notifications.
  int get unreadNotificationCount => _unreadNotificationCount;

  /// High-visibility banner alert surfaced when a retroactive clone/recall arrives.
  AppNotification? get activeBannerAlert => _activeBannerAlert;

  /// Most recent verification result card shown after a scan or simulation.
  VerificationResult? get latestResult => _latestResult;

  /// Initializes hardware keystore, SQLite data, notifications, and
  /// `connectivity_plus` listener.
  Future<void> initialize() async {
    if (_isInitialized) return;

    await _keystoreService.initialize();
    await _cryptoService.warmUp();
    await _databaseHelper.database;
    _deviceToken = await _keystoreService.getOrCreateDeviceToken();
    _highWaterMarkUtc = await _keystoreService.getHighWaterMark();

    await _syncRepository.startMonitoring(
      activeLanguageProvider: () => _language,
    );

    _isOnline = _syncRepository.isOnline;

    _onlineSub = _syncRepository.onlineStream.listen((online) {
      _isOnline = online;
      notifyListeners();
    });

    _syncSub = _syncRepository.syncOutcomes.listen((outcome) async {
      _isSyncing = false;
      if (outcome.latestAlert != null) {
        _activeBannerAlert = outcome.latestAlert;
      }
      _highWaterMarkUtc = await _keystoreService.getHighWaterMark();
      await _reloadDbCollections();
    });

    await _reloadDbCollections();
    _isInitialized = true;
    notifyListeners();

    // Defer OS permission prompts until UI has rendered.
    unawaited(_notificationService.initialize());
    if (_locationTimeService != null) {
      unawaited(_locationTimeService.refreshLocationAndClock());
    }
  }

  /// Toggles between English and Bangla (`EN / বাংলা`).
  void toggleLanguage() {
    _language = _language == AppLanguage.english
        ? AppLanguage.bangla
        : AppLanguage.english;
    notifyListeners();
  }

  /// Explicitly sets UI display [lang].
  void setLanguage(AppLanguage lang) {
    if (_language == lang) return;
    _language = lang;
    notifyListeners();
  }

  /// Switches between `Consumer Unboxing` and `Factory Packing QA`.
  void setScanMode(ScanMode mode) {
    if (_scanMode == mode) return;
    _scanMode = mode;
    notifyListeners();
  }

  /// Starts a fresh pallet/shelf inspection session in Factory QA Mode.
  void resetQaSession() {
    _qaSessionId = 'QA-${_uuid.v4()}';
    _qaSessionBoxCount = 0;
    notifyListeners();
  }

  /// Verifies an [(outerSerial, innerSerial)] pair completely offline in <50ms.
  Future<VerificationResult> verifyCapturedPair({
    required String outerSerial,
    required String innerSerial,
    bool forceActivePalletSession = false,
  }) async {
    final useSession =
        _scanMode == ScanMode.factoryPackingQa || forceActivePalletSession;
    final sessionId = useSession ? _qaSessionId : 'CONSUMER-${_uuid.v4()}';

    final result = await _verificationRepository.verifyOffline(
      outerSerial: outerSerial,
      innerSerial: innerSerial,
      mode: _scanMode,
      sessionId: sessionId,
    );

    if (useSession) {
      _qaSessionBoxCount++;
    }
    _latestResult = result;
    _lastLatencyMs = result.latencyMs;
    _highWaterMarkUtc = await _keystoreService.getHighWaterMark();
    await _reloadDbCollections();
    return result;
  }

  /// Runs a real Ed25519-signed offline simulator scenario in Airplane Mode.
  Future<VerificationResult> runDemoScenario(
    DemoScenario scenario, {
    bool simulateRepeatOwner = false,
    bool simulateDuplicateBoxCollision = false,
  }) async {
    String? fixedSerial;
    if ((simulateRepeatOwner || simulateDuplicateBoxCollision) &&
        _lastDemoGenuineOuterSerial != null) {
      fixedSerial = _lastDemoGenuineOuterSerial;
    }

    final pair = await _demoSimulator.generateScenario(
      scenario,
      fixedOuterSerial: fixedSerial,
    );

    if (scenario == DemoScenario.genuineProduct && fixedSerial == null) {
      _lastDemoGenuineOuterSerial = pair.outerSerialOrOcr;
    }

    return verifyCapturedPair(
      outerSerial: pair.outerSerialOrOcr,
      innerSerial: pair.innerSerial,
      forceActivePalletSession: simulateDuplicateBoxCollision,
    );
  }

  /// Triggers an immediate cloud cross-check sync (real HTTP or demo mock).
  Future<SyncBatchOutcome> triggerManualSync({
    bool useDemoMockServer = false,
  }) async {
    _isSyncing = true;
    notifyListeners();

    final outcome = await _syncRepository.syncNow(
      language: _language,
      overrideClient: useDemoMockServer ? const DemoMockCloudApiClient() : null,
    );

    _isSyncing = false;
    if (outcome.latestAlert != null) {
      _activeBannerAlert = outcome.latestAlert;
    }
    _highWaterMarkUtc = await _keystoreService.getHighWaterMark();
    await _reloadDbCollections();
    return outcome;
  }

  /// Updates the scan history search filter and reloads matching SQLite rows.
  Future<void> refreshHistory({String? query}) async {
    if (query != null) {
      _searchQuery = query;
    }
    _history = await _databaseHelper.getScanHistory(query: _searchQuery);
    _pendingSyncCount = await _databaseHelper.countPendingSync();
    notifyListeners();
  }

  /// Finds an existing [ScanRecord] by its [outerSerial].
  Future<ScanRecord?> findRecordBySerial(String outerSerial) {
    return _databaseHelper.findLatestByOuterSerial(outerSerial);
  }

  /// Marks a single notification as read.
  Future<void> markNotificationRead(int id) async {
    await _databaseHelper.markNotificationRead(id);
    if (_activeBannerAlert?.id == id) {
      _activeBannerAlert = null;
    }
    await _reloadDbCollections();
  }

  /// Marks all notifications in `notifications_inbox` as read.
  Future<void> markAllNotificationsRead() async {
    await _databaseHelper.markAllNotificationsRead();
    _activeBannerAlert = null;
    await _reloadDbCollections();
  }

  /// Dismisses the top high-visibility warning banner without deleting the log.
  void dismissBannerAlert() {
    _activeBannerAlert = null;
    notifyListeners();
  }

  /// Clears the currently highlighted latest scan result card.
  void clearLatestResult() {
    _latestResult = null;
    notifyListeners();
  }

  /// Deletes the scan history row identified by [syncUuid] and refreshes
  /// history / pending-sync count.
  Future<void> deleteScanRecord(String syncUuid) async {
    await _databaseHelper.deleteScanRecord(syncUuid);
    await _reloadDbCollections();
  }

  /// Deletes every row from local scan history and refreshes collections.
  Future<void> clearScanHistory() async {
    await _databaseHelper.clearScanHistory();
    await _reloadDbCollections();
  }

  /// Builds the cryptographic QR payload for secondhand ownership transfer.
  Future<String> generateTransferOwnershipQr(ScanRecord record) async {
    final token = _deviceToken.isNotEmpty
        ? _deviceToken
        : await _keystoreService.getOrCreateDeviceToken();
    return _cryptoService.buildOwnershipTransferPayload(
      outerSerial: record.outerSerial,
      unitHash: record.unitHash,
      currentDeviceToken: token,
      timestampUtc: DateTime.now().toUtc(),
    );
  }

  Future<void> _reloadDbCollections() async {
    _history = await _databaseHelper.getScanHistory(query: _searchQuery);
    _pendingSyncCount = await _databaseHelper.countPendingSync();
    _notifications = await _databaseHelper.getNotifications();
    _unreadNotificationCount = await _databaseHelper.countUnreadNotifications();

    if (_activeBannerAlert == null) {
      for (final notif in _notifications) {
        if (!notif.isRead && notif.severity == 'critical') {
          _activeBannerAlert = notif;
          break;
        }
      }
    }
    notifyListeners();
  }

  @override
  void dispose() {
    unawaited(_onlineSub?.cancel());
    unawaited(_syncSub?.cancel());
    super.dispose();
  }
}
