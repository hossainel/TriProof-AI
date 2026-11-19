import 'package:flutter/material.dart';
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/core/l10n/app_strings.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/enums/offline_status.dart';
import 'package:triproof_ai_scanner/domain/models/verification_result.dart';

/// Displays the Green (Verified Genuine), Blue/Green (Repeat Owner), or Red
/// (Fake / Mismatch / Expired / Retroactive Clone / Recalled) verification card.
class VerificationResultCard extends StatelessWidget {
  /// Creates a [VerificationResultCard].
  const VerificationResultCard({
    super.key,
    required this.result,
    required this.language,
    required this.onTransferOwnership,
    this.onScanNext,
  });

  /// Verification result to render.
  final VerificationResult result;

  /// Active language.
  final AppLanguage language;

  /// Callback invoked when the user taps "Transfer Ownership QR".
  final VoidCallback onTransferOwnership;

  /// Optional callback to launch another scan right away.
  final VoidCallback? onScanNext;

  @override
  Widget build(BuildContext context) {
    final s = AppStrings(language);
    final isRepeatOwner = result.offlineStatus == OfflineStatus.repeatOwner &&
        !result.cloudStatus.isRetroactiveAlert;
    final isMasterCarton = result.offlineStatus.isMasterCarton;
    final isGenuine = result.isEffectiveGenuine ||
        result.offlineStatus == OfflineStatus.masterCartonGenuine;

    final Color accentColor;
    final Color backgroundTint;
    final IconData statusIcon;

    if (!isGenuine) {
      accentColor = AppTheme.alertRed;
      backgroundTint = AppTheme.alertSurface;
      statusIcon = Icons.gpp_bad_rounded;
    } else if (isRepeatOwner) {
      accentColor = AppTheme.repeatOwnerBlue;
      backgroundTint = AppTheme.repeatOwnerSurface;
      statusIcon = Icons.verified_user_rounded;
    } else {
      accentColor = AppTheme.genuineGreen;
      backgroundTint = AppTheme.genuineSurface;
      statusIcon = Icons.verified_rounded;
    }

    final headline =
        language == AppLanguage.bangla ? result.headlineBn : result.headlineEn;
    final primaryMsg =
        language == AppLanguage.bangla ? result.messageBn : result.messageEn;
    final secondaryMsg =
        language == AppLanguage.bangla ? result.messageEn : result.messageBn;

    final factoryNames =
        AppConstants.resolveFactoryName(result.record.factoryId);
    final factoryDisplay =
        language == AppLanguage.bangla ? factoryNames.bn : factoryNames.en;

    return Card(
      key: const Key('verification_result_card'),
      elevation: 3,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: accentColor, width: 2),
      ),
      child: Container(
        decoration: BoxDecoration(
          color: backgroundTint,
          borderRadius: BorderRadius.circular(18),
        ),
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Header verdict strip
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: accentColor,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Icon(statusIcon, color: Colors.white, size: 30),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Wrap(
                        spacing: 6,
                        runSpacing: 4,
                        children: [
                          _badge(
                            s.offlineBadgeLabel(result.offlineStatus),
                            accentColor,
                          ),
                          _badge(
                            '${result.latencyMs}ms',
                            AppTheme.brandNavy,
                          ),
                        ],
                      ),
                      const SizedBox(height: 6),
                      Text(
                        headline,
                        style: TextStyle(
                          color: accentColor,
                          fontSize: 17,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            // Primary & bilingual instruction box
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: accentColor.withValues(alpha: 0.35),
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    primaryMsg,
                    style: TextStyle(
                      color: isGenuine ? const Color(0xFF111827) : accentColor,
                      fontWeight: FontWeight.w700,
                      fontSize: 14,
                      height: 1.35,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    secondaryMsg,
                    style: TextStyle(
                      color: Colors.grey.shade700,
                      fontSize: 12.5,
                      height: 1.35,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            // Decoded Supply Chain Metadata Grid
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.9),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(
                children: [
                  _metaRow(s.factoryNameLabel, factoryDisplay),
                  const Divider(height: 14),
                  Row(
                    children: [
                      Expanded(
                        child: _metaCol(
                          s.machineIdLabel,
                          result.record.machineId,
                        ),
                      ),
                      Expanded(
                        child: _metaCol(
                          s.batchIdLabel,
                          result.record.batchId,
                        ),
                      ),
                      Expanded(
                        child: _metaCol(
                          s.unitNumberLabel,
                          result.parsedSerial?.unitNumber ?? '-',
                        ),
                      ),
                    ],
                  ),
                  const Divider(height: 14),
                  Row(
                    children: [
                      Expanded(
                        child: _metaCol(
                          s.prodDateLabel,
                          result.record.prodDate,
                        ),
                      ),
                      Expanded(
                        child: _metaCol(
                          s.expDateLabel,
                          result.record.expDate,
                          highlightColor:
                              result.offlineStatus == OfflineStatus.expired
                                  ? AppTheme.alertRed
                                  : null,
                        ),
                      ),
                      Expanded(
                        child: _metaCol(
                          s.latencyLabel,
                          '${result.verificationMicroseconds} µs',
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 14),
            if (isGenuine && !isMasterCarton)
              FilledButton.icon(
                key: const Key('transfer_ownership_qr_button'),
                onPressed: onTransferOwnership,
                style: FilledButton.styleFrom(
                  backgroundColor: accentColor,
                ),
                icon: const Icon(Icons.qr_code_2_rounded),
                label: Text(s.transferOwnershipQr),
              ),
            if (onScanNext != null) ...[
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: onScanNext,
                icon: const Icon(Icons.qr_code_scanner_rounded),
                label: Text(s.scanNextItem),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _badge(String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color.withValues(alpha: 0.4)),
      ),
      child: Text(
        text,
        style: TextStyle(
          color: color,
          fontSize: 10.5,
          fontWeight: FontWeight.w800,
        ),
      ),
    );
  }

  Widget _metaRow(String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          '$label: ',
          style: TextStyle(
            color: Colors.grey.shade700,
            fontSize: 12.5,
            fontWeight: FontWeight.w600,
          ),
        ),
        Expanded(
          child: Text(
            value,
            style: const TextStyle(
              color: Color(0xFF0F172A),
              fontSize: 13,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
      ],
    );
  }

  Widget _metaCol(String label, String value, {Color? highlightColor}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          label,
          style: TextStyle(
            color: Colors.grey.shade600,
            fontSize: 11,
            fontWeight: FontWeight.w600,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          value,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(
            color: highlightColor ?? const Color(0xFF0F172A),
            fontSize: 12.5,
            fontWeight: FontWeight.w800,
          ),
        ),
      ],
    );
  }
}
