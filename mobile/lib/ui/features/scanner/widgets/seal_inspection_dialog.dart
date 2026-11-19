import 'package:flutter/material.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/core/l10n/app_strings.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';

/// Mandatory Physical Seal & Micro-Dot Inspection Prompt shown between Step 1
/// (Outer Box Serial) and Step 2 (Inner Private Tag).
class SealInspectionDialog extends StatelessWidget {
  /// Creates a [SealInspectionDialog].
  const SealInspectionDialog({
    super.key,
    required this.capturedOuterSerial,
    required this.language,
  });

  /// Verified-format outer box serial captured in Step 1.
  final String capturedOuterSerial;

  /// Active UI display language.
  final AppLanguage language;

  /// Displays the modal inspection prompt. Returns `true` if the user confirms
  /// the seal was intact and wants to proceed to Step 2, `false` if damaged/tampered,
  /// or `null` if cancelled.
  static Future<bool?> show(
    BuildContext context, {
    required String capturedOuterSerial,
    required AppLanguage language,
  }) {
    return showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (_) => SealInspectionDialog(
        capturedOuterSerial: capturedOuterSerial,
        language: language,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = AppStrings(language);
    final isBn = language == AppLanguage.bangla;

    return AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      titlePadding: const EdgeInsets.fromLTRB(20, 20, 20, 8),
      contentPadding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
      actionsPadding: const EdgeInsets.fromLTRB(16, 4, 16, 16),
      title: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: AppTheme.warningSurface,
              borderRadius: BorderRadius.circular(12),
            ),
            child: const Icon(
              Icons.verified_user_outlined,
              color: AppTheme.warningAmber,
              size: 26,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              s.sealPromptTitle,
              style: const TextStyle(
                fontSize: 16.5,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
        ],
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: AppTheme.genuineSurface,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(
                color: AppTheme.genuineGreen.withValues(alpha: 0.4),
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  isBn ? 'ধাপ ১ সিরিয়াল গৃহীত:' : 'Step 1 Serial Captured:',
                  style: const TextStyle(
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                    color: AppTheme.genuineGreen,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  capturedOuterSerial,
                  style: const TextStyle(
                    fontFamily: 'monospace',
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 12),
          Text(
            s.sealPromptBody,
            style: const TextStyle(fontSize: 13.5, height: 1.4),
          ),
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: const Color(0xFFF8FAFC),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: const Color(0xFFE2E8F0)),
            ),
            child: Row(
              children: [
                const Icon(
                  Icons.blur_on_rounded,
                  color: AppTheme.brandNavy,
                  size: 22,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    isBn
                        ? 'চেকলিস্ট: (১) স্ক্র্যাচ-অফ লেয়ার অক্ষত, (২) মাইক্রো-ডট হলোগ্রাম স্পষ্ট, (৩) ট্যাম্পার টেপ কাটা নয়।'
                        : 'Checklist: (1) Scratch-off intact, (2) Micro-dot hologram crisp, (3) No prior peel marks.',
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
      actions: [
        Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            FilledButton.icon(
              key: const Key('seal_intact_confirm_button'),
              onPressed: () => Navigator.of(context).pop(true),
              style: FilledButton.styleFrom(
                backgroundColor: AppTheme.genuineGreen,
              ),
              icon: const Icon(Icons.check_circle_outline_rounded),
              label: Text(s.sealIntactProceed),
            ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).pop(false),
              style: OutlinedButton.styleFrom(
                foregroundColor: AppTheme.alertRed,
                side: const BorderSide(color: AppTheme.alertRed),
              ),
              icon: const Icon(Icons.report_problem_outlined),
              label: Text(s.sealTamperedFlag),
            ),
            TextButton(
              onPressed: () => Navigator.of(context).pop(null),
              child: Text(isBn ? 'বাতিল করুন' : 'Cancel Scan'),
            ),
          ],
        ),
      ],
    );
  }
}
