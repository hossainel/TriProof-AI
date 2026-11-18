package ai.triproof.scanner.data.remote

import ai.triproof.scanner.crypto.sha256
import ai.triproof.scanner.data.local.CachedBlacklistEntity
import java.io.ByteArrayOutputStream
import java.time.OffsetDateTime
import java.util.Base64
import java.util.zip.GZIPInputStream
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

object BlacklistCodec {
    @Serializable private data class Recall(val batch: String)

    @Serializable private data class Clone(val serial: String)

    @Serializable
    private data class Payload(
        val format: String,
        @SerialName("generated_at") val generated: String,
        @SerialName("recalled_batches") val recalls: List<Recall>,
        @SerialName("cloned_serials") val clones: List<Clone>,
        val truncated: Boolean,
    )

    data class Snapshot(val entries: List<CachedBlacklistEntity>, val partial: Boolean)

    fun decode(pack: BlacklistPackage): Snapshot {
        require(pack.encoding == "gzip+base64" && pack.payload.length in 1..50_000)
        val compressed = Base64.getDecoder().decode(pack.payload)
        require(sha256(compressed) == pack.sha256)
        val json =
            GZIPInputStream(compressed.inputStream()).use { stream ->
                val output = ByteArrayOutputStream()
                val block = ByteArray(8192)
                while (true) {
                    val n = stream.read(block)
                    if (n < 0) break
                    require(output.size() + n <= 2 * 1024 * 1024)
                    output.write(block, 0, n)
                }
                output.toString("UTF-8")
            }
        val p = TriProofApi.json.decodeFromString<Payload>(json)
        require(p.format == "triproof-offline-blacklist/v1")
        val at = OffsetDateTime.parse(p.generated).toInstant().toEpochMilli()
        return Snapshot(
            p.recalls.map { CachedBlacklistEntity(it.batch, "recalled_batch", at) } +
                p.clones.map { CachedBlacklistEntity(it.serial, "clone_detected", at) },
            p.truncated,
        )
    }
}
