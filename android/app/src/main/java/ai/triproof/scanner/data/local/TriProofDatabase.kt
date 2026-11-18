package ai.triproof.scanner.data.local

import androidx.room.*
import kotlinx.coroutines.flow.Flow

@Entity(
    tableName = "scan_history",
    indices =
        [
            Index(value = ["syncUuid"], unique = true),
            Index(value = ["outerSerial", "deviceToken"]),
            Index(value = ["cloudStatus", "id"]),
            Index(value = ["packingSession", "outerSerial"]),
            Index(value = ["scannedAt"]),
        ],
)
data class ScanHistoryEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val syncUuid: String,
    val outerSerial: String,
    val innerSerial: String,
    val unitHash: String,
    val factoryId: String?,
    val machineId: String?,
    val batchId: String?,
    val prodDate: String?,
    val expDate: String?,
    val offlineStatus: String,
    val cloudStatus: String = "pending_sync",
    val feedbackMessageEn: String? = null,
    val feedbackMessageBn: String? = null,
    val gpsLat: Double?,
    val gpsLng: Double?,
    val scannedAt: Long,
    val deviceToken: String,
    val packingSession: String?,
    val previousVerifiedAt: Long? = null,
    val bannerDismissed: Boolean = false,
    val transferTicket: String? = null,
)

@Entity(tableName = "cached_blacklist")
data class CachedBlacklistEntity(
    @PrimaryKey val serialOrBatch: String,
    val reason: String,
    val updatedAt: Long,
)

@Entity(tableName = "notifications_inbox")
data class NotificationEntity(
    @PrimaryKey val id: Long,
    val outerSerial: String?,
    val severity: String,
    val titleEn: String,
    val messageEn: String,
    val titleBn: String,
    val messageBn: String,
    val isRead: Boolean,
    val createdAt: Long,
    val pendingRead: Boolean = false,
    val bannerDismissed: Boolean = false,
)

data class NewlyFlagged(val outerSerial: String, val recalled: Boolean)

data class LocalEvidence(
    val blocked: Boolean,
    val duplicateBox: Boolean,
    val previousVerifiedAt: Long?,
)

@Dao
interface HistoryDao {
    @Query(
        """SELECT h.outerSerial, MAX(b.reason='recalled_batch') AS recalled FROM scan_history h JOIN cached_blacklist b
        ON b.serialOrBatch=h.outerSerial OR b.serialOrBatch=(h.factoryId || '-' || h.machineId || '-' || h.batchId)
        WHERE h.offlineStatus IN ('genuine_offline','owner_repeat_ok') AND (h.cloudStatus IN ('verified_clean','owner_repeat_ok','store_grace_ok')
        OR (h.cloudStatus='retroactive_clone_alert' AND b.reason='recalled_batch')) GROUP BY h.outerSerial"""
    )
    suspend fun newlyFlagged(): List<NewlyFlagged>

    @Insert suspend fun insert(row: ScanHistoryEntity): Long

    @Query("SELECT * FROM scan_history WHERE id=:id") suspend fun get(id: Long): ScanHistoryEntity?

    @Query("SELECT * FROM scan_history WHERE id=:id")
    fun observe(id: Long): Flow<ScanHistoryEntity?>

    @Query(
        "SELECT scannedAt FROM scan_history WHERE offlineStatus != 'clock_tampered' ORDER BY scannedAt DESC LIMIT 1"
    )
    suspend fun highWater(): Long?

    @Query(
        """SELECT EXISTS(SELECT 1 FROM cached_blacklist WHERE serialOrBatch IN (:outer,:batch)) AS blocked,
        EXISTS(SELECT 1 FROM scan_history WHERE packingSession=:session AND outerSerial=:outer
            AND offlineStatus IN ('genuine_offline','owner_repeat_ok','duplicate_box')) AS duplicateBox,
        (SELECT MIN(scannedAt) FROM scan_history WHERE outerSerial=:outer AND deviceToken=:device AND
            offlineStatus IN ('genuine_offline','owner_repeat_ok') AND cloudStatus NOT IN
            ('retroactive_clone_alert','retroactive_recall_alert','forged_signature','expired','sync_rejected')) AS previousVerifiedAt"""
    )
    suspend fun evidence(
        outer: String,
        batch: String,
        session: String?,
        device: String,
    ): LocalEvidence

    @Query("SELECT * FROM scan_history WHERE cloudStatus='pending_sync' ORDER BY id LIMIT :limit")
    suspend fun pending(limit: Int): List<ScanHistoryEntity>

    @Query("SELECT COUNT(*) FROM scan_history WHERE cloudStatus='pending_sync'")
    fun pendingCount(): Flow<Int>

    @Query(
        "SELECT * FROM scan_history WHERE instr(upper(outerSerial),upper(:search)) > 0 AND (:filter='all' OR (:filter='pending' AND cloudStatus='pending_sync') OR (:filter='verified' AND offlineStatus IN ('genuine_offline','owner_repeat_ok') AND cloudStatus IN ('verified_clean','owner_repeat_ok','store_grace_ok','pending_sync')) OR (:filter='alerts' AND (offlineStatus NOT IN ('genuine_offline','owner_repeat_ok') OR cloudStatus IN ('retroactive_clone_alert','retroactive_recall_alert','expired','forged_signature','clone_detected','recalled_batch','sync_rejected')))) ORDER BY id DESC LIMIT :limit"
    )
    fun search(search: String, filter: String, limit: Int): Flow<List<ScanHistoryEntity>>

    @Query(
        "UPDATE scan_history SET cloudStatus=:status, feedbackMessageEn=:en, feedbackMessageBn=:bn, transferTicket=NULL WHERE syncUuid=:uuid AND cloudStatus='pending_sync'"
    )
    suspend fun verdict(uuid: String, status: String, en: String?, bn: String?)

    @Query(
        "UPDATE scan_history SET cloudStatus=:status, feedbackMessageEn=:en, feedbackMessageBn=:bn, bannerDismissed=0 WHERE outerSerial=:outer AND offlineStatus IN ('genuine_offline','owner_repeat_ok') AND cloudStatus NOT IN ('pending_sync',:status,'retroactive_recall_alert')"
    )
    suspend fun retroactive(outer: String, status: String, en: String, bn: String)

    @Query(
        "SELECT * FROM scan_history WHERE bannerDismissed=0 AND cloudStatus IN ('retroactive_clone_alert','retroactive_recall_alert') ORDER BY id DESC LIMIT 1"
    )
    fun banner(): Flow<ScanHistoryEntity?>

    @Query(
        "UPDATE scan_history SET bannerDismissed=1 WHERE outerSerial=:outer AND cloudStatus=:status"
    )
    suspend fun dismiss(outer: String, status: String)
}

@Dao
interface BlacklistDao {
    @Query(
        "SELECT EXISTS(SELECT 1 FROM cached_blacklist WHERE serialOrBatch=:outer OR serialOrBatch=:batch)"
    )
    suspend fun blocked(outer: String, batch: String): Boolean

    @Query("DELETE FROM cached_blacklist") suspend fun clear()

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun put(items: List<CachedBlacklistEntity>)
}

@Dao
interface InboxDao {
    @Query(
        "SELECT * FROM notifications_inbox WHERE bannerDismissed=0 AND isRead=0 AND severity IN ('critical_clone','recall_alert') ORDER BY id DESC LIMIT 1"
    )
    fun banner(): Flow<NotificationEntity?>

    @Query("UPDATE notifications_inbox SET bannerDismissed=1 WHERE id=:id")
    suspend fun dismiss(id: Long)

    @Query("SELECT * FROM notifications_inbox ORDER BY id DESC")
    fun all(): Flow<List<NotificationEntity>>

    @Query("SELECT COUNT(*) FROM notifications_inbox WHERE isRead=0") fun unread(): Flow<Int>

    @Query("SELECT * FROM notifications_inbox WHERE id=:id")
    suspend fun get(id: Long): NotificationEntity?

    @Upsert suspend fun put(item: NotificationEntity)

    @Query("UPDATE notifications_inbox SET isRead=1,pendingRead=1 WHERE isRead=0")
    suspend fun markAll()

    @Query("SELECT id FROM notifications_inbox WHERE pendingRead=1 LIMIT 500")
    suspend fun unsentReads(): List<Long>

    @Query("UPDATE notifications_inbox SET pendingRead=0 WHERE id IN (:ids)")
    suspend fun acknowledge(ids: List<Long>)
}

@Database(
    entities = [ScanHistoryEntity::class, CachedBlacklistEntity::class, NotificationEntity::class],
    version = 1,
    exportSchema = true,
)
abstract class TriProofDatabase : RoomDatabase() {
    abstract fun history(): HistoryDao

    abstract fun blacklist(): BlacklistDao

    abstract fun inbox(): InboxDao
}
