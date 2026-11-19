import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/models/app_notification.dart';
import 'package:triproof_ai_scanner/domain/models/blacklist_entry.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';

/// Verdict returned by the cloud cross-check API for one uploaded scan.
class CrossCheckVerdictItem {
  /// Creates a [CrossCheckVerdictItem].
  const CrossCheckVerdictItem({
    required this.syncUuid,
    required this.verdict,
    this.titleEn,
    this.messageEn,
    this.titleBn,
    this.messageBn,
  });

  /// Matching scan UUID.
  final String syncUuid;

  /// Server decision (`verified_clean`, `clone_detected`, or `recalled_batch`).
  final String verdict;

  /// Optional English alert title.
  final String? titleEn;

  /// Optional English alert detail.
  final String? messageEn;

  /// Optional Bangla alert title.
  final String? titleBn;

  /// Optional Bangla alert detail.
  final String? messageBn;

  /// Maps raw server string [verdict] to local [CloudStatus].
  CloudStatus toCloudStatus() {
    switch (verdict) {
      case 'clone_detected':
        return CloudStatus.retroactiveCloneAlert;
      case 'recalled_batch':
        return CloudStatus.retroactiveRecallAlert;
      case 'verified_clean':
      default:
        return CloudStatus.verifiedClean;
    }
  }

  /// Parses JSON map from API response.
  factory CrossCheckVerdictItem.fromJson(Map<String, dynamic> json) {
    return CrossCheckVerdictItem(
      syncUuid: json['sync_uuid'] as String,
      verdict: (json['verdict'] as String?) ?? 'verified_clean',
      titleEn: json['title_en'] as String?,
      messageEn: json['message_en'] as String?,
      titleBn: json['title_bn'] as String?,
      messageBn: json['message_bn'] as String?,
    );
  }
}

/// Response bundle for `POST /api/v1/verify/online-crosscheck`.
class CrossCheckBatchResponse {
  /// Creates a [CrossCheckBatchResponse].
  const CrossCheckBatchResponse({
    required this.verdicts,
    required this.serverTimeUtc,
  });

  /// Individual verdicts keyed by `sync_uuid`.
  final List<CrossCheckVerdictItem> verdicts;

  /// Authoritative UTC server timestamp used to update the clock High-Water Mark.
  final DateTime serverTimeUtc;
}

/// Response bundle for `GET /api/v1/notifications/{device_token}` and
/// `GET /api/v1/blacklist/delta`.
class CloudFeedResponse {
  /// Creates a [CloudFeedResponse].
  const CloudFeedResponse({
    required this.notifications,
    required this.blacklistEntries,
    required this.serverTimeUtc,
    this.bloomFilter,
  });

  /// Server notifications addressed to this device.
  final List<AppNotification> notifications;

  /// Latest <50KB recalled batches / cloned serials delta.
  final List<BlacklistEntry> blacklistEntries;

  /// Authoritative UTC server timestamp.
  final DateTime serverTimeUtc;

  /// Optional `{bits_b64, m_bits, k_hashes, seed}` bloom filter describing the
  /// full (possibly truncated) blacklist set. A miss is a definitive negative;
  /// a hit still requires the exact `cached_blacklist` check.
  final Map<String, dynamic>? bloomFilter;
}

/// Interface for online cross-check and notification synchronization.
abstract interface class CloudApiClient {
  /// Uploads pending offline scans to `POST /api/v1/verify/online-crosscheck`.
  Future<CrossCheckBatchResponse> postOnlineCrossCheck({
    required String deviceToken,
    required List<ScanRecord> records,
  });

  /// Fetches notifications (`GET /api/v1/notifications/{device_token}`) and
  /// the compressed <50KB blacklist delta.
  Future<CloudFeedResponse> fetchNotificationsAndBlacklist({
    required String deviceToken,
  });

  /// Fetches the public versioned Ed25519 verification key ring from
  /// `GET /.well-known/triproof-key.json` (no JWT required).
  ///
  /// Returns a `keyId -> base64 public key` map. Legacy responses exposing a
  /// single `public_key_base64` are mapped to `K00`.
  Future<Map<String, String>> fetchPublicKeyRing();
}

/// Production HTTP REST client for TriProof Cloud APIs.
class HttpCloudApiClient implements CloudApiClient {
  /// Creates an [HttpCloudApiClient].
  HttpCloudApiClient({
    http.Client? httpClient,
    this.baseUrl = AppConstants.apiBaseUrl,
  }) : _client = httpClient ?? http.Client();

  final http.Client _client;

  /// API base URL.
  final String baseUrl;

  @override
  Future<CrossCheckBatchResponse> postOnlineCrossCheck({
    required String deviceToken,
    required List<ScanRecord> records,
  }) async {
    final uri = Uri.parse('$baseUrl${AppConstants.onlineCrossCheckPath}');
    final response = await _client
        .post(
          uri,
          headers: {
            HttpHeaders.contentTypeHeader: 'application/json; charset=UTF-8',
            HttpHeaders.acceptHeader: 'application/json',
            'X-TriProof-Device-Token': deviceToken,
          },
          body: jsonEncode({
            'device_token': deviceToken,
            // `records` is the canonical key; `logs` is accepted as an alias by
            // the cloud API. Both carry the same scan_seq-bearing payload.
            'records': records.map((r) => r.toCrossCheckJson()).toList(),
            'logs': records.map((r) => r.toCrossCheckJson()).toList(),
          }),
        )
        .timeout(const Duration(seconds: 8));

    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw HttpException(
        'Online cross-check failed (${response.statusCode})',
        uri: uri,
      );
    }

    final body = jsonDecode(response.body) as Map<String, dynamic>;
    final rawResultList = (body['results'] as List<dynamic>?) ??
        (body['records'] as List<dynamic>?) ??
        (body['logs'] as List<dynamic>?) ??
        const <dynamic>[];
    final rawResults = rawResultList.cast<Map<String, dynamic>>();

    final serverTime = _extractServerTime(body, response.headers);

    return CrossCheckBatchResponse(
      verdicts: rawResults.map(CrossCheckVerdictItem.fromJson).toList(),
      serverTimeUtc: serverTime,
    );
  }

  @override
  Future<CloudFeedResponse> fetchNotificationsAndBlacklist({
    required String deviceToken,
  }) async {
    final notifUri = Uri.parse(
      '$baseUrl${AppConstants.notificationsPathPrefix}/${Uri.encodeComponent(deviceToken)}',
    );
    final blacklistUri =
        Uri.parse('$baseUrl${AppConstants.blacklistDeltaPath}');

    final notifResponse = await _client.get(
      notifUri,
      headers: {HttpHeaders.acceptHeader: 'application/json'},
    ).timeout(const Duration(seconds: 8));

    if (notifResponse.statusCode < 200 || notifResponse.statusCode >= 300) {
      throw HttpException(
        'Notification fetch failed (${notifResponse.statusCode})',
        uri: notifUri,
      );
    }

    final notifBody = jsonDecode(notifResponse.body) as Map<String, dynamic>;
    final rawNotifs =
        (notifBody['notifications'] as List<dynamic>? ?? const <dynamic>[])
            .cast<Map<String, dynamic>>();

    final notifications = rawNotifs.map((n) {
      return AppNotification(
        outerSerial: (n['outer_serial'] as String?) ?? '',
        severity: (n['severity'] as String?) ?? 'high',
        titleEn: (n['title_en'] as String?) ?? 'Security Alert',
        messageEn: (n['message_en'] as String?) ?? '',
        titleBn: (n['title_bn'] as String?) ?? 'নিরাপত্তা সতর্কতা',
        messageBn: (n['message_bn'] as String?) ?? '',
        isRead: false,
        createdAt: DateTime.tryParse(
              (n['created_at'] as String?) ?? '',
            )?.toUtc() ??
            DateTime.now().toUtc(),
      );
    }).toList();

    final blacklistResponse = await _client.get(
      blacklistUri,
      headers: {HttpHeaders.acceptHeader: 'application/json'},
    ).timeout(const Duration(seconds: 8));

    final blacklistEntries = <BlacklistEntry>[];
    Map<String, dynamic>? bloomFilter;
    DateTime? blacklistServerTime;
    if (blacklistResponse.statusCode >= 200 &&
        blacklistResponse.statusCode < 300) {
      // Guard strict <50KB network payload budget before parsing.
      if (blacklistResponse.bodyBytes.length <=
          AppConstants.maxBlacklistPayloadBytes) {
        final blBody =
            jsonDecode(blacklistResponse.body) as Map<String, dynamic>;
        final rawEntries =
            (blBody['entries'] as List<dynamic>? ?? const <dynamic>[])
                .cast<Map<String, dynamic>>();
        for (final entry in rawEntries) {
          blacklistEntries.add(BlacklistEntry.fromMap(entry));
        }

        final rawBloom = blBody['bloom_filter'];
        if (rawBloom is Map) {
          bloomFilter = <String, dynamic>{
            for (final entry in rawBloom.entries)
              entry.key.toString(): entry.value,
          };
        }
        blacklistServerTime = DateTime.tryParse(
          (blBody['server_time'] as String?) ?? '',
        )?.toUtc();
      }
    }

    final serverTime = blacklistServerTime ??
        _extractServerTime(notifBody, notifResponse.headers);

    return CloudFeedResponse(
      notifications: notifications,
      blacklistEntries: blacklistEntries,
      serverTimeUtc: serverTime,
      bloomFilter: bloomFilter,
    );
  }

  @override
  Future<Map<String, String>> fetchPublicKeyRing() async {
    final uri = Uri.parse('$baseUrl${AppConstants.keyRingPath}');
    final response = await _client.get(
      uri,
      headers: {HttpHeaders.acceptHeader: 'application/json'},
    ).timeout(const Duration(seconds: 8));

    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw HttpException(
        'Public key ring fetch failed (${response.statusCode})',
        uri: uri,
      );
    }

    final body = jsonDecode(response.body) as Map<String, dynamic>;
    final ring = <String, String>{};

    final rawRing = body['key_ring'];
    if (rawRing is List) {
      for (final item in rawRing) {
        if (item is! Map) continue;
        final version = (item['key_version'] ?? item['key_id'])?.toString();
        final encoded = item['public_key_base64']?.toString();
        if (version == null || encoded == null || encoded.isEmpty) continue;
        ring[version.trim().toUpperCase()] = encoded.trim();
      }
    }

    final legacy = body['public_key_base64']?.toString();
    if (legacy != null && legacy.isNotEmpty) {
      ring.putIfAbsent('K00', () => legacy.trim());
    }

    return ring;
  }

  DateTime _extractServerTime(
    Map<String, dynamic> body,
    Map<String, String> headers,
  ) {
    final jsonTime = body['server_time'] as String?;
    if (jsonTime != null) {
      final parsed = DateTime.tryParse(jsonTime);
      if (parsed != null) return parsed.toUtc();
    }

    final dateHeader = headers['date'];
    if (dateHeader != null) {
      try {
        return HttpDate.parse(dateHeader).toUtc();
      } catch (_) {
        // Fallback below.
      }
    }
    return DateTime.now().toUtc();
  }
}
