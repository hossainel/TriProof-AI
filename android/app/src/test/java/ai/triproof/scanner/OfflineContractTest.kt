package ai.triproof.scanner

import ai.triproof.scanner.crypto.*
import ai.triproof.scanner.data.remote.*
import ai.triproof.scanner.scanner.*
import java.io.ByteArrayOutputStream
import java.time.LocalDate
import java.util.Base64
import java.util.zip.GZIPOutputStream
import org.junit.Assert.*
import org.junit.Test

class OfflineContractTest {
    private val policy = SignaturePolicy(Demo.KEY)

    private fun verdict(date: String, mode: ScanMode) =
        policy.evaluate(
            Demo.OUTER,
            Demo.INNER,
            Label.parse(Demo.OUTER),
            LocalDate.parse(date),
            mode,
        )

    @Test
    fun phpSodiumVectorAndWireHashMatchExactly() {
        assertEquals(
            "2463c1dffe7dd17ea938afe1f964eee13e62fad0ec2df47c4b814c4a9d1a48c7",
            unitHash(Demo.OUTER, Demo.INNER),
        )
        assertEquals(Verdict.GENUINE, verdict("2026-12-31", ScanMode.CONSUMER))
        assertEquals(Verdict.EXPIRED, verdict("2027-01-01", ScanMode.CONSUMER))
        assertEquals(
            Verdict.FAKE,
            policy.evaluate(
                Demo.OUTER.replace("000001", "000002"),
                Demo.INNER,
                Label.parse(Demo.OUTER),
                LocalDate.of(2026, 1, 1),
                ScanMode.CONSUMER,
            ),
        )
        assertEquals(
            Verdict.FAKE,
            SignaturePolicy(BuildConfig.PUBLIC_KEY)
                .evaluate(
                    Demo.OUTER,
                    Demo.INNER,
                    Label.parse(Demo.OUTER),
                    LocalDate.of(2026, 1, 1),
                    ScanMode.CONSUMER,
                ),
        )
    }

    @Test
    fun factoryWindowIsInclusiveAndDoesNotAcceptFutureProduction() {
        assertEquals(Verdict.EXPIRED, verdict("2025-12-31", ScanMode.FACTORY))
        assertEquals(Verdict.GENUINE, verdict("2026-02-15", ScanMode.FACTORY))
        assertEquals(Verdict.EXPIRED, verdict("2026-02-16", ScanMode.FACTORY))
    }

    @Test
    fun strictCalendarAndOcrAndHandoffBoundaries() {
        assertNull(Label.parse(Demo.OUTER.replace("20260101", "20260230")))
        assertNull(Label.parse(Demo.OUTER.replace("000001", "000000")))
        assertEquals(
            Demo.OUTER,
            Reader.read("Label: " + Demo.OUTER.lowercase().replace("|", " ¦ "), Step.OUTER, true)
                ?.text,
        )
        assertNull(Reader.read(Demo.OUTER, Step.INNER))
        val ticket = "a".repeat(64)
        assertEquals(
            Candidate(Demo.OUTER, ticket),
            Reader.read(Reader.handoff(Demo.OUTER, ticket), Step.OUTER),
        )
        assertNull(Reader.read(Reader.handoff(Demo.OUTER, "bad"), Step.OUTER))
    }

    @Test
    fun simulatorRunsSignedExpiredAndSwappedFixtures() {
        assertEquals(Verdict.GENUINE, Demo.sample("genuine").offlineStatus)
        assertEquals(Verdict.EXPIRED, Demo.sample("expired").offlineStatus)
        assertEquals(Verdict.FAKE, Demo.sample("forged").offlineStatus)
    }

    private fun pack(json: String): BlacklistPackage {
        val bytes =
            ByteArrayOutputStream()
                .apply { GZIPOutputStream(this).use { it.write(json.toByteArray()) } }
                .toByteArray()
        return BlacklistPackage(
            "gzip+base64",
            Base64.getEncoder().encodeToString(bytes),
            sha256(bytes),
        )
    }

    @Test
    fun parsesBackendBlacklistAndRejectsCorruptionAndUnboundedExpansion() {
        val p =
            pack(
                """{"format":"triproof-offline-blacklist/v1","generated_at":"2026-01-15T10:00:00+06:00","recalled_batches":[{"batch":"DHK04-M1-B1"}],"cloned_serials":[{"serial":"X"}],"truncated":true}"""
            )
        val decoded = BlacklistCodec.decode(p)
        assertTrue(decoded.partial)
        assertEquals(listOf("DHK04-M1-B1", "X"), decoded.entries.map { it.serialOrBatch })
        assertThrows(IllegalArgumentException::class.java) {
            BlacklistCodec.decode(p.copy(sha256 = "0".repeat(64)))
        }
        assertThrows(IllegalArgumentException::class.java) {
            BlacklistCodec.decode(pack("a".repeat(2_100_000)))
        }
        assertThrows(IllegalArgumentException::class.java) {
            BlacklistCodec.decode(p.copy(payload = "x".repeat(50_001)))
        }
    }
}
