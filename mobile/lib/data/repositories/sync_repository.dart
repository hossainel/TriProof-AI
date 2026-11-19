import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:triproof_ai_scanner/data/services/api_client.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/feedback_service.dart';
import 'package:triproof_ai_scanner/data/services/notification_service.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/models/app_notification.dart';

/// Summary of one store-and-forward cloud synchronization pass.
class SyncBatchOutcome {
  /// Creates a [SyncBatchOutcome].
  const SyncBatchOutcome({
    required this.syncedCount,
    required this.retroactiveAlertsTriggered,
    required this.newNotificationsCount,
    required this.blacklistUpdatedCount,
    this.latestAlert,
    this.errorMessage,
  });

  /// Number of `pending_sync` scan records processed.
  final int syncedCount;

  /// Number of scans retroactively upgraded to clone/recall alerts.
  final int retroactiveAlertsTriggered;

  /// Total new notifications stored in `notifications_inbox`.
  final int newNotificationsCount;

  /// Number of blacklist entries refreshed in `cached_blacklist`.
  final int blacklistUpdatedCount;

  /// Most critical newly arrived alert, if any.
  final AppNotification? latestAlert;

  /// Non-null if the network sync encountered a transport error.
  final String? errorMessage;

  /// Whether any retroactive clone or recall alert was discovered.
  bool get hasRetroactiveAlerts => retroactiveAlertsTriggered > 0;
}

/// Manages network connectivity listening (`connectivity_plus`) and the
/// store-and-forward cloud cross-check + retroactive alert feedback loop.
class SyncRepository {
  /// Creates a [SyncRepository].
  SyncRepository({
    required SecureKeystoreService keystoreService,
    required DatabaseHelper databaseHelper,
    required CloudApiClient apiClient,
    required NotificationService notificationService,
    required FeedbackService feedbackService,
    Connectivity? connectivity,
  })  : _keystoreService = keystoreService,
        _databaseHelper = databaseHelper,
        _apiClient = apiClient,
        _notificationService = notificationService,
        _feedbackService = feedbackService,
        _connectivity = connectivity ?? Connectivity();

  final SecureKeystoreService _keystoreService;
  final DatabaseHelper _databaseHelper;
  final CloudApiClient _apiClient;
  final NotificationService _notificationService;
  final FeedbackService _feedbackService;
  final Connectivity _connectivity;

  final StreamController<SyncBatchOutcome> _outcomeController =
      StreamController<SyncBatchOutcome>.broadcast();
  final StreamController<bool> _onlineController =
      StreamController<bool>.broadcast();

  StreamSubscription<List<ConnectivityResult>>? _connectivitySubscription;
  bool _isSyncing = false;
  bool _isOnline = false;

  /// Whether the device currently has an active Wi-Fi, mobile, or ethernet
  /// transport.
  bool get isOnline => _isOnline;

  /// Whether a store-and-forward sync cycle is currently executing.
  bool get isSyncing => _isSyncing;

  /// Broadcast stream of sync outcomes.
  Stream<SyncBatchOutcome> get syncOutcomes => _outcomeController.stream;

  /// Broadcast stream of online/offline status changes.
  Stream<bool> get onlineStream => _onlineController.stream;

  /// Starts listening to `connectivity_plus` changes and checks initial state.
  Future<void> startMonitoring({
    AppLanguage Function()? activeLanguageProvider,
  }) async {
    try {
      final initial = await _connectivity.checkConnectivity();
      _handleConnectivityUpdate(
        initial,
        language: activeLanguageProvider?.call() ?? AppLanguage.english,
      );
    } catch (_) {
      _setOnline(false);
    }

    await _connectivitySubscription?.cancel();
    _connectivitySubscription = _connectivity.onConnectivityChanged.listen(
      (results) => _handleConnectivityUpdate(
        results,
        language: activeLanguageProvider?.call() ?? AppLanguage.english,
      ),
    );
  }

  void _handleConnectivityUpdate(
    List<ConnectivityResult> results, {
    required AppLanguage language,
  }) {
    final connected = results.contains(ConnectivityResult.wifi) ||
        results.contains(ConnectivityResult.mobile) ||
        results.contains(ConnectivityResult.ethernet);

    final transitionedToOnline = connected && !_isOnline;
    _setOnline(connected);

    if (transitionedToOnline) {
      unawaited(syncNow(language: language));
    }
  }

  void _setOnline(bool online) {
    if (_isOnline != online) {
      _isOnline = online;
      if (!_onlineController.isClosed) {
        _onlineController.add(online);
      }
    }
  }

  /// Executes store-and-forward upload of all `pending_sync` records, fetches
  /// notifications + `<50KB` blacklist delta, updates clock High-Water Mark, and
  /// triggers local OS push notifications on any retroactive clone/recall hit.
  Future<SyncBatchOutcome> syncNow({
    AppLanguage language = AppLanguage.english,
    CloudApiClient? overrideClient,
  }) async {
    if (_isSyncing) {
      return const SyncBatchOutcome(
        syncedCount: 0,
        retroactiveAlertsTriggered: 0,
        newNotificationsCount: 0,
        blacklistUpdatedCount: 0,
      );
    }
    _isSyncing = true;

    final client = overrideClient ?? _apiClient;

    try {
      final deviceToken = await _keystoreService.getOrCreateDeviceToken();
      final pending = await _databaseHelper.getPendingSyncRecords();
      final pendingByUuid = {for (final r in pending) r.syncUuid: r};

      var syncedCount = 0;
      var retroactiveCount = 0;
      var newNotifCount = 0;
      AppNotification? latestAlert;

      if (pending.isNotEmpty) {
        final crossCheck = await client.postOnlineCrossCheck(
          deviceToken: deviceToken,
          records: pending,
        );

        await _keystoreService.updateHighWaterMarkIfNewer(
          crossCheck.serverTimeUtc,
        );

        for (final item in crossCheck.verdicts) {
          final target = pendingByUuid[item.syncUuid];
          if (target == null) continue;

          final cloudStatus = item.toCloudStatus();
          syncedCount++;

          if (cloudStatus.isRetroactiveAlert) {
            retroactiveCount++;
            final isClone = cloudStatus == CloudStatus.retroactiveCloneAlert;

            final msgEn = item.messageEn ??
                (isClone
                    ? 'RETROACTIVE CLONE ALERT: Serial ${target.outerSerial} was previously redeemed on another device.'
                    : 'RETROACTIVE BATCH RECALL: Batch ${target.batchId} has been recalled by regulatory authority.');
            final msgBn = item.messageBn ??
                (isClone
                    ? 'সতর্কতা: এই সিরিয়ালটি ইতিপূর্বে অন্য ডিভাইসে ব্যবহৃত হয়েছে — এটি একটি ক্লোন পণ্য (${target.outerSerial})'
                    : 'সতর্কতা: এই ব্যাচটি (${target.batchId}) অনলাইন যাচাইয়ে রিকল বা বাতিল ঘোষিত হয়েছে — পণ্যটি ব্যবহার করবেন না।');
            final titleEn = item.titleEn ??
                (isClone
                    ? 'Counterfeit Clone Detected!'
                    : 'Precision Batch Recall Alert!');
            final titleBn = item.titleBn ??
                (isClone
                    ? 'রেট্রোঅ্যাক্টিভ ক্লোন সতর্কতা!'
                    : 'ব্যাচ রিকল সতর্কতা!');

            await _databaseHelper.updateCloudVerdict(
              syncUuid: item.syncUuid,
              cloudStatus: cloudStatus,
              feedbackMessageEn: msgEn,
              feedbackMessageBn: msgBn,
            );

            final notif = await _databaseHelper.insertNotification(
              AppNotification(
                outerSerial: target.outerSerial,
                severity: 'critical',
                titleEn: titleEn,
                messageEn: msgEn,
                titleBn: titleBn,
                messageBn: msgBn,
                isRead: false,
                createdAt: DateTime.now().toUtc(),
              ),
            );
            newNotifCount++;
            latestAlert = notif;

            await _notificationService.showRetroactiveAlert(
              id: notif.id ?? target.syncUuid.hashCode,
              title: language == AppLanguage.bangla ? titleBn : titleEn,
              body: language == AppLanguage.bangla ? msgBn : msgEn,
              payload: target.outerSerial,
            );
          } else {
            await _databaseHelper.updateCloudVerdict(
              syncUuid: item.syncUuid,
              cloudStatus: CloudStatus.verifiedClean,
            );
          }
        }
      }

      // Provision the public versioned Ed25519 key ring (no JWT required).
      // Best-effort: a transient well-known endpoint failure must not abort
      // the store-and-forward cross-check.
      try {
        final keyRing = await client.fetchPublicKeyRing();
        if (keyRing.isNotEmpty) {
          await _keystoreService.upsertPublicKeyRing(keyRing);
        }
      } catch (_) {
        // Keep the previously provisioned key ring.
      }

      // Fetch server notifications & <50KB blacklist updates.
      final feed = await client.fetchNotificationsAndBlacklist(
        deviceToken: deviceToken,
      );
      await _keystoreService.updateHighWaterMarkIfNewer(feed.serverTimeUtc);

      if (feed.bloomFilter != null) {
        await _databaseHelper.saveBloomFilter(feed.bloomFilter!);
      }

      for (final incoming in feed.notifications) {
        final stored = await _databaseHelper.insertNotification(incoming);
        newNotifCount++;
        if (stored.severity == 'critical' || stored.severity == 'high') {
          latestAlert ??= stored;
          await _notificationService.showRetroactiveAlert(
            id: stored.id ?? incoming.outerSerial.hashCode,
            title: language == AppLanguage.bangla
                ? stored.titleBn
                : stored.titleEn,
            body: language == AppLanguage.bangla
                ? stored.messageBn
                : stored.messageEn,
            payload: stored.outerSerial,
          );
        }
      }

      if (feed.blacklistEntries.isNotEmpty) {
        await _databaseHelper.upsertBlacklistEntries(feed.blacklistEntries);
      }

      if (retroactiveCount > 0) {
        await _feedbackService.playAlertFeedback();
      }

      final outcome = SyncBatchOutcome(
        syncedCount: syncedCount,
        retroactiveAlertsTriggered: retroactiveCount,
        newNotificationsCount: newNotifCount,
        blacklistUpdatedCount: feed.blacklistEntries.length,
        latestAlert: latestAlert,
      );
      if (!_outcomeController.isClosed) {
        _outcomeController.add(outcome);
      }
      return outcome;
    } catch (e) {
      final outcome = SyncBatchOutcome(
        syncedCount: 0,
        retroactiveAlertsTriggered: 0,
        newNotificationsCount: 0,
        blacklistUpdatedCount: 0,
        errorMessage: e.toString(),
      );
      if (!_outcomeController.isClosed) {
        _outcomeController.add(outcome);
      }
      return outcome;
    } finally {
      _isSyncing = false;
    }
  }

  /// Releases connectivity subscriptions and stream controllers.
  Future<void> dispose() async {
    await _connectivitySubscription?.cancel();
    await _outcomeController.close();
    await _onlineController.close();
  }
}
