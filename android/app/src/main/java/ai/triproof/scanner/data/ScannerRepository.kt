package ai.triproof.scanner.data

import ai.triproof.scanner.crypto.KeystoreManager
import ai.triproof.scanner.crypto.Verdict
import ai.triproof.scanner.data.local.*
import ai.triproof.scanner.data.remote.*
import androidx.room.withTransaction
import java.io.IOException
import java.time.Instant
import java.time.OffsetDateTime
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import retrofit2.HttpException

data class Alert(
    val id: Long,
    val enTitle: String,
    val en: String,
    val bnTitle: String,
    val bn: String,
)

class ScannerRepository(
    val db: TriProofDatabase,
    val keys: KeystoreManager,
    private val api: TriProofApi,
    private val notify: (Alert) -> Unit,
    private val enqueue: () -> Unit,
) {
    private val syncLock = Mutex()

    private suspend fun store(pack: BlacklistPackage) {
        val snapshot = BlacklistCodec.decode(pack)
        val newlyFlagged =
            db.withTransaction {
                // Trimming for the wire cap must not erase a previously received recall.
                if (!snapshot.partial) db.blacklist().clear()
                db.blacklist().put(snapshot.entries)
                val matches = db.history().newlyFlagged()
                for (match in matches) {
                    if (match.recalled) {
                        db.history()
                            .retroactive(
                                match.outerSerial,
                                "retroactive_recall_alert",
                                "This previously scanned batch was recalled. Stop use or dispatch.",
                                "আগে স্ক্যান করা এই ব্যাচ রিকল হয়েছে। ব্যবহার বা সরবরাহ বন্ধ রাখুন।",
                            )
                    } else {
                        db.history()
                            .retroactive(
                                match.outerSerial,
                                "retroactive_clone_alert",
                                "This previously scanned serial was flagged as a clone. Isolate it.",
                                "আগে স্ক্যান করা সিরিয়ালটিতে ক্লোন ধরা পড়েছে। পণ্যটি আলাদা রাখুন।",
                            )
                    }
                }
                matches.isNotEmpty()
            }
        if (newlyFlagged) {
            notify(
                Alert(
                    0,
                    "Updated product alert",
                    "A previously verified label is now on the warning list. Open scan history.",
                    "পণ্যের নতুন সতর্কতা",
                    "আগে যাচাই করা লেবেল এখন সতর্কতা তালিকায় আছে। স্ক্যানের ইতিহাস দেখুন।",
                )
            )
        }
    }

    private fun checkKey(meta: KeyMeta) {
        if (
            meta.algorithm != "Ed25519" ||
                !runCatching { keys.matchesPin(meta.key, meta.fingerprint) }.getOrDefault(false)
        )
            throw java.security.GeneralSecurityException("key_mismatch")
    }

    suspend fun login(email: String, password: String) {
        checkKey(api.key().data)
        val result = api.login(Credentials(email.trim(), password)).data
        checkKey(result.verification)
        store(result.blacklist)
        keys.login(result.token)
        keys.advance(System.currentTimeMillis())
        enqueue()
    }

    suspend fun markRead() {
        db.inbox().markAll()
        enqueue()
    }

    suspend fun handoff(row: ScanHistoryEntity): String {
        val token = keys.token.value ?: error("login_required")
        return api.handoff(
                "Bearer $token",
                HandoffRequest(keys.deviceToken, row.outerSerial, row.innerSerial),
            )
            .data
            .ticket
    }

    suspend fun sync(token: String) =
        syncLock.withLock {
            val auth = "Bearer $token"
            while (true) {
                val rows = db.history().pending(100)
                if (rows.isEmpty()) break
                try {
                    submit(auth, rows)
                } catch (e: HttpException) {
                    if (e.code() != 422) throw e
                    // A single invalid/redeemed handoff must not strand every subsequent scan.
                    for (row in rows) try {
                        submit(auth, listOf(row))
                    } catch (invalid: HttpException) {
                        if (invalid.code() != 422) throw invalid
                        db.history()
                            .verdict(
                                row.syncUuid,
                                "sync_rejected",
                                "Server rejected this record. Inspect the label or handoff ticket.",
                                "সার্ভার এই রেকর্ড গ্রহণ করেনি। লেবেল বা হস্তান্তর টিকিট পরীক্ষা করুন।",
                            )
                    }
                }
            }
            while (true) {
                val ids = db.inbox().unsentReads()
                if (ids.isEmpty()) break
                api.mark(auth, ReadRequest(keys.deviceToken, ids))
                db.inbox().acknowledge(ids)
            }
            var page = 1
            do {
                val inbox = api.inbox(auth, keys.deviceToken, page).data
                db.withTransaction { inbox.notifications.mapNotNull { merge(it) } }.forEach(notify)
                page++
            } while (page <= inbox.pagination.last)
            store(api.blacklist(auth).data)
            keys.synced()
        }

    private suspend fun submit(auth: String, rows: List<ScanHistoryEntity>) {
        val response =
            api.crosscheck(
                    auth,
                    Batch(
                        rows.map {
                            ScanLog(
                                it.syncUuid,
                                it.outerSerial,
                                it.innerSerial,
                                it.deviceToken,
                                Instant.ofEpochMilli(it.scannedAt).toString(),
                                it.offlineStatus,
                                it.gpsLat,
                                it.gpsLng,
                                ticket = it.transferTicket,
                            )
                        }
                    ),
                )
                .data
        val byId = rows.associateBy { it.syncUuid }
        if (
            response.results.size != rows.size ||
                response.results.map { it.uuid }.toSet() != byId.keys
        )
            throw IOException("Incomplete cross-check")
        db.withTransaction {
                val alerts = mutableListOf<Alert>()
                val mine = response.feedback.filter { it.device == keys.deviceToken }
                for (result in response.results) {
                    val row = byId.getValue(result.uuid)
                    val status =
                        when (result.verdict) {
                            "recalled_batch" ->
                                if (Verdict.positive(row.offlineStatus)) "retroactive_recall_alert"
                                else result.verdict
                            "clone_detected" ->
                                if (Verdict.positive(row.offlineStatus)) "retroactive_clone_alert"
                                else result.verdict
                            "verified_clean",
                            "owner_repeat_ok",
                            "store_grace_ok",
                            "forged_signature",
                            "expired" -> result.verdict
                            else -> throw IOException("Unknown cloud verdict")
                        }
                    db.history().verdict(row.syncUuid, status, result.en, result.bn)
                    if (
                        status.startsWith("retroactive_") &&
                            mine.none { it.outer == row.outerSerial }
                    ) {
                        alerts +=
                            Alert(-row.id, "Product alert", result.en, "পণ্য সতর্কতা", result.bn)
                    }
                }
                alerts + mine.mapNotNull { merge(it) }
            }
            .forEach(notify)
    }

    private suspend fun merge(n: Notice): Alert? {
        if (n.device != keys.deviceToken) return null
        val old = db.inbox().get(n.id)
        db.inbox()
            .put(
                NotificationEntity(
                    n.id,
                    n.outer,
                    n.severity,
                    n.titleEn,
                    n.en,
                    n.titleBn,
                    n.bn,
                    n.read || old?.isRead == true,
                    OffsetDateTime.parse(n.created).toInstant().toEpochMilli(),
                    old?.pendingRead == true && !n.read,
                    old?.bannerDismissed == true,
                )
            )
        val status =
            when (n.severity) {
                "critical_clone" -> "retroactive_clone_alert"
                "recall_alert" -> "retroactive_recall_alert"
                else -> return null
            }
        n.outer?.let { db.history().retroactive(it, status, n.en, n.bn) }
        return if (old == null && !n.read) Alert(n.id, n.titleEn, n.en, n.titleBn, n.bn) else null
    }
}
