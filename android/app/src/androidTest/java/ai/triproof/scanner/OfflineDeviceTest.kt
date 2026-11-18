package ai.triproof.scanner

import ai.triproof.scanner.crypto.*
import ai.triproof.scanner.data.local.*
import ai.triproof.scanner.scanner.Demo
import android.os.SystemClock
import android.util.Log
import androidx.room.Room
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.time.*
import kotlinx.coroutines.runBlocking
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class OfflineDeviceTest {
    @get:org.junit.Rule val radios = AirplaneModeRule()

    @Test
    fun recordsDistinctPackingLatencyWithPhysicalDatabaseAndKeystore() = runBlocking<Unit> {
        val ctx = InstrumentationRegistry.getInstrumentation().targetContext
        val name = "packing-check-${System.nanoTime()}"
        val db = Room.databaseBuilder(ctx, TriProofDatabase::class.java, name).build()
        try {
            val vault = KeystoreManager(ctx, name)
            // Only the instrumentation APK can issue these temporary labels. Signing is outside the
            // timer.
            val pair = com.google.crypto.tink.subtle.Ed25519Sign.KeyPair.newKeyPair()
            val signer = com.google.crypto.tink.subtle.Ed25519Sign(pair.privateKey)
            val identity =
                object : VerificationIdentity by vault {
                    override val publicKey =
                        java.util.Base64.getEncoder().encodeToString(pair.publicKey)
                }
            val engine = OfflineVerificationEngine(db, identity, {})
            val date = LocalDate.now()
            val format = java.time.format.DateTimeFormatter.BASIC_ISO_DATE
            val labels =
                (1..50).map {
                    val outer =
                        "QA-M1-TEST-${it.toString().padStart(6,'0')}|${date.format(format)}|${date.plusDays(10).format(format)}"
                    outer to
                        java.util.Base64.getEncoder()
                            .encodeToString(signer.sign(outer.toByteArray()))
                }
            val times =
                labels
                    .map { (outer, inner) ->
                        val start = SystemClock.elapsedRealtimeNanos()
                        val row = engine.verify(outer, inner, ScanMode.FACTORY, "shift")
                        val us = (SystemClock.elapsedRealtimeNanos() - start) / 1000
                        assertEquals(Verdict.GENUINE, row.offlineStatus)
                        us / 1000.0
                    }
                    .drop(5)
                    .sorted()
            Log.i(
                "TriProofTiming",
                "45 distinct packing labels (ms): p50=${times[22]}, p95=${times[42]}, max=${times.last()}",
            )
        } finally {
            db.close()
            ctx.deleteDatabase(name)
        }
    }

    @Test
    fun repeatSessionBlacklistAndClockSurviveReopen() = runBlocking {
        val ctx = InstrumentationRegistry.getInstrumentation().targetContext
        val name = "offline-check-${System.nanoTime()}"
        var db = Room.databaseBuilder(ctx, TriProofDatabase::class.java, name).build()
        val secure = KeystoreManager(ctx, name)
        var now = System.currentTimeMillis() + 50
        val fixedDate = Clock.fixed(Instant.parse("2026-01-15T12:00:00Z"), ZoneOffset.UTC)
        val identity =
            object : VerificationIdentity {
                override val publicKey = Demo.KEY
                override val deviceToken = secure.deviceToken

                override fun acceptScan(time: Long) = secure.acceptScan(now++)
            }
        fun engine() = OfflineVerificationEngine(db, identity, {}, fixedDate)
        try {
            var e = engine()
            assertEquals(
                Verdict.GENUINE,
                e.verify(Demo.OUTER, Demo.INNER, ScanMode.FACTORY, "A").offlineStatus,
            )
            db.close()
            db = Room.databaseBuilder(ctx, TriProofDatabase::class.java, name).build()
            e = engine()
            assertEquals(
                Verdict.DUPLICATE,
                e.verify(Demo.OUTER, Demo.INNER, ScanMode.FACTORY, "A").offlineStatus,
            )
            assertEquals(
                Verdict.REPEAT,
                e.verify(Demo.OUTER, Demo.INNER, ScanMode.FACTORY, "B").offlineStatus,
            )
            val times =
                (1..30)
                    .map {
                        val start = SystemClock.elapsedRealtimeNanos()
                        e.verify(Demo.OUTER, Demo.INNER, ScanMode.CONSUMER)
                        (SystemClock.elapsedRealtimeNanos() - start) / 1_000_000.0
                    }
                    .sorted()
            Log.i(
                "TriProofTiming",
                "Warm repeat: signature + encrypted clock + durable Room transaction (ms): p50=${times[14]}, p95=${times[28]}",
            )
            db.blacklist()
                .put(listOf(CachedBlacklistEntity("DEMO-M1-SAMPLE", "recalled_batch", now)))
            assertEquals(
                Verdict.BLOCKED,
                e.verify(Demo.OUTER, Demo.INNER, ScanMode.CONSUMER).offlineStatus,
            )
            now -= 60_000
            assertEquals(
                Verdict.CLOCK,
                e.verify(Demo.OUTER, Demo.INNER, ScanMode.CONSUMER).offlineStatus,
            )
            assertEquals(secure.deviceToken, KeystoreManager(ctx, name).deviceToken)
        } finally {
            db.close()
            ctx.deleteDatabase(name)
        }
    }
}
