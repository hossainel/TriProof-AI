import 'dart:io';

import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Abstraction for local OS push notifications triggered when retroactive clone
/// or batch recall alerts arrive upon cloud sync.
abstract interface class NotificationService {
  /// Initializes the platform notification channels and permissions.
  Future<void> initialize();

  /// Displays a high-priority local OS push notification.
  Future<void> showRetroactiveAlert({
    required int id,
    required String title,
    required String body,
    String? payload,
  });
}

/// [FlutterLocalNotificationsPlugin] (v22+) implementation.
class LocalPushNotificationService implements NotificationService {
  /// Creates a [LocalPushNotificationService].
  LocalPushNotificationService({FlutterLocalNotificationsPlugin? plugin})
      : _plugin = plugin ?? FlutterLocalNotificationsPlugin();

  static const String _channelId = 'triproof_retroactive_alerts';
  static const String _channelName = 'TriProof Clone & Recall Alerts';
  static const String _channelDescription =
      'Critical counterfeit clone detections and precision batch recalls.';

  final FlutterLocalNotificationsPlugin _plugin;
  bool _initialized = false;

  @override
  Future<void> initialize() async {
    if (_initialized) return;

    try {
      const androidSettings =
          AndroidInitializationSettings('@mipmap/ic_launcher');
      const darwinSettings = DarwinInitializationSettings(
        requestAlertPermission: true,
        requestBadgePermission: true,
        requestSoundPermission: true,
      );
      const settings = InitializationSettings(
        android: androidSettings,
        iOS: darwinSettings,
      );

      await _plugin.initialize(
        settings: settings,
      );

      if (Platform.isAndroid) {
        final androidImpl = _plugin.resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>();
        await androidImpl?.requestNotificationsPermission();
      }
      _initialized = true;
    } catch (_) {
      // Gracefully skip on unsupported desktop/headless test environments.
    }
  }

  @override
  Future<void> showRetroactiveAlert({
    required int id,
    required String title,
    required String body,
    String? payload,
  }) async {
    if (!_initialized) {
      await initialize();
    }

    try {
      const androidDetails = AndroidNotificationDetails(
        _channelId,
        _channelName,
        channelDescription: _channelDescription,
        importance: Importance.max,
        priority: Priority.high,
        ticker: 'TriProof Security Alert',
      );
      const iosDetails = DarwinNotificationDetails(
        presentAlert: true,
        presentBadge: true,
        presentSound: true,
      );
      const details = NotificationDetails(
        android: androidDetails,
        iOS: iosDetails,
      );

      await _plugin.show(
        id: id,
        title: title,
        body: body,
        notificationDetails: details,
        payload: payload,
      );
    } catch (_) {
      // Ignore failures in headless test environments.
    }
  }
}
