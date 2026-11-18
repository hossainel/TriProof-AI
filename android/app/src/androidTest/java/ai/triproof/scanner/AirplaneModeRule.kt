package ai.triproof.scanner

import android.net.ConnectivityManager
import android.os.Build
import android.os.ParcelFileDescriptor
import android.os.SystemClock
import android.provider.Settings
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.rules.ExternalResource

/** Opt-in for the disposable managed emulator. Never toggle radios on a connected handset. */
class AirplaneModeRule : ExternalResource() {
    private val instrumentation
        get() = InstrumentationRegistry.getInstrumentation()

    private var active = false
    private var airplaneWasOn = false
    private var wifiWasOn = false

    private fun shell(command: String) =
        ParcelFileDescriptor.AutoCloseInputStream(
                instrumentation.uiAutomation.executeShellCommand(command)
            )
            .bufferedReader()
            .use { it.readText().trim() }

    override fun before() {
        if (InstrumentationRegistry.getArguments().getString("airplane") != "true") return
        check(Build.HARDWARE == "ranchu" || Build.HARDWARE == "goldfish")
        val ctx = instrumentation.targetContext
        airplaneWasOn =
            Settings.Global.getInt(ctx.contentResolver, Settings.Global.AIRPLANE_MODE_ON, 0) == 1
        wifiWasOn = shell("settings get global wifi_on") == "1"
        active = true
        try {
            shell("cmd connectivity airplane-mode enable")
            shell("svc wifi disable")
            val cm = ctx.getSystemService(ConnectivityManager::class.java)
            val deadline = SystemClock.elapsedRealtime() + 10_000
            while (cm.activeNetwork != null && SystemClock.elapsedRealtime() < deadline) SystemClock
                .sleep(80)
            assertEquals(
                1,
                Settings.Global.getInt(ctx.contentResolver, Settings.Global.AIRPLANE_MODE_ON),
            )
            assertNull(cm.activeNetwork)
        } catch (t: Throwable) {
            after()
            throw t
        }
    }

    override fun after() {
        if (active) {
            if (!airplaneWasOn) shell("cmd connectivity airplane-mode disable")
            if (wifiWasOn) shell("svc wifi enable")
            active = false
        }
    }
}
