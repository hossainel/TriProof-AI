package ai.triproof.scanner.crypto

import org.bouncycastle.crypto.params.Ed25519PublicKeyParameters
import org.bouncycastle.crypto.signers.Ed25519Signer
import java.security.MessageDigest
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.time.format.DateTimeParseException
import java.util.Base64

enum class ScanMode {
    CONSUMER,
    FACTORY,
}

object Verdict {
    const val GENUINE = "genuine_offline"
    const val REPEAT = "owner_repeat_ok"
    const val FAKE = "fake_signature"
    const val EXPIRED = "expired"
    const val DUPLICATE = "duplicate_box"
    const val BLOCKED = "recalled_cached"
    const val CLOCK = "clock_tampered"

    fun positive(value: String) = value == GENUINE || value == REPEAT
}

data class Label(
    val factory: String,
    val machine: String,
    val batch: String,
    val prod: LocalDate,
    val exp: LocalDate,
) {
    val batchKey
        get() = "$factory-$machine-$batch"

    companion object {
        // Same greedy identifier boundaries as SerialCryptoService::parseOuterSerial.
        val pattern =
            Regex(
                "([A-Z0-9][A-Z0-9-]{1,15})-([A-Z0-9][A-Z0-9-]{0,15})-([A-Z0-9][A-Z0-9-]{0,15})-(\\d{6})\\|(\\d{8})\\|(\\d{8})"
            )

        fun parse(text: String): Label? {
            val m = pattern.matchEntire(text)?.groupValues ?: return null
            return try {
                val prod = LocalDate.parse(m[5], DateTimeFormatter.BASIC_ISO_DATE)
                val exp = LocalDate.parse(m[6], DateTimeFormatter.BASIC_ISO_DATE)
                if (m[4] == "000000" || exp < prod) null else Label(m[1], m[2], m[3], prod, exp)
            } catch (_: DateTimeParseException) {
                null
            }
        }
    }
}

private const val HEX = "0123456789abcdef"

fun ByteArray.hex(): String =
    buildString(size * 2) {
        for (b in this@hex) {
            append(HEX[(b.toInt() and 255) ushr 4])
            append(HEX[b.toInt() and 15])
        }
    }

fun sha256(bytes: ByteArray): String = MessageDigest.getInstance("SHA-256").digest(bytes).hex()

fun unitHash(outer: String, inner: String) = sha256("$outer|$inner".toByteArray(Charsets.UTF_8))

class SignaturePolicy(key: String) {
    // Use the lightweight raw-key API without installing/replacing an Android JCA provider.
    private val verifier = Ed25519Signer().apply {
        init(false, Ed25519PublicKeyParameters(Base64.getDecoder().decode(key).also { require(it.size == 32) }))
    }

    fun evaluate(
        outer: String,
        inner: String,
        label: Label?,
        today: LocalDate,
        mode: ScanMode,
    ): String {
        if (label == null || inner.length != 88) return Verdict.FAKE
        try {
            val signature = Base64.getDecoder().decode(inner)
            if (signature.size != 64 || Base64.getEncoder().encodeToString(signature) != inner)
                return Verdict.FAKE
            val message = outer.toByteArray(Charsets.UTF_8)
            val valid = synchronized(verifier) {
                verifier.reset()
                verifier.update(message, 0, message.size)
                verifier.verifySignature(signature)
            }
            if (!valid) return Verdict.FAKE
        } catch (_: IllegalArgumentException) {
            return Verdict.FAKE
        }
        if (
            today > label.exp ||
                (mode == ScanMode.FACTORY &&
                    (today < label.prod || today > label.prod.plusDays(45)))
        )
            return Verdict.EXPIRED
        return Verdict.GENUINE
    }
}
