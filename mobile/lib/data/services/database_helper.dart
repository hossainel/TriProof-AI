import 'dart:convert';

import 'package:path/path.dart' as p;
import 'package:sqflite_sqlcipher/sqflite.dart';
import 'package:triproof_ai_scanner/core/constants/app_constants.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';
import 'package:triproof_ai_scanner/domain/enums/cloud_status.dart';
import 'package:triproof_ai_scanner/domain/models/app_notification.dart';
import 'package:triproof_ai_scanner/domain/models/bloom_filter.dart';
import 'package:triproof_ai_scanner/domain/models/blacklist_entry.dart';
import 'package:triproof_ai_scanner/domain/models/scan_record.dart';
import 'package:triproof_ai_scanner/domain/models/trust_key.dart';

/// Callback signature for custom database opening (used in headless unit tests
/// with `sqflite_common_ffi`).
typedef TestDatabaseOpener =
    Future<Database> Function(
      int version,
      Future<void> Function(Database db, int version) onCreate,
      Future<void> Function(Database db, int oldVersion, int newVersion)
      onUpgrade,
    );

/// Manages the local SQLCipher-encrypted SQLite database with 3 core tables:
/// `scan_history`, `cached_blacklist`, and `notifications_inbox`.
class DatabaseHelper {
  /// Creates a [DatabaseHelper].
  DatabaseHelper({
    required SecureKeystoreService keystoreService,
    TestDatabaseOpener? testDatabaseOpener,
  }) : _keystoreService = keystoreService,
       _testDatabaseOpener = testDatabaseOpener;

  static const String _databaseName = 'triproof_scanner_v1.db';
  static const int _databaseVersion = 2;

  /// Key under which the cloud bloom filter JSON is stored in `app_meta`.
  static const String bloomFilterMetaKey = 'bloom_filter';

  /// Key under which the user-configured TriProof website base URL is stored.
  static const String serverBaseUrlMetaKey = 'server_base_url';

  /// Key under which the synced public key ring JSON is stored.
  static const String syncedKeyRingMetaKey = 'synced_key_ring_json';

  /// Key under which the last successful website trust sync time is stored.
  static const String lastTrustSyncAtMetaKey = 'last_trust_sync_at';

  /// Table storing all offline & cloud-synced verification records.
  static const String tableScanHistory = 'scan_history';

  /// Table storing the <50KB locally cached recalled batch & clone blacklist.
  static const String tableCachedBlacklist = 'cached_blacklist';

  /// Table storing bilingual retroactive alerts and recall notices.
  static const String tableNotificationsInbox = 'notifications_inbox';

  /// Table storing small key/value application metadata (bloom filter, etc.).
  static const String tableAppMeta = 'app_meta';

  final SecureKeystoreService _keystoreService;
  final TestDatabaseOpener? _testDatabaseOpener;

  Database? _database;
  Map<String, BlacklistEntry>? _blacklistCache;
  Set<String>? _sessionSerialCache;
  Map<String, ScanRecord>? _priorOwnerScanCache;
  BloomFilter? _bloomFilterCache;

  /// Returns an open encrypted [Database] instance, initializing schema on
  /// first launch.
  Future<Database> get database async {
    if (_database != null && _database!.isOpen) {
      return _database!;
    }
    _database = await _open();
    await _loadCachesIfNeeded(_database!);
    return _database!;
  }

  Future<void> _loadCachesIfNeeded(Database db) async {
    if (_blacklistCache == null) {
      final rows = await db.query(tableCachedBlacklist);
      _blacklistCache = {
        for (final row in rows)
          (row['serial_or_batch'] as String): BlacklistEntry.fromMap(row),
      };
    }

    _bloomFilterCache ??= await _readBloomFilter(db);

    if (_sessionSerialCache == null || _priorOwnerScanCache == null) {
      final historyRows = await db.query(
        tableScanHistory,
        orderBy: 'scanned_at ASC, id ASC',
      );
      _sessionSerialCache = <String>{};
      _priorOwnerScanCache = <String, ScanRecord>{};
      for (final row in historyRows) {
        final rec = ScanRecord.fromMap(row);
        if (rec.sessionId.isNotEmpty) {
          _sessionSerialCache!.add('${rec.sessionId}::${rec.outerSerial}');
        }
        if (rec.offlineStatus.isGenuinePass && rec.deviceToken.isNotEmpty) {
          final ownerKey = '${rec.deviceToken}::${rec.outerSerial}';
          _priorOwnerScanCache!.putIfAbsent(ownerKey, () => rec);
        }
      }
    }
  }

  Future<Database> _open() async {
    if (_testDatabaseOpener != null) {
      return _testDatabaseOpener(_databaseVersion, _onCreate, _onUpgrade);
    }

    final databasesPath = await getDatabasesPath();
    final dbPath = p.join(databasesPath, _databaseName);
    final password = await _keystoreService.getOrCreateDatabaseKey();

    return openDatabase(
      dbPath,
      password: password,
      version: _databaseVersion,
      onCreate: _onCreate,
      onUpgrade: _onUpgrade,
    );
  }

  Future<void> _onCreate(Database db, int version) async {
    await db.execute('''
      CREATE TABLE $tableScanHistory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sync_uuid TEXT NOT NULL UNIQUE,
        outer_serial TEXT NOT NULL,
        inner_serial TEXT NOT NULL,
        unit_hash TEXT NOT NULL,
        factory_id TEXT NOT NULL,
        machine_id TEXT NOT NULL,
        batch_id TEXT NOT NULL,
        prod_date TEXT NOT NULL,
        exp_date TEXT NOT NULL,
        offline_status TEXT NOT NULL,
        cloud_status TEXT NOT NULL,
        feedback_message_en TEXT NOT NULL,
        feedback_message_bn TEXT NOT NULL,
        gps_lat REAL,
        gps_lng REAL,
        scanned_at TEXT NOT NULL,
        device_token TEXT NOT NULL DEFAULT '',
        scan_mode TEXT NOT NULL DEFAULT 'consumer_unboxing',
        session_id TEXT NOT NULL DEFAULT '',
        latency_ms INTEGER NOT NULL DEFAULT 0,
        scan_seq INTEGER NOT NULL DEFAULT 0
      )
    ''');

    await db.execute(
      'CREATE INDEX idx_scan_outer_serial ON $tableScanHistory(outer_serial)',
    );
    await db.execute(
      'CREATE INDEX idx_scan_cloud_status ON $tableScanHistory(cloud_status)',
    );
    await db.execute(
      'CREATE INDEX idx_scan_scanned_at ON $tableScanHistory(scanned_at DESC)',
    );

    await db.execute('''
      CREATE TABLE $tableCachedBlacklist (
        serial_or_batch TEXT PRIMARY KEY,
        reason TEXT NOT NULL,
        updated_at TEXT NOT NULL
      )
    ''');

    await db.execute('''
      CREATE TABLE $tableNotificationsInbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        outer_serial TEXT NOT NULL,
        severity TEXT NOT NULL,
        title_en TEXT NOT NULL,
        message_en TEXT NOT NULL,
        title_bn TEXT NOT NULL,
        message_bn TEXT NOT NULL,
        is_read INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
      )
    ''');

    await db.execute('''
      CREATE TABLE $tableAppMeta (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
      )
    ''');

    // Seed compact sample recalled batches (<500 bytes) for immediate offline
    // QA & customs verification before first cloud delta sync.
    final nowIso = DateTime.now().toUtc().toIso8601String();
    await db.insert(tableCachedBlacklist, {
      'serial_or_batch': 'B2026RECALL',
      'reason': 'DGDA Precision Recall: Packaging seal lot compromised',
      'updated_at': nowIso,
    }, conflictAlgorithm: ConflictAlgorithm.replace);
    await db.insert(tableCachedBlacklist, {
      'serial_or_batch': 'BD01-M04-B2026CLONE-000999|2026-09-01|2028-09-01',
      'reason': 'Confirmed counterfeit clone serial cluster (Chattogram Port)',
      'updated_at': nowIso,
    }, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  /// Migrates legacy databases to schema v2.
  Future<void> _onUpgrade(Database db, int oldVersion, int newVersion) async {
    if (oldVersion < 2) {
      await db.execute(
        'ALTER TABLE $tableScanHistory '
        'ADD COLUMN scan_seq INTEGER NOT NULL DEFAULT 0',
      );
      await db.execute('''
        CREATE TABLE IF NOT EXISTS $tableAppMeta (
          key TEXT PRIMARY KEY,
          value TEXT NOT NULL
        )
      ''');
    }
  }

  /// Inserts a new [ScanRecord] and returns the persisted row with assigned ID.
  Future<ScanRecord> insertScanRecord(ScanRecord record) async {
    final db = await database;
    final id = await db.insert(
      tableScanHistory,
      record.toMap(),
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
    final persisted = ScanRecord(
      id: id,
      syncUuid: record.syncUuid,
      outerSerial: record.outerSerial,
      innerSerial: record.innerSerial,
      unitHash: record.unitHash,
      factoryId: record.factoryId,
      machineId: record.machineId,
      batchId: record.batchId,
      prodDate: record.prodDate,
      expDate: record.expDate,
      offlineStatus: record.offlineStatus,
      cloudStatus: record.cloudStatus,
      feedbackMessageEn: record.feedbackMessageEn,
      feedbackMessageBn: record.feedbackMessageBn,
      gpsLat: record.gpsLat,
      gpsLng: record.gpsLng,
      scannedAt: record.scannedAt,
      deviceToken: record.deviceToken,
      scanMode: record.scanMode,
      sessionId: record.sessionId,
      latencyMs: record.latencyMs,
      scanSeq: record.scanSeq,
    );

    if (record.sessionId.isNotEmpty) {
      _sessionSerialCache ??= <String>{};
      _sessionSerialCache!.add('${record.sessionId}::${record.outerSerial}');
    }
    if (record.offlineStatus.isGenuinePass && record.deviceToken.isNotEmpty) {
      _priorOwnerScanCache ??= <String, ScanRecord>{};
      _priorOwnerScanCache!.putIfAbsent(
        '${record.deviceToken}::${record.outerSerial}',
        () => persisted,
      );
    }

    return persisted;
  }

  /// Finds the most recent prior genuine scan on this [deviceToken] for
  /// [outerSerial], used for repeat-owner verification.
  Future<ScanRecord?> findPriorOwnerScan({
    required String outerSerial,
    required String deviceToken,
  }) async {
    if (_priorOwnerScanCache == null) {
      await database;
    }
    return _priorOwnerScanCache!['$deviceToken::$outerSerial'];
  }

  /// Returns whether [outerSerial] was already scanned within [sessionId],
  /// indicating a pallet/shelf duplicate box collision.
  Future<bool> hasSessionScan({
    required String outerSerial,
    required String sessionId,
  }) async {
    if (sessionId.isEmpty) return false;
    if (_sessionSerialCache == null) {
      await database;
    }
    return _sessionSerialCache!.contains('$sessionId::$outerSerial');
  }

  /// Fetches all scan history records, newest first, optionally filtered by
  /// [query].
  Future<List<ScanRecord>> getScanHistory({String query = ''}) async {
    final db = await database;
    final trimmed = query.trim();
    final List<Map<String, Object?>> rows;
    if (trimmed.isEmpty) {
      rows = await db.query(
        tableScanHistory,
        orderBy: 'scanned_at DESC, id DESC',
      );
    } else {
      final like = '%$trimmed%';
      rows = await db.query(
        tableScanHistory,
        where:
            'outer_serial LIKE ? OR batch_id LIKE ? OR factory_id LIKE ? OR offline_status LIKE ? OR cloud_status LIKE ?',
        whereArgs: [like, like, like, like, like],
        orderBy: 'scanned_at DESC, id DESC',
      );
    }
    return rows.map(ScanRecord.fromMap).toList();
  }

  /// Fetches all records waiting to be cross-checked with the cloud.
  Future<List<ScanRecord>> getPendingSyncRecords() async {
    final db = await database;
    final rows = await db.query(
      tableScanHistory,
      where: 'cloud_status = ?',
      whereArgs: [CloudStatus.pendingSync.dbValue],
      orderBy: 'scanned_at ASC',
    );
    return rows.map(ScanRecord.fromMap).toList();
  }

  /// Returns count of `pending_sync` rows.
  Future<int> countPendingSync() async {
    final db = await database;
    final res = await db.rawQuery(
      'SELECT COUNT(*) AS cnt FROM $tableScanHistory WHERE cloud_status = ?',
      [CloudStatus.pendingSync.dbValue],
    );
    return (res.first['cnt'] as int?) ?? 0;
  }

  /// Deletes the scan history row identified by [syncUuid]. Returns the number
  /// of rows removed and invalidates in-memory session/owner caches so they
  /// rebuild on next access.
  Future<int> deleteScanRecord(String syncUuid) async {
    final db = await database;
    final deleted = await db.delete(
      tableScanHistory,
      where: 'sync_uuid = ?',
      whereArgs: [syncUuid],
    );
    _sessionSerialCache = null;
    _priorOwnerScanCache = null;
    return deleted;
  }

  /// Deletes every row from `scan_history`. Returns the number of rows removed
  /// and invalidates in-memory session/owner caches so they rebuild on next
  /// access.
  Future<int> clearScanHistory() async {
    final db = await database;
    final deleted = await db.delete(tableScanHistory);
    _sessionSerialCache = null;
    _priorOwnerScanCache = null;
    return deleted;
  }

  /// Updates the `cloud_status` and bilingual feedback messages for [syncUuid].
  Future<void> updateCloudVerdict({
    required String syncUuid,
    required CloudStatus cloudStatus,
    String? feedbackMessageEn,
    String? feedbackMessageBn,
  }) async {
    final db = await database;
    final values = <String, Object?>{'cloud_status': cloudStatus.dbValue};
    if (feedbackMessageEn != null) {
      values['feedback_message_en'] = feedbackMessageEn;
    }
    if (feedbackMessageBn != null) {
      values['feedback_message_bn'] = feedbackMessageBn;
    }
    await db.update(
      tableScanHistory,
      values,
      where: 'sync_uuid = ?',
      whereArgs: [syncUuid],
    );
  }

  /// Finds a scan record by [outerSerial] (most recent).
  Future<ScanRecord?> findLatestByOuterSerial(String outerSerial) async {
    final db = await database;
    final rows = await db.query(
      tableScanHistory,
      where: 'outer_serial = ?',
      whereArgs: [outerSerial],
      orderBy: 'scanned_at DESC, id DESC',
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return ScanRecord.fromMap(rows.first);
  }

  /// Checks `cached_blacklist` (backed by an O(1) <50KB RAM mirror) for a
  /// matching [outerSerial], [unitHash], or [batchId] in under 1 millisecond.
  ///
  /// A valid persisted bloom filter is used as a negative short-circuit only:
  /// a definite miss skips the exact lookup, while a possible hit still runs
  /// the exact `cached_blacklist` check (no `possible_match` verdict is issued).
  Future<BlacklistEntry?> findBlacklistMatch({
    required String outerSerial,
    required String batchId,
    String? unitHash,
  }) async {
    if (_blacklistCache == null) {
      await database;
    }

    final candidates = <String>[
      if (outerSerial.isNotEmpty) outerSerial,
      if (batchId.isNotEmpty) batchId,
      if (unitHash != null && unitHash.isNotEmpty) unitHash,
    ];
    final bloom = _bloomFilterCache;
    if (bloom != null && bloom.isValid && candidates.isNotEmpty) {
      var possibleMatch = false;
      for (final candidate in candidates) {
        if (bloom.mightContain(candidate)) {
          possibleMatch = true;
          break;
        }
      }
      if (!possibleMatch) return null;
    }

    final cache = _blacklistCache!;
    if (cache.containsKey(outerSerial)) return cache[outerSerial];
    if (cache.containsKey(batchId)) return cache[batchId];
    if (unitHash != null &&
        unitHash.isNotEmpty &&
        cache.containsKey(unitHash)) {
      return cache[unitHash];
    }
    return null;
  }

  /// Persists a cloud `bloom_filter` map into `app_meta` for offline negatives.
  Future<void> saveBloomFilter(Map<String, dynamic> bloom) async {
    final filter = BloomFilter.fromMap(bloom);
    if (!filter.isValid) return;
    final db = await database;
    await db.insert(tableAppMeta, {
      'key': bloomFilterMetaKey,
      'value': filter.toJson(),
    }, conflictAlgorithm: ConflictAlgorithm.replace);
    _bloomFilterCache = filter;
  }

  /// Loads the persisted bloom filter, or `null` when none has been synced.
  Future<BloomFilter?> loadBloomFilter() async {
    if (_bloomFilterCache != null) return _bloomFilterCache;
    final db = await database;
    return _bloomFilterCache ?? await _readBloomFilter(db);
  }

  Future<BloomFilter?> _readBloomFilter(Database db) async {
    final rows = await db.query(
      tableAppMeta,
      where: 'key = ?',
      whereArgs: [bloomFilterMetaKey],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    final raw = rows.first['value'] as String?;
    if (raw == null || raw.isEmpty) return null;
    final filter = BloomFilter.fromJson(raw);
    return filter.isValid ? filter : null;
  }

  /// Replaces or upserts blacklist entries while enforcing the strict `<50KB`
  /// storage budget ([AppConstants.maxBlacklistPayloadBytes]).
  Future<void> upsertBlacklistEntries(
    List<BlacklistEntry> entries, {
    bool replaceAll = false,
  }) async {
    final serializedBytes = utf8
        .encode(jsonEncode(entries.map((e) => e.toMap()).toList()))
        .length;

    if (serializedBytes > AppConstants.maxBlacklistPayloadBytes) {
      throw StateError(
        'Blacklist payload ($serializedBytes bytes) exceeds strict 50KB '
        '(${AppConstants.maxBlacklistPayloadBytes} bytes) offline budget.',
      );
    }

    final db = await database;
    await db.transaction((txn) async {
      if (replaceAll) {
        await txn.delete(tableCachedBlacklist);
      }
      for (final entry in entries) {
        await txn.insert(
          tableCachedBlacklist,
          entry.toMap(),
          conflictAlgorithm: ConflictAlgorithm.replace,
        );
      }
    });

    if (replaceAll) {
      _blacklistCache = {for (final e in entries) e.serialOrBatch: e};
    } else {
      _blacklistCache ??= <String, BlacklistEntry>{};
      for (final e in entries) {
        _blacklistCache![e.serialOrBatch] = e;
      }
    }
  }

  /// Returns current cached blacklist entries.
  Future<List<BlacklistEntry>> getBlacklistEntries() async {
    final db = await database;
    final rows = await db.query(
      tableCachedBlacklist,
      orderBy: 'updated_at DESC',
    );
    return rows.map(BlacklistEntry.fromMap).toList();
  }

  /// Inserts a notification into `notifications_inbox`.
  Future<AppNotification> insertNotification(
    AppNotification notification,
  ) async {
    final db = await database;
    final id = await db.insert(
      tableNotificationsInbox,
      notification.toMap(),
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
    return AppNotification(
      id: id,
      outerSerial: notification.outerSerial,
      severity: notification.severity,
      titleEn: notification.titleEn,
      messageEn: notification.messageEn,
      titleBn: notification.titleBn,
      messageBn: notification.messageBn,
      isRead: notification.isRead,
      createdAt: notification.createdAt,
    );
  }

  /// Fetches all notifications sorted newest first.
  Future<List<AppNotification>> getNotifications() async {
    final db = await database;
    final rows = await db.query(
      tableNotificationsInbox,
      orderBy: 'created_at DESC, id DESC',
    );
    return rows.map(AppNotification.fromMap).toList();
  }

  /// Counts unread alerts in `notifications_inbox`.
  Future<int> countUnreadNotifications() async {
    final db = await database;
    final res = await db.rawQuery(
      'SELECT COUNT(*) AS cnt FROM $tableNotificationsInbox WHERE is_read = 0',
    );
    return (res.first['cnt'] as int?) ?? 0;
  }

  /// Marks a single notification [id] as read.
  Future<void> markNotificationRead(int id) async {
    final db = await database;
    await db.update(
      tableNotificationsInbox,
      {'is_read': 1},
      where: 'id = ?',
      whereArgs: [id],
    );
  }

  /// Marks all notifications as read.
  Future<void> markAllNotificationsRead() async {
    final db = await database;
    await db.update(tableNotificationsInbox, {'is_read': 1});
  }

  /// Reads a raw `app_meta` value, or `null` when absent.
  Future<String?> getMeta(String key) async {
    final db = await database;
    final rows = await db.query(
      tableAppMeta,
      where: 'key = ?',
      whereArgs: [key],
      limit: 1,
    );
    if (rows.isEmpty) return null;
    return rows.first['value'] as String?;
  }

  /// Writes a raw `app_meta` value.
  Future<void> setMeta(String key, String value) async {
    final db = await database;
    await db.insert(tableAppMeta, {
      'key': key,
      'value': value,
    }, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  /// Returns the configured server URL or the compile-time default.
  Future<String> getServerBaseUrl() async {
    final stored = await getMeta(serverBaseUrlMetaKey);
    return (stored != null && stored.trim().isNotEmpty)
        ? stored
        : AppConstants.apiBaseUrl;
  }

  /// Persists the user-configured server URL.
  Future<void> setServerBaseUrl(String url) =>
      setMeta(serverBaseUrlMetaKey, url.trim());

  /// Returns the synced public key ring from encrypted `app_meta`.
  Future<List<TrustKey>> readSyncedKeyRing() async {
    final raw = await getMeta(syncedKeyRingMetaKey);
    if (raw == null || raw.trim().isEmpty) return const <TrustKey>[];
    try {
      final decoded = jsonDecode(raw) as List<dynamic>;
      return decoded
          .whereType<Map<String, dynamic>>()
          .map(TrustKey.fromMap)
          .toList();
    } on FormatException {
      return const <TrustKey>[];
    }
  }

  /// Persists the synced public key ring into encrypted `app_meta`.
  Future<void> saveSyncedKeyRing(List<TrustKey> keys) => setMeta(
    syncedKeyRingMetaKey,
    jsonEncode(keys.map((k) => k.toMap()).toList()),
  );

  /// Returns the last successful website trust sync timestamp.
  Future<DateTime?> getLastTrustSyncAt() async {
    final raw = await getMeta(lastTrustSyncAtMetaKey);
    if (raw == null || raw.isEmpty) return null;
    return DateTime.tryParse(raw)?.toUtc();
  }

  /// Persists the last successful website trust sync timestamp.
  Future<void> setLastTrustSyncAt(DateTime value) =>
      setMeta(lastTrustSyncAtMetaKey, value.toUtc().toIso8601String());

  /// Closes the open SQLite database connection.
  Future<void> close() async {
    if (_database != null && _database!.isOpen) {
      await _database!.close();
    }
    _database = null;
    _blacklistCache = null;
    _sessionSerialCache = null;
    _priorOwnerScanCache = null;
    _bloomFilterCache = null;
  }
}
