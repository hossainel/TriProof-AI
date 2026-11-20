import 'package:flutter/material.dart';
import 'package:triproof_ai_scanner/core/l10n/app_strings.dart';

/// Bilingual card for pointing the installed APK at any TriProof website and
/// refreshing offline verification keys / blacklist data over HTTPS.
class WebsiteSyncCard extends StatefulWidget {
  /// Creates a [WebsiteSyncCard].
  const WebsiteSyncCard({
    super.key,
    required this.strings,
    required this.serverUrl,
    required this.lastSyncedAt,
    required this.trustedKeyCount,
    required this.isSyncing,
    required this.message,
    required this.onUpdate,
    this.onUrlChanged,
  });

  /// Localized strings for the active language.
  final AppStrings strings;

  /// Currently configured server URL.
  final String serverUrl;

  /// Last successful trust sync timestamp.
  final DateTime? lastSyncedAt;

  /// Number of locally cached trusted keys.
  final int trustedKeyCount;

  /// Whether a sync is currently running.
  final bool isSyncing;

  /// Optional feedback message from the latest manual sync.
  final String? message;

  /// Called with the entered website URL when the update button is pressed.
  final Future<void> Function(String url) onUpdate;

  /// Optional callback when the URL field is edited.
  final ValueChanged<String>? onUrlChanged;

  @override
  State<WebsiteSyncCard> createState() => _WebsiteSyncCardState();
}

class _WebsiteSyncCardState extends State<WebsiteSyncCard> {
  late final TextEditingController _controller;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.serverUrl);
  }

  @override
  void didUpdateWidget(covariant WebsiteSyncCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.serverUrl != widget.serverUrl &&
        _controller.text != widget.serverUrl) {
      _controller.text = widget.serverUrl;
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.strings;
    final status = widget.lastSyncedAt == null
        ? s.notYetSynced
        : '${s.activeKeysLabel}: ${widget.trustedKeyCount} · '
              '${s.lastUpdatedLabel}: ${widget.lastSyncedAt!.toLocal()}';

    return Card(
      key: const Key('website_sync_card'),
      elevation: 2,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              s.websiteSyncTitle,
              style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 10),
            TextField(
              key: const Key('website_sync_server_url_field'),
              controller: _controller,
              onChanged: widget.onUrlChanged,
              keyboardType: TextInputType.url,
              autocorrect: false,
              decoration: InputDecoration(
                labelText: s.serverUrlLabel,
                hintText: 'https://your-domain.com',
                border: const OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 10),
            Text(
              status,
              key: const Key('website_sync_status'),
              style: const TextStyle(fontSize: 11, color: Colors.black54),
            ),
            if (widget.message != null) ...[
              const SizedBox(height: 6),
              Text(
                widget.message!,
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ],
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                key: const Key('website_sync_update_button'),
                onPressed: widget.isSyncing
                    ? null
                    : () => widget.onUpdate(_controller.text.trim()),
                icon: widget.isSyncing
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.cloud_download_outlined),
                label: Text(
                  widget.isSyncing ? s.syncingPill : s.updateFromWebsiteButton,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
