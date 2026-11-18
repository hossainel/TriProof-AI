package ai.triproof.scanner.ui

import ai.triproof.scanner.R
import ai.triproof.scanner.crypto.Verdict
import ai.triproof.scanner.util.bangla
import android.content.Context
import android.content.res.ColorStateList
import android.view.View
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat
import androidx.core.view.*
import androidx.lifecycle.*
import java.text.DateFormat
import java.util.Date
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.launch

fun Context.toast(res: Int) = Toast.makeText(this, res, Toast.LENGTH_LONG).show()

fun Context.toggleLanguage() =
    AppCompatDelegate.setApplicationLocales(
        LocaleListCompat.forLanguageTags(if (bangla()) "en" else "bn")
    )

fun AppCompatActivity.content(root: View) {
    WindowCompat.setDecorFitsSystemWindows(window, false)
    setContentView(root)
    ViewCompat.setOnApplyWindowInsetsListener(root) { v, insets ->
        val edge =
            insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or
                    WindowInsetsCompat.Type.ime() or
                    WindowInsetsCompat.Type.displayCutout()
            )
        v.setPadding(edge.left, edge.top, edge.right, edge.bottom)
        WindowInsetsCompat.CONSUMED
    }
    ViewCompat.requestApplyInsets(root)
}

fun <T> LifecycleOwner.watch(flow: Flow<T>, action: (T) -> Unit) =
    lifecycleScope.launch {
        repeatOnLifecycle(Lifecycle.State.STARTED) { flow.collect { action(it) } }
    }

fun date(value: Long): String =
    DateFormat.getDateTimeInstance(DateFormat.MEDIUM, DateFormat.SHORT).format(Date(value))

fun TextView.tint(color: Int) {
    backgroundTintList = ColorStateList.valueOf(context.getColor(color))
}

fun offlineText(status: String) =
    when (status) {
        Verdict.GENUINE -> R.string.genuine
        Verdict.REPEAT -> R.string.repeat_ok
        Verdict.EXPIRED -> R.string.expired
        Verdict.DUPLICATE -> R.string.duplicate
        Verdict.BLOCKED -> R.string.blacklisted
        Verdict.CLOCK -> R.string.clock
        else -> R.string.fake
    }

fun cloudText(status: String) =
    when (status) {
        "verified_clean" -> R.string.cloud_clean
        "owner_repeat_ok" -> R.string.cloud_repeat
        "store_grace_ok" -> R.string.cloud_grace
        "retroactive_clone_alert",
        "clone_detected" -> R.string.cloud_clone
        "retroactive_recall_alert",
        "recalled_batch" -> R.string.cloud_recall
        "expired" -> R.string.cloud_expired
        "forged_signature" -> R.string.cloud_forged
        "local_only" -> R.string.local_only
        "pending_sync" -> R.string.pending
        else -> R.string.cloud_rejected
    }

fun cloudAlarm(s: String) =
    s !in setOf("pending_sync", "local_only", "verified_clean", "store_grace_ok", "owner_repeat_ok")
