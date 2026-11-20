import 'package:intl/intl.dart';
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/core/utils/ocr_serial_normalizer.dart';
import 'package:triproof_ai_scanner/core/utils/smart_payload_parser.dart';
import 'package:triproof_ai_scanner/data/services/crypto_service.dart';
import 'package:triproof_ai_scanner/data/services/database_helper.dart';
import 'package:triproof_ai_scanner/data/services/feedback_service.dart';
import 'package:triproof_ai_scanner/data/services/location_time_service.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/enums/offline_status.dart';
import 'package:triproof_ai_scanner/domain/enums/scan_mode.dart';
import 'package:triproof_ai_scanner/domain/models/parsed_outer_serial.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';
import 'package:triproof_ai_scanner/domain/models/verification_result.dart';
import 'package:uuid/uuid.dart';

/// Clock function returning current UTC [DateTime] (injectable for tests).
typedef UtcClock = DateTime Function();

/// Orchestrates the 100% offline (<50ms) cryptographic & policy verification
/// pipeline (`verifyOffline`) and persists audit logs to encrypted SQLite.
class VerificationRepository {
  /// Creates a [VerificationRepository].
  VerificationRepository({
    required SecureKeystoreService keystoreService,
    required CryptoService cryptoService,
    required DatabaseHelper databaseHelper,
    required FeedbackService feedbackService,
    required LocationTimeService locationTimeService,
    UtcClock? clock,
    Uuid? uuid,
  }) : _keystoreService = keystoreService,
       _cryptoService = cryptoService,
       _databaseHelper = databaseHelper,
       _feedbackService = feedbackService,
       _locationTimeService = locationTimeService,
       _clock = clock ?? (() => DateTime.now().toUtc()),
       _uuid = uuid ?? const Uuid();

  final SecureKeystoreService _keystoreService;
  final CryptoService _cryptoService;
  final DatabaseHelper _databaseHelper;
  final FeedbackService _feedbackService;
  final LocationTimeService _locationTimeService;
  final UtcClock _clock;
  final Uuid _uuid;

  /// Executes the complete 5-stage offline verification pipeline in <50ms:
  /// 0. Anti-clock-rollback High-Water Mark verification.
  /// 1. Outer serial parsing (`{FactoryID}-{MachineID}-{BatchID}-{UnitNumber}|{ProdDate}|{ExpDate}`).
  /// 2. Ed25519 cryptographic verification (`verify(outerSerial, Base64Decode(innerSerial), universalPublicKey)`).
  /// 3. Two-stage expiry verification (strict 45-day check in Factory QA Mode, full ExpDate in Consumer Mode).
  /// 4. Local <50KB `cached_blacklist` lookup.
  /// 5. Local `scan_history` check for repeat device ownership vs active-session duplicate box collision.
  Future<VerificationResult> verifyOffline({
    required String outerSerial,
    required String innerSerial,
    required ScanMode mode,
    String sessionId = '',
    bool triggerFeedback = true,
    List<int>? overridePublicKeyBytes,
  }) async {
    final totalStopwatch = Stopwatch()..start();
    final nowUtc = _clock().toUtc();
    final deviceToken = await _keystoreService.getOrCreateDeviceToken();
    final coords = _locationTimeService.getCachedCoordinates();

    // Normalize inner signature whitespace and optional URI/prefix wrapper.
    final normalizedInner = OcrSerialNormalizer.normalizeInnerSignature(
      innerSerial,
    );

    // ------------------------------------------------------------------
    // EARLY BRANCH: Sealed master carton (CTN-...|root|count|signature).
    // Verified entirely offline against the versioned public key ring.
    // ------------------------------------------------------------------
    final carton = SmartPayloadParser.tryParseMasterCarton(outerSerial);
    if (carton != null) {
      totalStopwatch.stop();
      final cartonUnitHash = await _cryptoService.computeUnitHash(
        carton.toPayloadString(),
        normalizedInner,
      );
      return _verifyMasterCarton(
        payload: carton,
        innerSerial: normalizedInner,
        unitHash: cartonUnitHash,
        nowUtc: nowUtc,
        deviceToken: deviceToken,
        mode: mode,
        sessionId: sessionId,
        coords: coords,
        elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
        triggerFeedback: triggerFeedback,
      );
    }

    // Attempt clean parse first, falling back to OCR structural repair. Smart URL
    // wrappers are stripped here so the persisted serial and unit hash are the
    // exact canonical strings that were signed by HQ and verified by the cloud.
    final smartParsed = ParsedOuterSerial.tryParse(outerSerial);
    final cleanOuter =
        smartParsed?.rawSerial ??
        (OcrSerialNormalizer.extractAndNormalize(outerSerial) ??
            outerSerial.trim());
    final parsed = smartParsed ?? ParsedOuterSerial.tryParse(cleanOuter);

    final unitHash = await _cryptoService.computeUnitHash(
      cleanOuter,
      normalizedInner,
    );

    // ------------------------------------------------------------------
    // STAGE 0: Anti-Clock-Rollback Protection (High-Water Mark)
    // ------------------------------------------------------------------
    final clockRolledBack = await _keystoreService.isClockRolledBack(nowUtc);
    if (clockRolledBack) {
      final hwm = await _keystoreService.getHighWaterMark();
      final hwmStr = hwm != null
          ? DateFormat('yyyy-MM-dd HH:mm').format(hwm.toLocal())
          : 'Unknown';
      totalStopwatch.stop();
      return _finalizeAndPersist(
        parsed: parsed,
        rawOuter: cleanOuter,
        innerSerial: normalizedInner,
        unitHash: unitHash,
        nowUtc: nowUtc,
        deviceToken: deviceToken,
        mode: mode,
        sessionId: sessionId,
        coords: coords,
        elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
        offlineStatus: OfflineStatus.clockRollback,
        headlineEn: 'CLOCK ROLLBACK DETECTED',
        headlineBn: 'সিস্টেম ঘড়ি পরিবর্তনের সতর্কতা',
        messageEn:
            'Verification blocked: Device time is earlier than last verified timestamp ($hwmStr). Restore automatic network/GPS time to prevent expiry bypass.',
        messageBn:
            'যাচাইকরণ বাতিল: ফোনের সময় সর্বশেষ যাচাইকৃত সময়ের ($hwmStr) চেয়ে পেছনে রয়েছে। সঠিক সময় সেট করে আবার চেষ্টা করুন।',
        triggerFeedback: triggerFeedback,
      );
    }

    // ------------------------------------------------------------------
    // STAGE 1: Parse Outer Serial Structure
    // ------------------------------------------------------------------
    if (parsed == null) {
      totalStopwatch.stop();
      return _finalizeAndPersist(
        parsed: null,
        rawOuter: cleanOuter,
        innerSerial: normalizedInner,
        unitHash: unitHash,
        nowUtc: nowUtc,
        deviceToken: deviceToken,
        mode: mode,
        sessionId: sessionId,
        coords: coords,
        elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
        offlineStatus: OfflineStatus.fakeSignature,
        headlineEn: 'INVALID / CORRUPTED OUTER SERIAL',
        headlineBn: 'ত্রুটিপূর্ণ বা অকার্যকর সিরিয়াল',
        messageEn:
            'Outer label does not match required TriProof format ({Factory}-{Machine}-{Batch}-{Unit}|{Prod}|{Exp}). Inspect packaging for tampering.',
        messageBn:
            'সতর্কতা: বক্স এবং ভেতরের ট্যাগের সিরিয়াল মিলছে না — পণ্যটি নকল হতে পারে। বাইরের সিরিয়াল ফরম্যাটটি সঠিক নয়।',
        triggerFeedback: triggerFeedback,
      );
    }

    // ------------------------------------------------------------------
    // STAGE 2: Ed25519 Offline Signature Verification
    // ------------------------------------------------------------------
    final sigOutcome = await _cryptoService.verifyEd25519(
      outerSerial: parsed.rawSerial,
      innerSerialBase64: normalizedInner,
      keyId: parsed.keyId,
      expectedDppDigest: parsed.dppDigest,
      overridePublicKeyBytes: overridePublicKeyBytes,
    );

    if (!sigOutcome.isValid) {
      totalStopwatch.stop();
      final dppMismatch = sigOutcome.dppMismatch;
      return _finalizeAndPersist(
        parsed: parsed,
        rawOuter: parsed.rawSerial,
        innerSerial: normalizedInner,
        unitHash: unitHash,
        nowUtc: nowUtc,
        deviceToken: deviceToken,
        mode: mode,
        sessionId: sessionId,
        coords: coords,
        elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
        offlineStatus: OfflineStatus.fakeSignature,
        headlineEn: dppMismatch
            ? 'DPP PROVENANCE DIGEST MISMATCH'
            : 'COUNTERFEIT / TAG MISMATCH',
        headlineBn: dppMismatch
            ? 'ডিপিপি প্রমাণ ডাইজেস্ট অমিল'
            : 'সতর্কতা: নকল বা ট্যাগ অমিল!',
        messageEn: dppMismatch
            ? 'Embedded DPP digest ${parsed.dppDigest} does not match the signed label. Do NOT consume or accept this unit.'
            : 'Cryptographic Ed25519 signature mismatch between outer box and inner private seal. Do NOT consume or accept this unit.',
        messageBn: dppMismatch
            ? 'সতর্কতা: লেবেলের ডিপিপি ডাইজেস্ট (${parsed.dppDigest}) স্বাক্ষরিত লেবেলের সাথে মিলছে না — পণ্যটি গ্রহণ করবেন না।'
            : 'সতর্কতা: বক্স এবং ভেতরের ট্যাগের সিরিয়াল মিলছে না — পণ্যটি নকল হতে পারে।',
        triggerFeedback: triggerFeedback,
      );
    }

    // ------------------------------------------------------------------
    // STAGE 3: Two-Stage Expiry Verification
    // ------------------------------------------------------------------
    final isPastAbsoluteExpiry = nowUtc.isAfter(parsed.expDateUtc);
    final remainingDays = parsed.expDateUtc.difference(nowUtc).inDays;
    final failsFactoryQaThreshold =
        mode == ScanMode.factoryPackingQa &&
        !isPastAbsoluteExpiry &&
        nowUtc
            .add(const Duration(days: AppConstants.factoryQaMinShelfLifeDays))
            .isAfter(parsed.expDateUtc);

    if (isPastAbsoluteExpiry || failsFactoryQaThreshold) {
      totalStopwatch.stop();
      if (failsFactoryQaThreshold) {
        return _finalizeAndPersist(
          parsed: parsed,
          rawOuter: parsed.rawSerial,
          innerSerial: normalizedInner,
          unitHash: unitHash,
          nowUtc: nowUtc,
          deviceToken: deviceToken,
          mode: mode,
          sessionId: sessionId,
          coords: coords,
          elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
          offlineStatus: OfflineStatus.expired,
          nearExpiryFactoryQa: true,
          headlineEn: 'FACTORY QA REJECT: <45 DAYS SHELF LIFE',
          headlineBn: 'ফ্যাক্টরি কিউএ বাতিল: মেয়াদ ৪৫ দিনের কম',
          messageEn:
              'Rejected by strict Factory Packing QA policy: Only $remainingDays days remaining until ${parsed.expDateIso} (minimum ${AppConstants.factoryQaMinShelfLifeDays} days required).',
          messageBn:
              'ফ্যাক্টরি প্যাকিং কিউএ নীতিমালা অনুযায়ী বাতিল: ${parsed.expDateIso} মেয়াদ শেষ হতে মাত্র $remainingDays দিন বাকি (ন্যূনতম ৪৫ দিন প্রয়োজন)।',
          triggerFeedback: triggerFeedback,
        );
      }

      return _finalizeAndPersist(
        parsed: parsed,
        rawOuter: parsed.rawSerial,
        innerSerial: normalizedInner,
        unitHash: unitHash,
        nowUtc: nowUtc,
        deviceToken: deviceToken,
        mode: mode,
        sessionId: sessionId,
        coords: coords,
        elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
        offlineStatus: OfflineStatus.expired,
        headlineEn: 'EXPIRED PRODUCT',
        headlineBn: 'মেয়াদোত্তীর্ণ পণ্য!',
        messageEn:
            'This unit expired on ${parsed.expDateIso}. Do not distribute or consume.',
        messageBn:
            'সতর্কতা: এই পণ্যটির মেয়াদ ${parsed.expDateIso} তারিখে শেষ হয়ে গেছে — এটি ব্যবহার বা বিক্রি করবেন না।',
        triggerFeedback: triggerFeedback,
      );
    }

    // ------------------------------------------------------------------
    // STAGE 4: Local <50KB Cached Blacklist Check
    // ------------------------------------------------------------------
    final blacklistMatch = await _databaseHelper.findBlacklistMatch(
      outerSerial: parsed.rawSerial,
      batchId: parsed.batchId,
      unitHash: unitHash,
    );
    if (blacklistMatch != null) {
      totalStopwatch.stop();
      return _finalizeAndPersist(
        parsed: parsed,
        rawOuter: parsed.rawSerial,
        innerSerial: normalizedInner,
        unitHash: unitHash,
        nowUtc: nowUtc,
        deviceToken: deviceToken,
        mode: mode,
        sessionId: sessionId,
        coords: coords,
        elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
        offlineStatus: OfflineStatus.recalledCached,
        blacklistReason: blacklistMatch.reason,
        headlineEn: 'RECALLED / BLACKLISTED BATCH',
        headlineBn: 'প্রত্যাহৃত (রিকলড) ব্যাচ সতর্কতা!',
        messageEn:
            'Matched offline <50KB recall registry (${blacklistMatch.serialOrBatch}): ${blacklistMatch.reason}. Quarantine immediately.',
        messageBn:
            'সতর্কতা: এই ব্যাচটি (${blacklistMatch.serialOrBatch}) অফলাইন রিকল তালিকায় রয়েছে (${blacklistMatch.reason}) — অবিলম্বে পণ্যটি আলাদা করে রাখুন।',
        triggerFeedback: triggerFeedback,
      );
    }

    // ------------------------------------------------------------------
    // STAGE 5: Local `scan_history` Duplicate Box vs Repeat Owner Check
    // ------------------------------------------------------------------
    final isSessionCollision = await _databaseHelper.hasSessionScan(
      outerSerial: parsed.rawSerial,
      sessionId: sessionId,
    );
    if (isSessionCollision) {
      totalStopwatch.stop();
      return _finalizeAndPersist(
        parsed: parsed,
        rawOuter: parsed.rawSerial,
        innerSerial: normalizedInner,
        unitHash: unitHash,
        nowUtc: nowUtc,
        deviceToken: deviceToken,
        mode: mode,
        sessionId: sessionId,
        coords: coords,
        elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
        offlineStatus: OfflineStatus.duplicateBox,
        headlineEn: 'DUPLICATE BOX ALERT (PALLET COLLISION)',
        headlineBn: 'ডুপ্লিকেট বক্স সতর্কতা (একই সেশনে পুনরাবৃত্তি)',
        messageEn:
            'Alert: Serial ${parsed.rawSerial} was already scanned on another carton in this active pallet/shelf session. Possible cloned batch!',
        messageBn:
            'সতর্কতা: একই প্যাকিং সেশনে আরেকটি কার্টনে এই সিরিয়ালটি (${parsed.rawSerial}) পাওয়া গেছে — এটি একটি ক্লোন বা ডুপ্লিকেট কার্টন হতে পারে।',
        triggerFeedback: triggerFeedback,
      );
    }

    final priorOwnerScan = await _databaseHelper.findPriorOwnerScan(
      outerSerial: parsed.rawSerial,
      deviceToken: deviceToken,
    );

    totalStopwatch.stop();

    // Advance monotonic high-water mark after timing the verification pipeline.
    // The compile-time build epoch is a hard floor for replay protection.
    await _keystoreService.updateHighWaterMarkIfNewer(
      DateTime.fromMillisecondsSinceEpoch(
        AppConstants.buildEpochMs,
        isUtc: true,
      ),
    );
    await _keystoreService.updateHighWaterMarkIfNewer(nowUtc);

    if (priorOwnerScan != null) {
      final dateFormatted = DateFormat(
        'yyyy-MM-dd HH:mm',
      ).format(priorOwnerScan.scannedAt.toLocal());
      return _finalizeAndPersist(
        parsed: parsed,
        rawOuter: parsed.rawSerial,
        innerSerial: normalizedInner,
        unitHash: unitHash,
        nowUtc: nowUtc,
        deviceToken: deviceToken,
        mode: mode,
        sessionId: sessionId,
        coords: coords,
        elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
        offlineStatus: OfflineStatus.repeatOwner,
        priorRegisteredDate: priorOwnerScan.scannedAt,
        headlineEn: 'Verified Genuine — Repeat Owner',
        headlineBn: 'যাচাইকৃত আসল — নিজ ডিভাইসে নিবন্ধিত',
        messageEn:
            'Verified Genuine — Previously registered to your device on $dateFormatted.',
        messageBn:
            'যাচাইকৃত আসল — পণ্যটি পূর্বে $dateFormatted তারিখে আপনার এই ডিভাইসে নিবন্ধিত হয়েছিল।',
        triggerFeedback: triggerFeedback,
      );
    }

    return _finalizeAndPersist(
      parsed: parsed,
      rawOuter: parsed.rawSerial,
      innerSerial: normalizedInner,
      unitHash: unitHash,
      nowUtc: nowUtc,
      deviceToken: deviceToken,
      mode: mode,
      sessionId: sessionId,
      coords: coords,
      elapsedMicroseconds: totalStopwatch.elapsedMicroseconds,
      offlineStatus: OfflineStatus.genuineOffline,
      headlineEn: 'VERIFIED GENUINE (OFFLINE)',
      headlineBn: 'যাচাইকৃত আসল পণ্য (অফলাইন)',
      messageEn:
          'Ed25519 cryptographic seal matches outer box label. Product is genuine and within validity.',
      messageBn:
          'বাইরের বক্স ও ভেতরের গোপন ক্রিপ্টোগ্রাফিক ট্যাগ শতভাগ মিলেছে — পণ্যটি সম্পূর্ণ আসল এবং মেয়াদোত্তীর্ণ নয়।',
      triggerFeedback: triggerFeedback,
    );
  }

  Future<VerificationResult> _verifyMasterCarton({
    required MasterCartonPayload payload,
    required String innerSerial,
    required String unitHash,
    required DateTime nowUtc,
    required String deviceToken,
    required ScanMode mode,
    required String sessionId,
    required ({double? lat, double? lng}) coords,
    required int elapsedMicroseconds,
    required bool triggerFeedback,
  }) async {
    final keyRing = await _keystoreService.getPublicKeyRing();
    final outcome = await _cryptoService.verifyMasterCarton(payload, keyRing);
    final genuine = outcome.isValid;

    await _keystoreService.updateHighWaterMarkIfNewer(
      DateTime.fromMillisecondsSinceEpoch(
        AppConstants.buildEpochMs,
        isUtc: true,
      ),
    );
    await _keystoreService.updateHighWaterMarkIfNewer(nowUtc);

    final serialParts = payload.cartonSerial.split('-');
    final factoryId = serialParts.length > 2 ? serialParts[2] : 'UNKNOWN';
    final batchId = serialParts.length > 3
        ? serialParts.sublist(3, serialParts.length - 1).join('-')
        : 'UNKNOWN';

    return _finalizeAndPersist(
      parsed: null,
      rawOuter: payload.toPayloadString(),
      innerSerial: innerSerial,
      unitHash: unitHash,
      nowUtc: nowUtc,
      deviceToken: deviceToken,
      mode: mode,
      sessionId: sessionId,
      coords: coords,
      elapsedMicroseconds: elapsedMicroseconds,
      offlineStatus: genuine
          ? OfflineStatus.masterCartonGenuine
          : OfflineStatus.masterCartonMismatch,
      headlineEn: genuine
          ? 'MASTER CARTON GENUINE (OFFLINE)'
          : 'MASTER CARTON SIGNATURE MISMATCH',
      headlineBn: genuine
          ? 'মাস্টার কার্টন যাচাইকৃত আসল (অফলাইন)'
          : 'মাস্টার কার্টন স্বাক্ষর অমিল',
      messageEn: genuine
          ? 'Sealed master carton ${payload.cartonSerial} signature and Merkle root over ${payload.unitCount} units verified offline.'
          : 'Master carton ${payload.cartonSerial} signature did not verify against the trusted key ring. Do not accept the sealed shipment.',
      messageBn: genuine
          ? 'সিল করা মাস্টার কার্টন ${payload.cartonSerial} এর স্বাক্ষর ও ${payload.unitCount} ইউনিটের মার্কেল রুট অফলাইনে যাচাইকৃত।'
          : 'মাস্টার কার্টন ${payload.cartonSerial} এর স্বাক্ষর বিশ্বস্ত কী রিংয়ের সাথে মিলছে না — সিল করা চালান গ্রহণ করবেন না।',
      triggerFeedback: triggerFeedback,
      factoryIdOverride: factoryId,
      batchIdOverride: batchId,
    );
  }

  Future<VerificationResult> _finalizeAndPersist({
    required ParsedOuterSerial? parsed,
    required String rawOuter,
    required String innerSerial,
    required String unitHash,
    required DateTime nowUtc,
    required String deviceToken,
    required ScanMode mode,
    required String sessionId,
    required ({double? lat, double? lng}) coords,
    required int elapsedMicroseconds,
    required OfflineStatus offlineStatus,
    required String headlineEn,
    required String headlineBn,
    required String messageEn,
    required String messageBn,
    required bool triggerFeedback,
    DateTime? priorRegisteredDate,
    bool nearExpiryFactoryQa = false,
    String? blacklistReason,
    String? factoryIdOverride,
    String? batchIdOverride,
  }) async {
    final latencyMs = (elapsedMicroseconds / 1000)
        .ceil()
        .clamp(1, 999999)
        .toInt();
    final scanSeq = await _keystoreService.nextScanSeq();

    final record = ScanRecord(
      syncUuid: _uuid.v4(),
      outerSerial: rawOuter,
      innerSerial: innerSerial,
      unitHash: unitHash,
      factoryId: factoryIdOverride ?? parsed?.factoryId ?? 'UNKNOWN',
      machineId: parsed?.machineId ?? 'UNKNOWN',
      batchId: batchIdOverride ?? parsed?.batchId ?? 'UNKNOWN',
      prodDate: parsed?.prodDateIso ?? 'UNKNOWN',
      expDate: parsed?.expDateIso ?? 'UNKNOWN',
      offlineStatus: offlineStatus,
      cloudStatus: CloudStatus.pendingSync,
      feedbackMessageEn: messageEn,
      feedbackMessageBn: messageBn,
      gpsLat: coords.lat,
      gpsLng: coords.lng,
      scannedAt: nowUtc,
      deviceToken: deviceToken,
      scanMode: mode,
      sessionId: sessionId,
      latencyMs: latencyMs,
      scanSeq: scanSeq,
    );

    final persisted = await _databaseHelper.insertScanRecord(record);

    if (triggerFeedback) {
      if (offlineStatus.isGenuinePass) {
        await _feedbackService.playGenuineFeedback();
      } else {
        await _feedbackService.playAlertFeedback();
      }
    }

    return VerificationResult(
      record: persisted,
      parsedSerial: parsed,
      offlineStatus: offlineStatus,
      cloudStatus: CloudStatus.pendingSync,
      verificationMicroseconds: elapsedMicroseconds,
      scanMode: mode,
      headlineEn: headlineEn,
      headlineBn: headlineBn,
      messageEn: messageEn,
      messageBn: messageBn,
      priorRegisteredDate: priorRegisteredDate,
      nearExpiryFactoryQa: nearExpiryFactoryQa,
      blacklistReason: blacklistReason,
    );
  }
}
