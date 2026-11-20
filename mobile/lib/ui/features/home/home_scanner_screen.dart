import 'package:flutter/material.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/data/services/demo_simulator_service.dart';
import 'package:triproof_ai_scanner/data/services/feedback_service.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/enums/scan_mode.dart';
import 'package:triproof_ai_scanner/ui/core/widgets/transfer_ownership_sheet.dart';
import 'package:triproof_ai_scanner/ui/core/widgets/website_sync_card.dart';
import 'package:triproof_ai_scanner/ui/core/widgets/verification_result_card.dart';
import 'package:triproof_ai_scanner/ui/features/scanner/guided_scanner_screen.dart';
import 'package:triproof_ai_scanner/ui/view_models/app_view_model.dart';

/// Home & Scanner Launch Screen designed for one-handed thumb-zone operation
/// in Bangladeshi factory lines, customs checkpoints, and retail stores.
class HomeScannerScreen extends StatelessWidget {
  /// Creates a [HomeScannerScreen].
  const HomeScannerScreen({
    super.key,
    required this.viewModel,
    required this.feedbackService,
  });

  /// Central view model.
  final AppViewModel viewModel;

  /// Feedback service injected into [GuidedScannerScreen].
  final FeedbackService feedbackService;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: viewModel,
      builder: (context, _) {
        final s = viewModel.strings;
        final isBn = viewModel.language == AppLanguage.bangla;
        final isFactoryQa = viewModel.scanMode == ScanMode.factoryPackingQa;
        final latest = viewModel.latestResult;

        return Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 760),
            child: Column(
              children: [
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(16, 14, 16, 12),
                    children: [
                      // Hardware Identity & Anti-Clock-Rollback status strip
                      _securityTelemetryStrip(isBn),
                      const SizedBox(height: 12),

                      WebsiteSyncCard(
                        strings: s,
                        serverUrl: viewModel.serverBaseUrl,
                        lastSyncedAt: viewModel.lastTrustSyncAt,
                        trustedKeyCount: viewModel.trustedKeyCount,
                        isSyncing: viewModel.isTrustSyncing,
                        message: viewModel.trustSyncMessage,
                        onUpdate: (url) => viewModel.refreshTrustFromWebsite(
                          overrideBaseUrl: url,
                        ),
                        onUrlChanged: (_) {},
                      ),
                      const SizedBox(height: 12),

                      // Mode Switcher (Consumer Unboxing vs Factory Packing QA)
                      Text(
                        s.modeSectionTitle,
                        style: const TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w800,
                          color: AppTheme.brandNavy,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          Expanded(
                            child: _modeCard(
                              key: const Key('mode_consumer'),
                              title: s.consumerModeTitle,
                              subtitle: s.consumerModeDesc,
                              icon: Icons.shopping_bag_outlined,
                              selected: !isFactoryQa,
                              onTap: () => viewModel.setScanMode(
                                ScanMode.consumerUnboxing,
                              ),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: _modeCard(
                              key: const Key('mode_factory_qa'),
                              title: s.factoryModeTitle,
                              subtitle: s.factoryModeDesc,
                              icon: Icons.factory_outlined,
                              selected: isFactoryQa,
                              onTap: () => viewModel.setScanMode(
                                ScanMode.factoryPackingQa,
                              ),
                            ),
                          ),
                        ],
                      ),

                      if (isFactoryQa) ...[
                        const SizedBox(height: 10),
                        _palletSessionCard(isBn),
                      ],

                      const SizedBox(height: 14),

                      // Latest Verification Result Card (if available)
                      if (latest != null)
                        VerificationResultCard(
                          result: latest,
                          language: viewModel.language,
                          onSyncTrust: () =>
                              viewModel.refreshTrustFromWebsite(),
                          onTransferOwnership: () {
                            TransferOwnershipSheet.show(
                              context,
                              record: latest.record,
                              transferPayloadFuture: viewModel
                                  .generateTransferOwnershipQr(latest.record),
                              language: viewModel.language,
                            );
                          },
                          onScanNext: () => _openScanner(context),
                        )
                      else
                        _readyGuideCard(isBn),
                    ],
                  ),
                ),

                // One-handed lower thumb-zone CTA dock
                Container(
                  padding: const EdgeInsets.fromLTRB(16, 10, 16, 14),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withValues(alpha: 0.06),
                        blurRadius: 10,
                        offset: const Offset(0, -3),
                      ),
                    ],
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      SizedBox(
                        height: 62,
                        child: FilledButton.icon(
                          key: const Key('start_scan_button'),
                          onPressed: () => _openScanner(context),
                          style: FilledButton.styleFrom(
                            backgroundColor: AppTheme.brandNavy,
                            foregroundColor: Colors.white,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(16),
                            ),
                          ),
                          icon: const Icon(
                            Icons.qr_code_scanner_rounded,
                            size: 28,
                          ),
                          label: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                s.startScanCta,
                                style: const TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.w900,
                                ),
                              ),
                              Text(
                                s.startScanSubtitle,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: TextStyle(
                                  fontSize: 11,
                                  color: Colors.white.withValues(alpha: 0.84),
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 8),
                      OutlinedButton.icon(
                        key: const Key('demo_simulator_button'),
                        onPressed: () => _openDemoSimulatorSheet(context),
                        icon: const Icon(Icons.science_outlined, size: 19),
                        label: Text(
                          s.demoSimulatorButton,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _securityTelemetryStrip(bool isBn) {
    final s = viewModel.strings;
    final tokenShort = viewModel.deviceToken.isEmpty
        ? 'KEYSTORE...'
        : viewModel.deviceToken.substring(
            0,
            viewModel.deviceToken.length.clamp(0, 14),
          );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      child: Row(
        children: [
          const Icon(Icons.key_rounded, size: 16, color: AppTheme.brandNavy),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              isBn
                  ? 'ডিভাইস টোকেন: $tokenShort... • ঘড়ি সুরক্ষা সক্রিয়'
                  : 'Device Token: $tokenShort... • Clock Guard Active',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                fontSize: 11.5,
                fontWeight: FontWeight.w700,
                color: Color(0xFF334155),
              ),
            ),
          ),
          Chip(
            key: const Key('trust_sync_chip'),
            visualDensity: VisualDensity.compact,
            avatar: const Icon(Icons.vpn_key_outlined, size: 13),
            label: Text(
              '${s.syncChipPrefix} ${viewModel.trustedKeyCount}',
              style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800),
            ),
          ),
          const Icon(
            Icons.lock_outline_rounded,
            size: 15,
            color: AppTheme.genuineGreen,
          ),
        ],
      ),
    );
  }

  Widget _modeCard({
    required Key key,
    required String title,
    required String subtitle,
    required IconData icon,
    required bool selected,
    required VoidCallback onTap,
  }) {
    return InkWell(
      key: key,
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 160),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: selected ? AppTheme.brandNavy : Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(
            color: selected ? AppTheme.brandNavy : const Color(0xFFD0D7DE),
            width: selected ? 2 : 1,
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  icon,
                  size: 20,
                  color: selected ? Colors.white : AppTheme.brandNavy,
                ),
                const Spacer(),
                if (selected)
                  const Icon(
                    Icons.check_circle_rounded,
                    size: 18,
                    color: Color(0xFF4ADE80),
                  ),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 13.5,
                fontWeight: FontWeight.w800,
                color: selected ? Colors.white : const Color(0xFF0F172A),
              ),
            ),
            const SizedBox(height: 3),
            Text(
              subtitle,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 11,
                height: 1.25,
                color: selected
                    ? Colors.white.withValues(alpha: 0.82)
                    : Colors.grey.shade600,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _palletSessionCard(bool isBn) {
    final s = viewModel.strings;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: AppTheme.repeatOwnerSurface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: AppTheme.repeatOwnerBlue.withValues(alpha: 0.35),
        ),
      ),
      child: Row(
        children: [
          const Icon(
            Icons.inventory_2_outlined,
            color: AppTheme.repeatOwnerBlue,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${s.activeSessionBoxes}: ${viewModel.qaSessionBoxCount}',
                  style: const TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 13,
                    color: AppTheme.brandNavy,
                  ),
                ),
                Text(
                  isBn
                      ? 'একই প্যালেটে ডুপ্লিকেট সিরিয়াল পেলে তৎক্ষণাৎ অফলাইন অ্যালার্ট দেবে'
                      : 'Duplicate serials scanned in this pallet trigger instant offline alerts',
                  style: TextStyle(fontSize: 11, color: Colors.grey.shade700),
                ),
              ],
            ),
          ),
          TextButton(
            onPressed: viewModel.resetQaSession,
            child: Text(s.resetSession),
          ),
        ],
      ),
    );
  }

  Widget _readyGuideCard(bool isBn) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: AppTheme.genuineSurface,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: const Icon(
                    Icons.shield_outlined,
                    color: AppTheme.genuineGreen,
                    size: 26,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    isBn
                        ? '২-ধাপে অফলাইন পণ্য যাচাইকরণ'
                        : '2-Step Offline Cryptographic Verification',
                    style: const TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w900,
                      color: AppTheme.brandNavy,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            _guideStep(
              '1',
              isBn
                  ? 'বাইরের বক্সের সিরিয়াল / বারকোড / বাঁকা লেখা স্ক্যান করুন (ML Kit Vision AI)'
                  : 'Scan outer box barcode or smudged curved printed text (on-device ML Kit OCR).',
            ),
            const SizedBox(height: 8),
            _guideStep(
              '2',
              isBn
                  ? 'সুরক্ষা সিল ও মাইক্রো-ডট অক্ষত কিনা দেখে ভেতরের গোপন QR/DataMatrix স্ক্যান করুন'
                  : 'Confirm tamper-evident seal & micro-dot integrity, then scan inner private tag.',
            ),
            const SizedBox(height: 8),
            _guideStep(
              '3',
              isBn
                  ? 'Ed25519 স্বাক্ষর, মেয়াদ, ও রিকল তালিকা ৫০ মিলিসেকেন্ডের কমে অফলাইনেই যাচাই হয়'
                  : 'Ed25519 signature, expiry policy, and <50KB recall cache verify locally in <50ms.',
            ),
          ],
        ),
      ),
    );
  }

  Widget _guideStep(String num, String text) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        CircleAvatar(
          radius: 11,
          backgroundColor: AppTheme.brandNavy,
          child: Text(
            num,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 11,
              fontWeight: FontWeight.w800,
            ),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            text,
            style: TextStyle(
              fontSize: 13,
              height: 1.35,
              color: Colors.grey.shade800,
            ),
          ),
        ),
      ],
    );
  }

  Future<void> _openScanner(BuildContext context) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => GuidedScannerScreen(
          viewModel: viewModel,
          feedbackService: feedbackService,
        ),
      ),
    );
  }

  Future<void> _openDemoSimulatorSheet(BuildContext context) async {
    final isBn = viewModel.language == AppLanguage.bangla;
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 20),
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  isBn
                      ? 'এয়ারপ্লেন মোড ডেমো সিমুলেটর (রিয়েল Ed25519)'
                      : 'Airplane Mode Demo Simulator (Real Ed25519)',
                  style: const TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w900,
                    color: AppTheme.brandNavy,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  isBn
                      ? 'প্রতিটি টেস্ট আসল ক্রিপ্টোগ্রাফিক সিগনেচার দিয়ে ৫০মি.সে.-এর কমে অফলাইনে যাচাই হয়।'
                      : 'Every test runs real Ed25519 cryptography, SQLite checks & audio/haptics offline.',
                  style: TextStyle(fontSize: 12.5, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 12),
                _simTile(
                  ctx,
                  key: const Key('sim_genuine'),
                  icon: Icons.verified_rounded,
                  color: AppTheme.genuineGreen,
                  title: isBn ? '১. আসল পণ্য (Genuine)' : '1. Genuine Product',
                  subtitle: isBn
                      ? 'সঠিক Ed25519 স্বাক্ষর এবং +৩৬৫ দিন মেয়াদ'
                      : 'Valid Ed25519 signature, +365 days shelf life',
                  onTap: () =>
                      viewModel.runDemoScenario(DemoScenario.genuineProduct),
                ),
                _simTile(
                  ctx,
                  key: const Key('sim_repeat_owner'),
                  icon: Icons.verified_user_rounded,
                  color: AppTheme.repeatOwnerBlue,
                  title: isBn
                      ? '২. পুনরায় স্ক্যান (Repeat Owner)'
                      : '2. Repeat Owner Re-Scan',
                  subtitle: isBn
                      ? 'নিজ ফোনে পূর্বে নিবন্ধিত সিরিয়াল পুনরায় যাচাই'
                      : 'Re-scans previously registered serial on this device',
                  onTap: () => viewModel.runDemoScenario(
                    DemoScenario.genuineProduct,
                    simulateRepeatOwner: true,
                  ),
                ),
                _simTile(
                  ctx,
                  key: const Key('sim_expired'),
                  icon: Icons.event_busy_rounded,
                  color: AppTheme.alertRed,
                  title: isBn
                      ? '৩. মেয়াদোত্তীর্ণ পণ্য (Expired)'
                      : '3. Expired Product',
                  subtitle: isBn
                      ? 'সঠিক স্বাক্ষর কিন্তু ৫ দিন আগে মেয়াদ শেষ'
                      : 'Valid signature, expired 5 days ago',
                  onTap: () =>
                      viewModel.runDemoScenario(DemoScenario.expiredProduct),
                ),
                _simTile(
                  ctx,
                  key: const Key('sim_short_dated'),
                  icon: Icons.hourglass_bottom_rounded,
                  color: AppTheme.warningAmber,
                  title: isBn
                      ? '৪. স্বল্প মেয়াদ (+২০ দিন — Factory QA বাতিল)'
                      : '4. Short-Dated (+20d — Factory QA Reject)',
                  subtitle: isBn
                      ? 'Consumer মোডে পাস, Factory QA (<৪৫ দিন) মোডে বাতিল'
                      : 'Passes Consumer Mode, rejected in Factory QA (<45d)',
                  onTap: () => viewModel.runDemoScenario(
                    DemoScenario.shortDatedFactoryQaFail,
                  ),
                ),
                _simTile(
                  ctx,
                  key: const Key('sim_swapped'),
                  icon: Icons.gpp_bad_rounded,
                  color: AppTheme.alertRed,
                  title: isBn
                      ? '৫. নকল / ট্যাগ বদলানো (Swapped Tag)'
                      : '5. Swapped / Counterfeit Inner Tag',
                  subtitle: isBn
                      ? 'বক্স A সিরিয়াল + বক্স B স্বাক্ষর → Ed25519 অমিল'
                      : 'Box A outer serial + Box B inner signature -> Mismatch',
                  onTap: () => viewModel.runDemoScenario(
                    DemoScenario.swappedTagMismatch,
                  ),
                ),
                _simTile(
                  ctx,
                  key: const Key('sim_duplicate_box'),
                  icon: Icons.content_copy_rounded,
                  color: AppTheme.alertRed,
                  title: isBn
                      ? '৬. ডুপ্লিকেট বক্স (একই প্যালেটে ক্লোন)'
                      : '6. Duplicate Box (Pallet Collision)',
                  subtitle: isBn
                      ? 'একই সেশনে একই সিরিয়াল পুনরায় স্ক্যান হলে সতর্কতা'
                      : 'Triggers immediate Duplicate Box Alert in same session',
                  onTap: () => viewModel.runDemoScenario(
                    DemoScenario.genuineProduct,
                    simulateDuplicateBoxCollision: true,
                  ),
                ),
                _simTile(
                  ctx,
                  key: const Key('sim_recalled'),
                  icon: Icons.block_rounded,
                  color: AppTheme.alertRed,
                  title: isBn
                      ? '৭. রিকলড ব্যাচ (<50KB ক্যাশ ব্ল্যাকলিস্ট)'
                      : '7. Recalled Batch (<50KB Cached Blacklist)',
                  subtitle: isBn
                      ? 'অফলাইন রিকল তালিকা থেকে ব্যাচ B2026RECALL ধরা'
                      : 'Matches B2026RECALL in local cached_blacklist table',
                  onTap: () => viewModel.runDemoScenario(
                    DemoScenario.recalledCachedBatch,
                  ),
                ),
                _simTile(
                  ctx,
                  key: const Key('sim_ocr_repair'),
                  icon: Icons.document_scanner_rounded,
                  color: AppTheme.genuineGreen,
                  title: isBn
                      ? '৮. অস্পষ্ট/বাঁকা OCR লেবেল মেরামত'
                      : '8. Degraded / Curved OCR Label Repair',
                  subtitle: isBn
                      ? 'ভাঙা পাইপ ও O/0 ত্রুটি অন-ডিভাইস AI ঠিক করে যাচাই করে'
                      : 'Normalizes smudged OCR text & passes exact Ed25519',
                  onTap: () => viewModel.runDemoScenario(
                    DemoScenario.degradedCurvedOcrLabel,
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _simTile(
    BuildContext ctx, {
    required Key key,
    required IconData icon,
    required Color color,
    required String title,
    required String subtitle,
    required Future<void> Function() onTap,
  }) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: BorderSide(color: Colors.grey.shade200),
      ),
      child: ListTile(
        key: key,
        leading: CircleAvatar(
          backgroundColor: color.withValues(alpha: 0.14),
          child: Icon(icon, color: color, size: 22),
        ),
        title: Text(
          title,
          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5),
        ),
        subtitle: Text(subtitle, style: const TextStyle(fontSize: 11.5)),
        trailing: const Icon(Icons.play_arrow_rounded),
        onTap: () async {
          Navigator.of(ctx).pop();
          await onTap();
        },
      ),
    );
  }
}
