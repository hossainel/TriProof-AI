import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/models/app_notification.dart';
import 'package:triproof_ai_scanner/ui/view_models/app_view_model.dart';

/// Alerts & Feedback Center displaying retroactive clone alerts, precision
/// batch recall notices, and manual/demo store-and-forward sync controls.
class AlertsCenterScreen extends StatelessWidget {
  /// Creates an [AlertsCenterScreen].
  const AlertsCenterScreen({
    super.key,
    required this.viewModel,
  });

  /// Central ViewModel.
  final AppViewModel viewModel;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: viewModel,
      builder: (context, _) {
        final s = viewModel.strings;
        final isBn = viewModel.language == AppLanguage.bangla;
        final notifications = viewModel.notifications;

        return Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 800),
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            children: [
                              const Icon(
                                Icons.sync_problem_rounded,
                                color: AppTheme.brandNavy,
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  isBn
                                      ? 'অপেক্ষমাণ ক্লাউড সিঙ্ক: ${viewModel.pendingSyncCount}'
                                      : 'Pending Cloud Cross-Check Queue: ${viewModel.pendingSyncCount}',
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w800,
                                    fontSize: 13.5,
                                  ),
                                ),
                              ),
                              if (viewModel.unreadNotificationCount > 0)
                                TextButton(
                                  onPressed: viewModel.markAllNotificationsRead,
                                  child: Text(s.markAllRead),
                                ),
                            ],
                          ),
                          const SizedBox(height: 10),
                          Row(
                            children: [
                              Expanded(
                                child: FilledButton.icon(
                                  key: const Key('manual_sync_button'),
                                  onPressed: viewModel.isSyncing
                                      ? null
                                      : () => _runSync(
                                            context,
                                            useDemoMock: false,
                                          ),
                                  icon: viewModel.isSyncing
                                      ? const SizedBox(
                                          width: 16,
                                          height: 16,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2,
                                            color: Colors.white,
                                          ),
                                        )
                                      : const Icon(Icons.cloud_sync_rounded),
                                  label: Text(s.syncNowButton),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          OutlinedButton.icon(
                            key: const Key('simulate_cloud_recall_button'),
                            onPressed: viewModel.isSyncing
                                ? null
                                : () => _runSync(
                                      context,
                                      useDemoMock: true,
                                    ),
                            icon: const Icon(Icons.notifications_active_outlined),
                            label: Text(
                              s.simulateCloudRecallButton,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                Expanded(
                  child: notifications.isEmpty
                      ? _emptyAlerts(s.noAlertsTitle, s.noAlertsSubtitle)
                      : ListView.builder(
                          padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                          itemCount: notifications.length,
                          itemBuilder: (context, index) =>
                              _alertCard(notifications[index]),
                        ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Future<void> _runSync(
    BuildContext context, {
    required bool useDemoMock,
  }) async {
    final outcome = await viewModel.triggerManualSync(
      useDemoMockServer: useDemoMock,
    );
    if (!context.mounted) return;

    final isBn = viewModel.language == AppLanguage.bangla;
    final String msg;
    if (outcome.errorMessage != null) {
      msg = isBn
          ? 'ক্লাউড সার্ভার অনুপলব্ধ — রেকর্ডগুলো নিরাপদে সংরক্ষিত আছে (${viewModel.pendingSyncCount}টি বাকি)।'
          : 'Cloud endpoint unreachable offline — ${viewModel.pendingSyncCount} scan(s) safely queued.';
    } else {
      msg = isBn
          ? 'সিঙ্ক সম্পন্ন: ${outcome.syncedCount}টি যাচাই, ${outcome.retroactiveAlertsTriggered}টি ক্লোন/রিকল সতর্কতা।'
          : 'Synced ${outcome.syncedCount} record(s). Retroactive alerts: ${outcome.retroactiveAlertsTriggered}.';
    }

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(msg)),
    );
  }

  Widget _alertCard(AppNotification notif) {
    final isBn = viewModel.language == AppLanguage.bangla;
    final isCritical = notif.severity == 'critical' || notif.severity == 'high';
    final accent = isCritical ? AppTheme.alertRed : AppTheme.warningAmber;
    final timeStr =
        DateFormat('yyyy-MM-dd HH:mm').format(notif.createdAt.toLocal());

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(
          color: notif.isRead ? Colors.grey.shade200 : accent,
          width: notif.isRead ? 1 : 1.8,
        ),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: notif.id == null
            ? null
            : () => viewModel.markNotificationRead(notif.id!),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: 0.14),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  isCritical
                      ? Icons.gpp_maybe_rounded
                      : Icons.campaign_rounded,
                  color: accent,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            isBn ? notif.titleBn : notif.titleEn,
                            style: TextStyle(
                              fontWeight: FontWeight.w900,
                              fontSize: 14,
                              color: accent,
                            ),
                          ),
                        ),
                        if (!notif.isRead)
                          Container(
                            width: 9,
                            height: 9,
                            decoration: BoxDecoration(
                              color: accent,
                              shape: BoxShape.circle,
                            ),
                          ),
                      ],
                    ),
                    const SizedBox(height: 5),
                    Text(
                      isBn ? notif.messageBn : notif.messageEn,
                      style: const TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        height: 1.35,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      isBn ? notif.messageEn : notif.messageBn,
                      style: TextStyle(
                        fontSize: 12,
                        color: Colors.grey.shade700,
                        height: 1.3,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            notif.outerSerial,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontFamily: 'monospace',
                              fontSize: 11,
                              color: Color(0xFF475569),
                            ),
                          ),
                        ),
                        Text(
                          timeStr,
                          style: TextStyle(
                            fontSize: 11,
                            color: Colors.grey.shade600,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _emptyAlerts(String title, String subtitle) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.notifications_none_rounded,
              size: 56,
              color: AppTheme.genuineGreen,
            ),
            const SizedBox(height: 12),
            Text(
              title,
              style: const TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.w800,
                color: AppTheme.brandNavy,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              subtitle,
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 13, color: Colors.grey.shade600),
            ),
          ],
        ),
      ),
    );
  }
}
