import 'package:flutter/material.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/models/app_notification.dart';

/// High-visibility bilingual banner surfaced immediately when an online
/// cross-check flags a previously offline-verified product as a clone or recall.
class RetroactiveAlertBanner extends StatelessWidget {
  /// Creates a [RetroactiveAlertBanner].
  const RetroactiveAlertBanner({
    super.key,
    required this.alert,
    required this.language,
    required this.onInspectAlerts,
    required this.onDismiss,
  });

  /// Critical retroactive alert from SQLite `notifications_inbox`.
  final AppNotification alert;

  /// Active display language.
  final AppLanguage language;

  /// Called when the user taps to inspect the alert in the Alerts Center.
  final VoidCallback onInspectAlerts;

  /// Called when the user dismisses the banner.
  final VoidCallback onDismiss;

  @override
  Widget build(BuildContext context) {
    final isBn = language == AppLanguage.bangla;
    final title = isBn ? alert.titleBn : alert.titleEn;
    final body = isBn ? alert.messageBn : alert.messageEn;

    return Material(
      color: AppTheme.alertRed,
      elevation: 4,
      child: SafeArea(
        top: false,
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Padding(
                padding: EdgeInsets.only(top: 2),
                child: Icon(
                  Icons.warning_amber_rounded,
                  color: Colors.white,
                  size: 26,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w900,
                        fontSize: 13.5,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      body,
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 12.5,
                        height: 1.3,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      isBn ? alert.messageEn : alert.messageBn,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.88),
                        fontSize: 11.5,
                      ),
                    ),
                  ],
                ),
              ),
              Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  IconButton(
                    icon: const Icon(Icons.close, color: Colors.white, size: 20),
                    tooltip: 'Dismiss',
                    onPressed: onDismiss,
                  ),
                  TextButton(
                    onPressed: onInspectAlerts,
                    style: TextButton.styleFrom(
                      foregroundColor: Colors.white,
                      backgroundColor: Colors.black.withValues(alpha: 0.22),
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 4,
                      ),
                    ),
                    child: Text(
                      isBn ? 'দেখুন' : 'VIEW',
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 11,
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
