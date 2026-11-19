import 'package:flutter/material.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/ui/view_models/app_view_model.dart';

/// Persistent top app bar displaying the Offline/Online Status Pill
/// (`OFFLINE READY - 0ms` / `ONLINE SYNCED`) and the `EN / বাংলা` toggle.
class TopStatusBar extends StatelessWidget implements PreferredSizeWidget {
  /// Creates a [TopStatusBar].
  const TopStatusBar({
    super.key,
    required this.viewModel,
  });

  /// Central view model providing language, sync, and latency state.
  final AppViewModel viewModel;

  @override
  Size get preferredSize => const Size.fromHeight(76);

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: viewModel,
      builder: (context, _) {
        final s = viewModel.strings;
        final isOnline = viewModel.isOnline;
        final isSyncing = viewModel.isSyncing;
        final pending = viewModel.pendingSyncCount;
        final latency = viewModel.lastLatencyMs;

        final Color pillColor;
        final IconData pillIcon;
        final String pillLabel;

        if (isSyncing) {
          pillColor = AppTheme.warningAmber;
          pillIcon = Icons.sync;
          pillLabel = s.syncingPill;
        } else if (isOnline && pending == 0) {
          pillColor = AppTheme.genuineGreen;
          pillIcon = Icons.cloud_done_rounded;
          pillLabel = s.onlineSyncedPill;
        } else if (isOnline && pending > 0) {
          pillColor = AppTheme.repeatOwnerBlue;
          pillIcon = Icons.cloud_upload_rounded;
          pillLabel = '${s.pendingSyncPillPrefix} ($pending)';
        } else {
          pillColor = const Color(0xFF15803D);
          pillIcon = Icons.bolt_rounded;
          pillLabel = '${s.offlineReadyPillPrefix} - ${latency}ms';
        }

        return AppBar(
          toolbarHeight: 76,
          titleSpacing: 14,
          title: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                s.appTitle,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 17,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 0.2,
                ),
              ),
              const SizedBox(height: 4),
              Semantics(
                label: 'Connectivity and latency status: $pillLabel',
                child: Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 3,
                  ),
                  decoration: BoxDecoration(
                    color: pillColor,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(pillIcon, size: 13, color: Colors.white),
                      const SizedBox(width: 5),
                      Text(
                        pillLabel,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 0.4,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
          actions: [
            Padding(
              padding: const EdgeInsets.only(right: 12),
              child: Semantics(
                button: true,
                label: 'Switch language between English and Bangla',
                child: InkWell(
                  key: const Key('language_toggle_button'),
                  onTap: viewModel.toggleLanguage,
                  borderRadius: BorderRadius.circular(22),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(
                      minHeight: 44,
                      minWidth: 48,
                    ),
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 6,
                      ),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.14),
                        borderRadius: BorderRadius.circular(22),
                        border: Border.all(
                          color: Colors.white.withValues(alpha: 0.35),
                        ),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          _langChip(
                            'EN',
                            selected: viewModel.language == AppLanguage.english,
                          ),
                          const SizedBox(width: 4),
                          _langChip(
                            'বাংলা',
                            selected: viewModel.language == AppLanguage.bangla,
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _langChip(String text, {required bool selected}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
      decoration: BoxDecoration(
        color: selected ? Colors.white : Colors.transparent,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Text(
        text,
        style: TextStyle(
          color: selected ? AppTheme.brandNavy : Colors.white,
          fontSize: 11.5,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }
}
