package ai.triproof.scanner

import ai.triproof.scanner.crypto.*
import ai.triproof.scanner.data.Alert
import ai.triproof.scanner.data.ScannerRepository
import ai.triproof.scanner.data.local.*
import ai.triproof.scanner.data.remote.TriProofApi
import ai.triproof.scanner.scanner.Demo
import androidx.room.Room
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import java.io.ByteArrayOutputStream
import java.util.Base64
import java.util.zip.GZIPOutputStream
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.*
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import retrofit2.HttpException

@RunWith(AndroidJUnit4::class)
class SyncDeviceTest {
    @Test
    fun offlineQueueSurvivesFailedPostAndMapsOutOfOrderResultsAndReadReceipts() = runBlocking {
        val ctx = InstrumentationRegistry.getInstrumentation().targetContext
        val db = Room.inMemoryDatabaseBuilder(ctx, TriProofDatabase::class.java).build()
        val keys = KeystoreManager(ctx, "sync_test")
        val device = keys.deviceToken
        val server = MockWebServer()
        server.start()
        try {
            val posted = mutableListOf<String>()
            val notices = mutableListOf<Alert>()
            var reject = true
            var read = false
            val n =
                """{"id":7,"device_token":"$device","outer_serial":"${Demo.OUTER}","severity":"critical_clone","title_en":"Clone","message_en":"Isolate","title_bn":"ক্লোন","message_bn":"আলাদা করুন","is_read":false,"created_at":"2026-01-15T00:00:00Z"}"""
            val bytes =
                ByteArrayOutputStream()
                    .apply {
                        GZIPOutputStream(this).use {
                            it.write(
                                """{"format":"triproof-offline-blacklist/v1","generated_at":"2026-01-15T00:00:00Z","truncated":false,"recalled_batches":[{"batch":"XX-M-B"}],"cloned_serials":[]}"""
                                    .toByteArray()
                            )
                        }
                    }
                    .toByteArray()
            val pack =
                """{"encoding":"gzip+base64","payload":"${Base64.getEncoder().encodeToString(bytes)}","sha256":"${sha256(bytes)}"}"""
            server.dispatcher =
                object : Dispatcher() {
                    override fun dispatch(request: RecordedRequest): MockResponse {
                        if (request.getHeader("Authorization") != "Bearer test")
                            return MockResponse().setResponseCode(401)
                        val data =
                            when (request.requestUrl?.encodedPath) {
                                "/api/v1/verify/online-crosscheck" -> {
                                    val logs =
                                        JSONObject(request.body.readUtf8()).getJSONArray("logs")
                                    posted += logs.getJSONObject(0).getString("sync_uuid")
                                    if (reject) {
                                        reject = false
                                        return MockResponse().setResponseCode(503)
                                    }
                                    """{"results":[{"sync_uuid":"b","verdict":"verified_clean","message_en":"ok","message_bn":"ঠিক"},{"sync_uuid":"a","verdict":"clone_detected","message_en":"Isolate","message_bn":"আলাদা করুন"}],"feedback_notifications":[$n,${n.replace(device,"someone-else").replace("\"id\":7","\"id\":8") }]}"""
                                }
                                "/api/v1/notifications/$device" ->
                                    """{"notifications":[$n],"pagination":{"last_page":1}}"""
                                "/api/v1/notifications/mark-read" -> {
                                    val body = JSONObject(request.body.readUtf8())
                                    read = body.getJSONArray("ids").getLong(0) == 7L
                                    """{"marked_read":1}"""
                                }
                                "/api/v1/offline-blacklist" -> pack
                                else -> return MockResponse().setResponseCode(404)
                            }
                        return MockResponse()
                            .setHeader("Content-Type", "application/json")
                            .setBody("""{"success":true,"data":$data}""")
                    }
                }
            val repo =
                ScannerRepository(
                    db,
                    keys,
                    TriProofApi.create(server.url("/api/v1/").toString()),
                    { notices += it },
                    {},
                )
            val row =
                Demo.sample("genuine")
                    .copy(syncUuid = "a", deviceToken = device, cloudStatus = "pending_sync")
            val a = db.history().insert(row)
            val b = db.history().insert(row.copy(syncUuid = "b", outerSerial = "other"))
            try {
                repo.sync("test")
                fail("Expected retryable HTTP failure")
            } catch (e: HttpException) {
                assertEquals(503, e.code())
            }
            assertEquals(2, db.history().pending(100).size)
            repo.sync("test")
            assertEquals(listOf("a", "a"), posted)
            assertEquals("retroactive_clone_alert", db.history().get(a)?.cloudStatus)
            assertEquals("verified_clean", db.history().get(b)?.cloudStatus)
            assertTrue(db.blacklist().blocked("unused", "XX-M-B"))
            assertEquals(1, notices.size)
            assertEquals(1, db.inbox().all().first().size)
            val earlier =
                db.history()
                    .insert(
                        row.copy(
                            syncUuid = "old",
                            outerSerial = "older-unit",
                            factoryId = "XX",
                            machineId = "M",
                            batchId = "B",
                            cloudStatus = "verified_clean",
                        )
                    )
            repo.markRead()
            repo.sync("test")
            assertTrue(read)
            assertEquals(0, db.inbox().unread().first())
            assertEquals("retroactive_recall_alert", db.history().get(earlier)?.cloudStatus)
            assertEquals(2, notices.size)
        } finally {
            server.shutdown()
            db.close()
        }
    }

    @Test
    fun loginRejectsAnUnpinnedServerBeforeSendingCredentials() = runBlocking {
        val ctx = InstrumentationRegistry.getInstrumentation().targetContext
        val db = Room.inMemoryDatabaseBuilder(ctx, TriProofDatabase::class.java).build()
        val keys = KeystoreManager(ctx, "pin_test")
        MockWebServer().use { server ->
            server.start()
            try {
                server.enqueue(
                    MockResponse()
                        .setBody(
                            """{"success":true,"data":{"algorithm":"Ed25519","public_key_base64":"${Demo.KEY}","key_fingerprint":"${sha256(Base64.getDecoder().decode(Demo.KEY))}"}}"""
                        )
                )
                val repo =
                    ScannerRepository(
                        db,
                        keys,
                        TriProofApi.create(server.url("/api/v1/").toString()),
                        {},
                        {},
                    )
                try {
                    repo.login("example@example.test", "test")
                    fail("Unpinned key accepted")
                } catch (_: java.security.GeneralSecurityException) {}
                assertEquals("/.well-known/triproof-key.json", server.takeRequest().path)
                assertEquals(1, server.requestCount)
                assertNull(keys.token.value)
            } finally {
                db.close()
            }
        }
    }
}
