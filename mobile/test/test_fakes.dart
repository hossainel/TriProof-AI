import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:triproof_ai_scanner/data/services/api_client.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/feedback_service.dart';
import 'package:triproof_ai_scanner/data/services/location_time_service.dart';
import 'package:triproof_ai_scanner/data/services/notification_service.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/domain/models/app_notification.dart';
import 'package:triproof_ai_scanner/domain/models/blacklist_entry.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';

/// In-memory key-value secure store for fast headless tests.
class InMemorySecureStore implements KeyValueSecureStore {
  final Map<String, String> data = <String, String>{};

  @override
  Future<String?> read(String key) async => data[key];

  @override
  Future<void> write(String key, String value) async {
    data[key] = value;
  }

  @override
  Future<void> delete(String key) async {
    data.remove(key);
  }
}

/// No-op audio/haptic service recording invocation counts.
class FakeFeedbackService implements FeedbackService {
  int genuineCount = 0;
  int alertCount = 0;
  int stepCount = 0;

  @override
  Future<void> playGenuineFeedback() async {
    genuineCount++;
  }

  @override
  Future<void> playAlertFeedback() async {
    alertCount++;
  }

  @override
  Future<void> playStepCaptureFeedback() async {
    stepCount++;
  }
}

/// Deterministic location/clock service returning fixed Dhaka coordinates.
class FakeLocationTimeService implements LocationTimeService {
  FakeLocationTimeService({this.lat = 23.8103, this.lng = 90.4125});

  final double? lat;
  final double? lng;

  @override
  ({double? lat, double? lng}) getCachedCoordinates() => (lat: lat, lng: lng);

  @override
  Future<({double? lat, double? lng, DateTime? timestampUtc})>
      refreshLocationAndClock() async {
    return (lat: lat, lng: lng, timestampUtc: null);
  }
}

/// Recording local notification service for testing retroactive alerts.
class FakeNotificationService implements NotificationService {
  bool initialized = false;
  final List<({int id, String title, String body, String? payload})> shown = [];

  @override
  Future<void> initialize() async {
    initialized = true;
  }

  @override
  Future<void> showRetroactiveAlert({
    required int id,
    required String title,
    required String body,
    String? payload,
  }) async {
    shown.add((id: id, title: title, body: body, payload: payload));
  }
}

/// Configurable cloud API client stub for testing retroactive sync loops.
class FakeCloudApiClient implements CloudApiClient {
  FakeCloudApiClient({
    required this.verdictBuilder,
    this.incomingNotifications = const [],
    this.incomingBlacklist = const [],
    this.publicKeyRing = const {},
    this.bloomFilter,
    DateTime? serverTimeUtc,
  }) : serverTimeUtc = serverTimeUtc ?? DateTime.now().toUtc();

  final List<CrossCheckVerdictItem> Function(List<ScanRecord> records)
      verdictBuilder;
  final List<AppNotification> incomingNotifications;
  final List<BlacklistEntry> incomingBlacklist;
  final DateTime serverTimeUtc;
  final Map<String, String> publicKeyRing;
  final Map<String, dynamic>? bloomFilter;

  int postCalls = 0;
  int feedCalls = 0;

  @override
  Future<CrossCheckBatchResponse> postOnlineCrossCheck({
    required String deviceToken,
    required List<ScanRecord> records,
  }) async {
    postCalls++;
    return CrossCheckBatchResponse(
      verdicts: verdictBuilder(records),
      serverTimeUtc: serverTimeUtc,
    );
  }

  @override
  Future<CloudFeedResponse> fetchNotificationsAndBlacklist({
    required String deviceToken,
  }) async {
    feedCalls++;
    return CloudFeedResponse(
      notifications: incomingNotifications,
      blacklistEntries: incomingBlacklist,
      serverTimeUtc: serverTimeUtc,
      bloomFilter: bloomFilter,
    );
  }

  @override
  Future<Map<String, String>> fetchPublicKeyRing() async {
    return Map<String, String>.from(publicKeyRing);
  }
}

/// Fake [Connectivity] avoiding platform channel dependencies in widget tests.
class FakeConnectivity implements Connectivity {
  FakeConnectivity({
    List<ConnectivityResult> initial = const [ConnectivityResult.none],
  }) : _current = initial;

  final List<ConnectivityResult> _current;
  final StreamController<List<ConnectivityResult>> _controller =
      StreamController<List<ConnectivityResult>>.broadcast();

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => _current;

  @override
  Stream<List<ConnectivityResult>> get onConnectivityChanged =>
      _controller.stream;

  Future<void> close() => _controller.close();
}

/// Creates a real SQLite [DatabaseHelper] backed by `sqflite_common_ffi` in
/// memory (no background isolate so `testWidgets` never deadlocks on ports).
DatabaseHelper createInMemoryDatabaseHelper(
  SecureKeystoreService keystoreService,
) {
  sqfliteFfiInit();
  return DatabaseHelper(
    keystoreService: keystoreService,
    testDatabaseOpener: (version, onCreate, onUpgrade) async {
      return databaseFactoryFfiNoIsolate.openDatabase(
        inMemoryDatabasePath,
        options: OpenDatabaseOptions(
          version: version,
          onCreate: onCreate,
          onUpgrade: onUpgrade,
        ),
      );
    },
  );
}
