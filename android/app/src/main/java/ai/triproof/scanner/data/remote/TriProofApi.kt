package ai.triproof.scanner.data.remote

import ai.triproof.scanner.BuildConfig
import java.util.concurrent.TimeUnit
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory
import retrofit2.http.*

@Serializable data class Envelope<T>(val success: Boolean, val data: T)

@Serializable data class Credentials(val email: String, val password: String)

@Serializable
data class KeyMeta(
    val algorithm: String,
    @SerialName("public_key_base64") val key: String,
    @SerialName("key_fingerprint") val fingerprint: String,
)

@Serializable
data class BlacklistPackage(val encoding: String, val payload: String, val sha256: String)

@Serializable
data class LoginData(
    val token: String,
    val verification: KeyMeta,
    @SerialName("offline_blacklist") val blacklist: BlacklistPackage,
)

@Serializable
data class ScanLog(
    @SerialName("sync_uuid") val uuid: String,
    @SerialName("outer_serial") val outer: String,
    @SerialName("inner_serial") val inner: String,
    @SerialName("device_token") val device: String,
    @SerialName("scanned_at") val time: String,
    @SerialName("offline_verdict") val offline: String,
    @SerialName("gps_lat") val lat: Double?,
    @SerialName("gps_lng") val lng: Double?,
    @SerialName("scan_mode") val mode: String = "offline_synced",
    @SerialName("transfer_ticket") val ticket: String? = null,
)

@Serializable data class Batch(val logs: List<ScanLog>)

@Serializable
data class CloudResult(
    @SerialName("sync_uuid") val uuid: String,
    val verdict: String,
    @SerialName("message_en") val en: String,
    @SerialName("message_bn") val bn: String,
)

@Serializable
data class Crosscheck(
    val results: List<CloudResult>,
    @SerialName("feedback_notifications") val feedback: List<Notice>,
)

@Serializable
data class Notice(
    val id: Long,
    @SerialName("device_token") val device: String,
    @SerialName("outer_serial") val outer: String?,
    val severity: String,
    @SerialName("title_en") val titleEn: String,
    @SerialName("message_en") val en: String,
    @SerialName("title_bn") val titleBn: String,
    @SerialName("message_bn") val bn: String,
    @SerialName("is_read") val read: Boolean,
    @SerialName("created_at") val created: String,
)

@Serializable data class Pagination(@SerialName("last_page") val last: Int)

@Serializable data class Inbox(val notifications: List<Notice>, val pagination: Pagination)

@Serializable
data class ReadRequest(@SerialName("device_token") val device: String, val ids: List<Long>)

@Serializable data class ReadResult(@SerialName("marked_read") val marked: Int)

@Serializable
data class HandoffRequest(
    @SerialName("device_token") val device: String,
    @SerialName("outer_serial") val outer: String,
    @SerialName("inner_serial") val inner: String,
)

@Serializable data class Handoff(val ticket: String)

interface TriProofApi {
    @GET("/.well-known/triproof-key.json") suspend fun key(): Envelope<KeyMeta>

    @POST("auth/login") suspend fun login(@Body request: Credentials): Envelope<LoginData>

    @GET("offline-blacklist")
    suspend fun blacklist(@Header("Authorization") auth: String): Envelope<BlacklistPackage>

    @POST("verify/online-crosscheck")
    suspend fun crosscheck(
        @Header("Authorization") auth: String,
        @Body body: Batch,
    ): Envelope<Crosscheck>

    @GET("notifications/{device}")
    suspend fun inbox(
        @Header("Authorization") auth: String,
        @Path("device") device: String,
        @Query("page") page: Int,
        @Query("per_page") size: Int = 100,
    ): Envelope<Inbox>

    @POST("notifications/mark-read")
    suspend fun mark(
        @Header("Authorization") auth: String,
        @Body body: ReadRequest,
    ): Envelope<ReadResult>

    @POST("verify/transfer-ticket")
    suspend fun handoff(
        @Header("Authorization") auth: String,
        @Body body: HandoffRequest,
    ): Envelope<Handoff>

    companion object {
        val json = Json {
            ignoreUnknownKeys = true
            encodeDefaults = true
        }

        fun create(baseUrl: String = BuildConfig.API_BASE_URL): TriProofApi =
            Retrofit.Builder()
                .baseUrl(baseUrl)
                .client(
                    OkHttpClient.Builder()
                        .followRedirects(false)
                        .followSslRedirects(false)
                        .callTimeout(30, TimeUnit.SECONDS)
                        .apply {
                            if (BuildConfig.DEBUG && BuildConfig.DEBUG_VHOST.isNotEmpty())
                                addInterceptor { chain ->
                                    chain.proceed(
                                        chain
                                            .request()
                                            .newBuilder()
                                            .header("Host", BuildConfig.DEBUG_VHOST)
                                            .build()
                                    )
                                }
                        }
                        .build()
                )
                .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
                .build()
                .create(TriProofApi::class.java)
    }
}
