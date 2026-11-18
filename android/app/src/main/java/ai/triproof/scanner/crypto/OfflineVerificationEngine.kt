package ai.triproof.scanner.crypto

import ai.triproof.scanner.data.local.ScanHistoryEntity
import ai.triproof.scanner.data.local.TriProofDatabase
import androidx.room.withTransaction
import java.time.Clock
import java.time.Instant
import java.util.UUID
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

data class Fix(val lat: Double, val lng: Double)

class OfflineVerificationEngine(
    private val db: TriProofDatabase,
    private val vault: VerificationIdentity,
    private val saved: () -> Unit,
    private val clock: Clock = Clock.systemDefaultZone(),
) {
    private val policy = SignaturePolicy(vault.publicKey)

    suspend fun verify(
        outerSerial: String,
        innerSerial: String,
        scanMode: ScanMode,
        session: String? = null,
        fix: Fix? = null,
        ticket: String? = null,
    ): ScanHistoryEntity =
        withContext(Dispatchers.IO) {
            require(scanMode != ScanMode.FACTORY || !session.isNullOrBlank())
            val outer = outerSerial.trim()
            val inner = innerSerial.filterNot(Char::isWhitespace)
            require(outer.length in 1..128 && inner.length in 1..255)
            val now = clock.millis()
            val clockOK = vault.acceptScan(now)
            val label = Label.parse(outer)
            val signatureStatus =
                if (!clockOK) Verdict.CLOCK
                else
                    policy.evaluate(
                        outer,
                        inner,
                        label,
                        Instant.ofEpochMilli(now).atZone(clock.zone).toLocalDate(),
                        scanMode,
                    )
            val hash = unitHash(outer, inner)
            val uuid = UUID.randomUUID().toString()
            val packing = session.takeIf { scanMode == ScanMode.FACTORY }
            val row =
                db.withTransaction {
                    val state =
                        if (signatureStatus == Verdict.GENUINE && label != null)
                            db.history().evidence(outer, label.batchKey, packing, vault.deviceToken)
                        else null
                    val status =
                        when {
                            state == null -> signatureStatus
                            state.blocked -> Verdict.BLOCKED
                            state.duplicateBox -> Verdict.DUPLICATE
                            state.previousVerifiedAt != null -> Verdict.REPEAT
                            else -> Verdict.GENUINE
                        }
                    val item =
                        ScanHistoryEntity(
                            syncUuid = uuid,
                            outerSerial = outer,
                            innerSerial = inner,
                            unitHash = hash,
                            factoryId = label?.factory,
                            machineId = label?.machine,
                            batchId = label?.batch,
                            prodDate = label?.prod?.toString(),
                            expDate = label?.exp?.toString(),
                            offlineStatus = status,
                            cloudStatus =
                                if (status == Verdict.CLOCK) "local_only" else "pending_sync",
                            gpsLat = fix?.lat,
                            gpsLng = fix?.lng,
                            scannedAt = now,
                            deviceToken = vault.deviceToken,
                            packingSession = packing,
                            previousVerifiedAt = state?.previousVerifiedAt,
                            transferTicket = ticket,
                        )
                    item.copy(id = db.history().insert(item))
                }
            saved()
            row
        }
}
