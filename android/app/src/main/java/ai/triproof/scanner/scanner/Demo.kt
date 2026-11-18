package ai.triproof.scanner.scanner

import ai.triproof.scanner.crypto.*
import ai.triproof.scanner.data.local.ScanHistoryEntity
import java.time.LocalDate

/** Public libsodium vectors only. This separate verifier cannot persist or sync a scan. */
object Demo {
    const val KEY = "K5CMEJTvuw9X7LpuB/hoeTGbeKVAa53H/ESUBQgChbI="
    const val OUTER = "DEMO-M1-SAMPLE-000001|20260101|20261231"
    const val INNER =
        "uQ6ufHY09zpPwjdOMTL0uFKhgwSzMeUdqo5Xe6GUbUGB3CwwKh73cpoErzASPNvKR5sCF3cGKL3QTY+YCT/eBA=="
    private const val OLD = "DEMO-M1-SAMPLE-000002|20250101|20251231"
    private const val OLD_SIG =
        "gIP3jrnlXot1DPMROsTKvf7A3s0d/Je+DJISQUgchEdLg1s9cwmnYy+glyUuR/ZAf206PRML2lO+J4EulTsKBQ=="

    fun sample(kind: String): ScanHistoryEntity {
        val outer = if (kind == "expired") OLD else OUTER
        val inner = if (kind == "genuine") INNER else OLD_SIG
        val label = checkNotNull(Label.parse(outer))
        val status =
            SignaturePolicy(KEY)
                .evaluate(outer, inner, label, LocalDate.of(2026, 1, 15), ScanMode.CONSUMER)
        return ScanHistoryEntity(
            syncUuid = "demo",
            outerSerial = outer,
            innerSerial = inner,
            unitHash = unitHash(outer, inner),
            factoryId = label.factory,
            machineId = label.machine,
            batchId = label.batch,
            prodDate = label.prod.toString(),
            expDate = label.exp.toString(),
            offlineStatus = status,
            cloudStatus = "local_only",
            gpsLat = null,
            gpsLng = null,
            scannedAt = 0,
            deviceToken = "demo",
            packingSession = null,
        )
    }
}
