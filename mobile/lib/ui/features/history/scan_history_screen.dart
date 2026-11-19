import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/enums/offline_status.dart';
import 'package:triproof_ai_scanner/domain/models/parsed_outer_serial.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';
import 'package:triproof_ai_scanner/domain/models/verification_result.dart';
import 'package:triproof_ai_scanner/ui/core/widgets/transfer_ownership_sheet.dart';
import 'package:triproof_ai_scanner/ui/core/widgets/verification_result_card.dart';
import 'package:triproof_ai_scanner/ui/view_models/app_view_model.dart';

enum _HistoryFilter { all, genuine, alerts, pending }

/// Searchable local SQLite scan audit log showing both `Offline Verdict` and
/// updated `Cloud Cross-Check Verdict` badges on every row.
class ScanHistoryScreen extends StatefulWidget {
  /// Creates a [ScanHistoryScreen].
  const ScanHistoryScreen({
    super.key,
    required this.viewModel,
  });

  /// Central ViewModel.
  final AppViewModel viewModel;

  @override
  State<ScanHistoryScreen> createState() => _ScanHistoryScreenState();
}

class _ScanHistoryScreenState extends State<ScanHistoryScreen> {
  final TextEditingController _searchController = TextEditingController();
  _HistoryFilter _filter = _HistoryFilter.all;

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: widget.viewModel,
      builder: (context, _) {
        final s = widget.viewModel.strings;
        final isBn = widget.viewModel.language == AppLanguage.bangla;
        final records = _applyFilter(widget.viewModel.history);

        return Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 800),
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
                  child: Column(
                    children: [
                      TextField(
                        key: const Key('history_search_field'),
                        controller: _searchController,
                        onChanged: (value) =>
                            widget.viewModel.refreshHistory(query: value),
                        decoration: InputDecoration(
                          hintText: s.searchHistoryHint,
                          prefixIcon: const Icon(Icons.search_rounded),
                          suffixIcon: _searchController.text.isNotEmpty
                              ? IconButton(
                                  icon: const Icon(Icons.clear_rounded),
                                  onPressed: () {
                                    _searchController.clear();
                                    widget.viewModel.refreshHistory(query: '');
                                  },
                                )
                              : null,
                        ),
                      ),
                      const SizedBox(height: 10),
                      SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: Row(
                          children: [
                            _filterChip(
                              _HistoryFilter.all,
                              isBn ? 'সব' : 'All',
                            ),
                            const SizedBox(width: 8),
                            _filterChip(
                              _HistoryFilter.genuine,
                              isBn ? 'আসল' : 'Genuine',
                            ),
                            const SizedBox(width: 8),
                            _filterChip(
                              _HistoryFilter.alerts,
                              isBn ? 'নকল / সতর্কতা' : 'Alerts & Rejected',
                            ),
                            const SizedBox(width: 8),
                            _filterChip(
                              _HistoryFilter.pending,
                              isBn ? 'সিঙ্ক বাকি' : 'Pending Sync',
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                if (records.isNotEmpty)
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton.icon(
                      key: const Key('clear_all_history_button'),
                      onPressed: () => _confirmClearAll(context),
                      icon: const Icon(Icons.delete_forever_rounded, size: 18),
                      label: Text(s.clearAllHistory),
                      style: TextButton.styleFrom(foregroundColor: AppTheme.alertRed),
                    ),
                  ),
                Expanded(
                  child: records.isEmpty
                      ? _emptyState(s.noHistoryTitle, s.noHistorySubtitle)
                      : ListView.builder(
                          padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                          itemCount: records.length,
                          itemBuilder: (context, index) => _historyTile(
                            context,
                            records[index],
                          ),
                        ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  List<ScanRecord> _applyFilter(List<ScanRecord> input) {
    switch (_filter) {
      case _HistoryFilter.all:
        return input;
      case _HistoryFilter.genuine:
        return input
            .where((r) =>
                _isAuthentic(r.offlineStatus) &&
                !r.cloudStatus.isRetroactiveAlert)
            .toList();
      case _HistoryFilter.alerts:
        return input
            .where((r) =>
                !_isAuthentic(r.offlineStatus) ||
                r.cloudStatus.isRetroactiveAlert)
            .toList();
      case _HistoryFilter.pending:
        return input
            .where((r) => r.cloudStatus == CloudStatus.pendingSync)
            .toList();
    }
  }

  Widget _filterChip(_HistoryFilter target, String label) {
    final selected = _filter == target;
    return ChoiceChip(
      label: Text(label),
      selected: selected,
      selectedColor: AppTheme.brandNavy,
      labelStyle: TextStyle(
        color: selected ? Colors.white : const Color(0xFF1E293B),
        fontWeight: FontWeight.w700,
        fontSize: 12.5,
      ),
      onSelected: (_) => setState(() => _filter = target),
    );
  }

  Widget _historyTile(BuildContext context, ScanRecord record) {
    final s = widget.viewModel.strings;
    final isBn = widget.viewModel.language == AppLanguage.bangla;
    final offlineColor = _offlineColor(record.offlineStatus);
    final cloudColor = _cloudColor(record.cloudStatus);
    final factoryName = AppConstants.resolveFactoryName(record.factoryId);
    final timeStr =
        DateFormat('yyyy-MM-dd HH:mm').format(record.scannedAt.toLocal());

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: () => _openDetailsSheet(context, record),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      isBn ? factoryName.bn : factoryName.en,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 14,
                        color: AppTheme.brandNavy,
                      ),
                    ),
                  ),
                  IconButton(
                    key: const Key('delete_history_item_button'),
                    tooltip: s.deleteHistoryItem,
                    onPressed: () => _confirmDelete(context, record),
                    icon: const Icon(
                      Icons.delete_outline_rounded,
                      size: 20,
                    ),
                  ),
                  Text(
                    '${record.latencyMs}ms',
                    style: TextStyle(
                      fontSize: 11.5,
                      fontWeight: FontWeight.w700,
                      color: Colors.grey.shade600,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              Text(
                record.outerSerial,
                style: const TextStyle(
                  fontFamily: 'monospace',
                  fontSize: 12,
                  fontWeight: FontWeight.w700,
                  color: Color(0xFF334155),
                ),
              ),
              const SizedBox(height: 8),
              // Dual verdict badges: Initial Offline Verdict + Cloud Cross-Check Verdict
              Wrap(
                spacing: 6,
                runSpacing: 6,
                children: [
                  _verdictBadge(
                    label:
                        '${isBn ? "অফলাইন" : "Offline"}: ${s.offlineBadgeLabel(record.offlineStatus)}',
                    color: offlineColor,
                  ),
                  _verdictBadge(
                    label:
                        '${isBn ? "ক্লাউড" : "Cloud"}: ${s.cloudBadgeLabel(record.cloudStatus)}',
                    color: cloudColor,
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Text(
                    '${s.batchIdLabel}: ${record.batchId}',
                    style: TextStyle(
                      fontSize: 11.5,
                      color: Colors.grey.shade700,
                    ),
                  ),
                  const Spacer(),
                  Text(
                    timeStr,
                    style: TextStyle(
                      fontSize: 11.5,
                      color: Colors.grey.shade600,
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

  Widget _verdictBadge({required String label, required Color color}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color.withValues(alpha: 0.45)),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontSize: 11,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }

  bool _isAuthentic(OfflineStatus status) =>
      status.isGenuinePass || status == OfflineStatus.masterCartonGenuine;

  Color _offlineColor(OfflineStatus status) {
    switch (status) {
      case OfflineStatus.genuineOffline:
      case OfflineStatus.masterCartonGenuine:
        return AppTheme.genuineGreen;
      case OfflineStatus.repeatOwner:
        return AppTheme.repeatOwnerBlue;
      case OfflineStatus.fakeSignature:
      case OfflineStatus.expired:
      case OfflineStatus.duplicateBox:
      case OfflineStatus.recalledCached:
      case OfflineStatus.clockRollback:
      case OfflineStatus.masterCartonMismatch:
        return AppTheme.alertRed;
    }
  }

  Color _cloudColor(CloudStatus status) {
    switch (status) {
      case CloudStatus.pendingSync:
        return AppTheme.warningAmber;
      case CloudStatus.verifiedClean:
        return AppTheme.genuineGreen;
      case CloudStatus.retroactiveCloneAlert:
      case CloudStatus.retroactiveRecallAlert:
        return AppTheme.alertRed;
    }
  }

  Future<void> _confirmDelete(BuildContext context, ScanRecord record) async {
    final s = widget.viewModel.strings;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(s.confirmDeleteItem),
        content: Text(record.outerSerial),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(s.cancelAction)),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(s.deleteAction, style: const TextStyle(color: AppTheme.alertRed)),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      await widget.viewModel.deleteScanRecord(record.syncUuid);
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(s.historyDeleted)),
        );
      }
    }
  }

  Future<void> _confirmClearAll(BuildContext context) async {
    final s = widget.viewModel.strings;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(s.clearAllHistory),
        content: Text(s.confirmClearAll),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(s.cancelAction)),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: Text(s.deleteAction, style: const TextStyle(color: AppTheme.alertRed)),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      await widget.viewModel.clearScanHistory();
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(s.historyCleared)),
        );
      }
    }
  }

  Future<void> _openDetailsSheet(
    BuildContext context,
    ScanRecord record,
  ) async {
    final s = widget.viewModel.strings;
    final parsed = ParsedOuterSerial.tryParse(record.outerSerial);
    final syntheticResult = VerificationResult(
      record: record,
      parsedSerial: parsed,
      offlineStatus: record.offlineStatus,
      cloudStatus: record.cloudStatus,
      verificationMicroseconds: record.latencyMs * 1000,
      scanMode: record.scanMode,
      headlineEn: record.cloudStatus.isRetroactiveAlert
          ? s.cloudBadgeLabel(record.cloudStatus)
          : s.offlineBadgeLabel(record.offlineStatus),
      headlineBn: record.cloudStatus.isRetroactiveAlert
          ? s.cloudBadgeLabel(record.cloudStatus)
          : s.offlineBadgeLabel(record.offlineStatus),
      messageEn: record.feedbackMessageEn,
      messageBn: record.feedbackMessageBn,
    );

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => Padding(
        padding: const EdgeInsets.fromLTRB(12, 0, 12, 16),
            child: VerificationResultCard(
              result: syntheticResult,
              language: widget.viewModel.language,
              onTransferOwnership: () {
                Navigator.of(context).pop();
                TransferOwnershipSheet.show(
                  context,
                  record: record,
                  transferPayloadFuture:
                      widget.viewModel.generateTransferOwnershipQr(record),
                  language: widget.viewModel.language,
                );
              },
              onDelete: () async {
                Navigator.of(context).pop();
                await _confirmDelete(context, record);
              },
            ),
      ),
    );
  }

  Widget _emptyState(String title, String subtitle) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.history_rounded, size: 54, color: Colors.grey.shade400),
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
