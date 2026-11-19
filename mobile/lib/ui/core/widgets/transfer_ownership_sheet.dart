import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';

/// Modal bottom sheet generating a signed Ownership Transfer QR code for
/// secondhand resale or gifting of verified genuine items.
class TransferOwnershipSheet extends StatelessWidget {
  /// Creates a [TransferOwnershipSheet].
  const TransferOwnershipSheet({
    super.key,
    required this.record,
    required this.transferPayloadFuture,
    required this.language,
  });

  /// Verified product scan record being transferred.
  final ScanRecord record;

  /// Future resolving to the cryptographic JSON QR payload.
  final Future<String> transferPayloadFuture;

  /// Active UI display language.
  final AppLanguage language;

  /// Opens the [TransferOwnershipSheet] as a modal bottom sheet.
  static Future<void> show(
    BuildContext context, {
    required ScanRecord record,
    required Future<String> transferPayloadFuture,
    required AppLanguage language,
  }) {
    return showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => TransferOwnershipSheet(
        record: record,
        transferPayloadFuture: transferPayloadFuture,
        language: language,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isBn = language == AppLanguage.bangla;

    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
      child: FutureBuilder<String>(
        future: transferPayloadFuture,
        builder: (context, snapshot) {
          if (!snapshot.hasData) {
            return const SizedBox(
              height: 280,
              child: Center(child: CircularProgressIndicator()),
            );
          }

          final payload = snapshot.data!;

          return Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 42,
                height: 4,
                decoration: BoxDecoration(
                  color: Colors.grey.shade300,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              const SizedBox(height: 16),
              Text(
                isBn
                    ? 'মালিকানা হস্তান্তর QR কোড'
                    : 'Ownership Transfer QR Token',
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w900,
                  color: AppTheme.brandNavy,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                isBn
                    ? 'রিসেল বা উপহার দেওয়ার সময় ক্রেতা এই QR স্ক্যান করে আসল মালিকানা যাচাই করতে পারবেন।'
                    : 'Have the recipient scan this token to verify provenance during secondhand resale or gifting.',
                textAlign: TextAlign.center,
                style: TextStyle(color: Colors.grey.shade700, fontSize: 13),
              ),
              const SizedBox(height: 18),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(color: Colors.grey.shade300),
                ),
                child: QrImageView(
                  data: payload,
                  version: QrVersions.auto,
                  size: 210,
                  backgroundColor: Colors.white,
                ),
              ),
              const SizedBox(height: 14),
              Text(
                record.outerSerial,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontFamily: 'monospace',
                  fontSize: 11.5,
                  fontWeight: FontWeight.w700,
                ),
              ),
              const SizedBox(height: 14),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () async {
                        await Clipboard.setData(ClipboardData(text: payload));
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text(
                                isBn
                                    ? 'হস্তান্তর টোকেন কপি করা হয়েছে'
                                    : 'Transfer token copied',
                              ),
                            ),
                          );
                        }
                      },
                      icon: const Icon(Icons.copy_rounded, size: 18),
                      label: Text(isBn ? 'টোকেন কপি' : 'Copy Token'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: FilledButton(
                      onPressed: () => Navigator.of(context).pop(),
                      child: Text(isBn ? 'সম্পন্ন' : 'Done'),
                    ),
                  ),
                ],
              ),
            ],
          );
        },
      ),
    );
  }
}
