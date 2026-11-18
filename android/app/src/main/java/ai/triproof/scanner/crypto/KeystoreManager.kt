package ai.triproof.scanner.crypto

import ai.triproof.scanner.BuildConfig
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.security.KeyStore
import java.security.MessageDigest
import java.util.Base64
import java.util.UUID
import javax.crypto.KeyGenerator
import javax.crypto.Mac
import javax.crypto.SecretKey
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow

interface VerificationIdentity {
    val publicKey: String
    val deviceToken: String

    fun acceptScan(time: Long): Boolean
}

@Suppress("DEPRECATION")
class KeystoreManager(context: Context, namespace: String = "triproof") : VerificationIdentity {
    private val prefs =
        EncryptedSharedPreferences.create(
            context,
            "${namespace}_vault",
            MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    override val publicKey: String
    override val deviceToken: String
    private val clockKey = "last_known_verified_timestamp"
    private var high = prefs.getLong(clockKey, 0L)
    private val session = MutableStateFlow(prefs.getString("jwt", null))
    val token = session.asStateFlow()
    private val sync = MutableStateFlow(prefs.getLong("last_sync", 0))
    val lastSync = sync.asStateFlow()
    var entered: Boolean
        get() = prefs.getBoolean("entered", false)
        set(value) {
            prefs.edit().putBoolean("entered", value).apply()
        }

    init {
        // The APK pin is the trust anchor, including after an intentional key rotation by app
        // update.
        val stored = prefs.getString("UNIVERSAL_PUBLIC_VERIFICATION_KEY", null)
        publicKey = stored?.takeIf { it == BuildConfig.PUBLIC_KEY } ?: BuildConfig.PUBLIC_KEY
        val uuid = prefs.getString("install_id", null) ?: UUID.randomUUID().toString()
        val alias = "$namespace.device.hmac.v1"
        val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        fun generate(strongBox: Boolean): SecretKey =
            KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_HMAC_SHA256, "AndroidKeyStore")
                .run {
                    val spec =
                        KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_SIGN)
                            .setDigests(KeyProperties.DIGEST_SHA256)
                    if (Build.VERSION.SDK_INT >= 28 && strongBox) spec.setIsStrongBoxBacked(true)
                    init(spec.build())
                    generateKey()
                }
        val secret =
            store.getKey(alias, null) as? SecretKey
                ?: if (
                    Build.VERSION.SDK_INT >= 28 &&
                        context.packageManager.hasSystemFeature(
                            PackageManager.FEATURE_STRONGBOX_KEYSTORE
                        )
                ) {
                    try {
                        generate(true)
                    } catch (_: java.security.ProviderException) {
                        generate(false)
                    }
                } else generate(false)
        val mac = Mac.getInstance("HmacSHA256").apply { init(secret) }
        deviceToken = "$uuid.${mac.doFinal(uuid.toByteArray()).hex()}"
        check(
            prefs
                .edit()
                .putString("install_id", uuid)
                .putString("device_token", deviceToken)
                .putString("UNIVERSAL_PUBLIC_VERIFICATION_KEY", publicKey)
                .commit()
        )
        advance(System.currentTimeMillis())
    }

    @Synchronized
    fun advance(time: Long): Boolean {
        if (time < high) return false
        check(prefs.edit().putLong(clockKey, time).commit())
        high = time
        return true
    }

    // apply() updates the encrypted preferences immediately. The scan's Room commit is also
    // durable before returning a verdict; launch reconciles it if the preferences write was
    // interrupted.
    @Synchronized
    override fun acceptScan(time: Long): Boolean {
        if (time < high) return false
        if (time > high) {
            high = time
            prefs.edit().putLong(clockKey, time).apply()
        }
        return true
    }

    fun matchesPin(key: String, fingerprint: String): Boolean {
        val raw = Base64.getDecoder().decode(key)
        return raw.size == 32 &&
            MessageDigest.isEqual(raw, Base64.getDecoder().decode(publicKey)) &&
            sha256(raw) == fingerprint
    }

    @Synchronized
    fun login(jwt: String) {
        require(jwt.isNotBlank())
        check(prefs.edit().putString("jwt", jwt).putBoolean("entered", true).commit())
        session.value = jwt
    }

    @Synchronized
    fun expire(expected: String) {
        if (session.value == expected) {
            prefs.edit().remove("jwt").apply()
            session.value = null
        }
    }

    fun synced() {
        val now = System.currentTimeMillis()
        advance(now)
        prefs.edit().putLong("last_sync", now).apply()
        sync.value = now
    }
}
