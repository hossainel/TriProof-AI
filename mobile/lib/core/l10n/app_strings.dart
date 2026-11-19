import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/enums/offline_status.dart';
import 'package:triproof_ai_scanner/domain/enums/scan_mode.dart';

/// Bilingual (English & বাংলা) string provider for TriProof AI Scanner.
class AppStrings {
  /// Creates an [AppStrings] bundle for [language].
  const AppStrings(this.language);

  /// Active language.
  final AppLanguage language;

  /// Whether active language is Bangla.
  bool get isBn => language == AppLanguage.bangla;

  /// Selects English or Bangla string based on active [language].
  String pick(String en, String bn) => isBn ? bn : en;

  String get appTitle =>
      pick('TriProof AI Scanner', 'ট্রাইপ্রুফ এআই স্ক্যানার');

  String get appSubtitle => pick(
        'Offline Cryptographic Supply-Chain Verifier',
        'অফলাইন ক্রিপ্টোগ্রাফিক পণ্য যাচাইকরণ',
      );

  String get offlineReadyPillPrefix =>
      pick('OFFLINE READY', 'অফলাইন প্রস্তুত');

  String get onlineSyncedPill => pick('ONLINE SYNCED', 'অনলাইন সিঙ্কড');

  String get syncingPill => pick('SYNCING...', 'সিঙ্ক হচ্ছে...');

  String get pendingSyncPillPrefix => pick('PENDING SYNC', 'সিঙ্ক বাকি');

  // Navigation Tabs
  String get tabScanner => pick('Scanner', 'স্ক্যানার');
  String get tabHistory => pick('History', 'ইতিহাস');
  String get tabAlerts => pick('Alerts', 'সতর্কতা');

  // Home Screen
  String get modeSectionTitle =>
      pick('Verification Mode', 'যাচাইকরণ মোড');
  String get consumerModeTitle =>
      pick('Consumer Unboxing', 'ভোক্তা আনবক্সিং');
  String get consumerModeDesc => pick(
        'Full expiry check & device owner lock',
        'পূর্ণ মেয়াদ যাচাই ও ডিভাইস মালিকানা নিবন্ধন',
      );
  String get factoryModeTitle =>
      pick('Factory Packing QA', 'ফ্যাক্টরি প্যাকিং কিউএ');
  String get factoryModeDesc => pick(
        'Strict 45-day shelf life & pallet duplicate guard',
        'কঠোর ৪৫ দিনের মেয়াদ ও প্যালেট ডুপ্লিকেট সুরক্ষা',
      );

  String get startScanCta => pick(
        'SCAN PRODUCT (2-STEP)',
        'পণ্য স্ক্যান করুন (২-ধাপ)',
      );
  String get startScanSubtitle => pick(
        'Zero internet • Ed25519 <50ms • On-Device Vision AI',
        'ইন্টারনেট ছাড়াই • Ed25519 <৫০মি.সে. • অন-ডিভাইস ভিশন এআই',
      );

  String get demoSimulatorButton => pick(
        'Quick-Test Demo Simulator (Airplane Mode)',
        'কুইক-টেস্ট ডেমো সিমুলেটর (এয়ারপ্লেন মোড)',
      );

  String get activeSessionBoxes =>
      pick('Active Pallet Session Scans', 'চলমান প্যালেট সেশন স্ক্যান');
  String get resetSession => pick('New Pallet', 'নতুন প্যালেট');

  // Scanner Screen
  String get step1Title =>
      pick('Step 1 of 2: Outer Box Public Serial', 'ধাপ ১/২: বাইরের বক্সের সিরিয়াল');
  String get step1Instruction => pick(
        'Point camera at the outer barcode/QR or printed curved serial label. ML Kit OCR repairs smudged ink offline.',
        'বক্সের বাইরের বারকোড/কিউআর বা বাঁকা ছাপা সিরিয়ালের দিকে ক্যামেরা ধরুন। এমএল কিট ওসিআর অফলাইনেই অস্পষ্ট লেখা পড়ে নেবে।',
      );
  String get step2Title =>
      pick('Step 2 of 2: Inner Private Seal Tag', 'ধাপ ২/২: ভেতরের গোপন সিল ট্যাগ');
  String get step2Instruction => pick(
        'Align the inner QR or DataMatrix signature code exposed after breaking the tamper-evident seal.',
        'ট্যাম্পার-এভিডেন্ট সিল খোলার পর ভেতরের কিউআর বা ডেটা-ম্যাট্রিক্স কোডটি স্ক্যান করুন।',
      );

  String get manualEntryButton =>
      pick('Manual / Paste Serial', 'ম্যানুয়াল সিরিয়াল লিখুন');

  // Physical Seal Inspection Dialog
  String get sealPromptTitle => pick(
        'Physical Seal & Micro-Dot Inspection',
        'ভৌত সিল ও মাইক্রো-ডট পরীক্ষা',
      );
  String get sealPromptBody => pick(
        'Before scanning Step 2, physically inspect the inner scratch-off tamper seal and micro-dot hologram. Confirm it was completely intact before you broke it.',
        'ধাপ ২ স্ক্যান করার আগে ভেতরের স্ক্র্যাচ-অফ ট্যাম্পার সিল এবং মাইক্রো-ডট হলোগ্রামটি ভালোভাবে পরীক্ষা করুন। আপনি খোলার আগে সিলটি সম্পূর্ণ অক্ষত ছিল তা নিশ্চিত করুন।',
      );
  String get sealIntactProceed => pick(
        'Seal Was Intact — Scan Inner Tag',
        'সিল অক্ষত ছিল — ভেতরের ট্যাগ স্ক্যান করুন',
      );
  String get sealTamperedFlag => pick(
        'Seal Looks Broken / Scratched',
        'সিল ভাঙা বা ঘষা মনে হচ্ছে',
      );

  // Result Card Fields
  String get factoryNameLabel => pick('Factory', 'কারখানা');
  String get machineIdLabel => pick('Machine ID', 'মেশিন আইডি');
  String get batchIdLabel => pick('Batch ID', 'ব্যাচ আইডি');
  String get unitNumberLabel => pick('Unit #', 'ইউনিট নং');
  String get prodDateLabel => pick('Prod Date', 'উৎপাদন তারিখ');
  String get expDateLabel => pick('Expiry Date', 'মেয়াদ উত্তীর্ণ');
  String get latencyLabel => pick('Crypto Latency', 'ক্রিপ্টো সময়');
  String get transferOwnershipQr =>
      pick('Transfer Ownership QR', 'মালিকানা হস্তান্তর QR');
  String get scanNextItem => pick('Scan Next Product', 'পরবর্তী পণ্য স্ক্যান করুন');

  // Mode display label
  String modeLabel(ScanMode mode) => mode == ScanMode.consumerUnboxing
      ? consumerModeTitle
      : factoryModeTitle;

  // Offline status badge labels
  String offlineBadgeLabel(OfflineStatus status) {
    switch (status) {
      case OfflineStatus.genuineOffline:
        return pick('VERIFIED GENUINE', 'যাচাইকৃত আসল');
      case OfflineStatus.repeatOwner:
        return pick('REPEAT OWNER GENUINE', 'নিজ ডিভাইসে নিবন্ধিত আসল');
      case OfflineStatus.fakeSignature:
        return pick('FAKE / TAG MISMATCH', 'নকল / ট্যাগ অমিল');
      case OfflineStatus.expired:
        return pick('EXPIRED / SHORT-DATED', 'মেয়াদোত্তীর্ণ / স্বল্প মেয়াদ');
      case OfflineStatus.duplicateBox:
        return pick('DUPLICATE BOX ALERT', 'ডুপ্লিকেট বক্স সতর্কতা');
      case OfflineStatus.recalledCached:
        return pick('RECALLED BATCH (CACHED)', 'প্রত্যাহৃত ব্যাচ (ক্যাশড)');
      case OfflineStatus.clockRollback:
        return pick('CLOCK TAMPER DETECTED', 'ঘড়ি পরিবর্তনের সন্দেহ');
      case OfflineStatus.masterCartonGenuine:
        return pick('MASTER CARTON VERIFIED', 'মাস্টার কার্টন যাচাইকৃত');
      case OfflineStatus.masterCartonMismatch:
        return pick('MASTER CARTON MISMATCH', 'মাস্টার কার্টন অমিল');
    }
  }

  // Cloud status badge labels
  String cloudBadgeLabel(CloudStatus status) {
    switch (status) {
      case CloudStatus.pendingSync:
        return pick('Pending Sync', 'সিঙ্ক অপেক্ষমাণ');
      case CloudStatus.verifiedClean:
        return pick('Cloud Verified', 'ক্লাউড যাচাইকৃত');
      case CloudStatus.retroactiveCloneAlert:
        return pick('Clone Alert!', 'ক্লোন সতর্কতা!');
      case CloudStatus.retroactiveRecallAlert:
        return pick('Recall Alert!', 'রিকল সতর্কতা!');
    }
  }

  // History & Alerts Screens
  String get searchHistoryHint => pick(
        'Search serial, batch ID, or factory...',
        'সিরিয়াল, ব্যাচ বা কারখানা খুঁজুন...',
      );
  String get noHistoryTitle => pick('No Scans Recorded Yet', 'এখনো কোনো স্ক্যান হয়নি');
  String get noHistorySubtitle => pick(
        'Scan a product or run the offline demo simulator to see cryptographic audit logs here.',
        'পণ্য স্ক্যান করুন অথবা অফলাইন ডেমো সিমুলেটর চালিয়ে ক্রিপ্টোগ্রাফিক অডিট লগ দেখুন।',
      );

  String get alertsCenterTitle =>
      pick('Alerts & Feedback Center', 'সতর্কতা ও ফিডব্যাক কেন্দ্র');
  String get noAlertsTitle => pick('All Clear — No Active Alerts', 'সব ঠিক আছে — কোনো সতর্কতা নেই');
  String get noAlertsSubtitle => pick(
        'Retroactive clone detections and precision batch recalls from cloud cross-checks appear here.',
        'অনলাইন ক্রস-চেক থেকে আসা ক্লোন শনাক্তকরণ এবং ব্যাচ রিকল বিজ্ঞপ্তি এখানে দেখা যাবে।',
      );
  String get syncNowButton => pick('Sync & Cross-Check Now', 'এখনই সিঙ্ক ও ক্রস-চেক করুন');
  String get simulateCloudRecallButton => pick(
        'Demo: Simulate Retroactive Clone/Recall Sync',
        'ডেমো: রেট্রোঅ্যাক্টিভ ক্লোন/রিকল সিঙ্ক টেস্ট',
      );
  String get markAllRead => pick('Mark All Read', 'সব পঠিত চিহ্নিত করুন');
}
