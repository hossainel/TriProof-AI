package ai.triproof.scanner.util

import ai.triproof.scanner.R
import ai.triproof.scanner.data.Alert
import ai.triproof.scanner.ui.MainActivity
import android.Manifest
import android.app.*
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat

fun Context.bangla() =
    (AppCompatDelegate.getApplicationLocales()[0] ?: resources.configuration.locales[0]).language ==
        "bn"

fun Context.postAlert(alert: Alert) {
    val manager = getSystemService(NotificationManager::class.java)
    manager.createNotificationChannel(
        NotificationChannel(
            "product_alerts",
            getString(R.string.alerts),
            NotificationManager.IMPORTANCE_HIGH,
        )
    )
    if (
        Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) !=
                PackageManager.PERMISSION_GRANTED
    )
        return
    val title = if (bangla()) alert.bnTitle else alert.enTitle
    val body = if (bangla()) alert.bn else alert.en
    val pi =
        PendingIntent.getActivity(
            this,
            1,
            Intent(this, MainActivity::class.java)
                .putExtra("inbox", true)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
    manager.notify(
        "product:${alert.id}",
        1,
        NotificationCompat.Builder(this, "product_alerts")
            .setSmallIcon(R.drawable.ic_brand)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setContentIntent(pi)
            .build(),
    )
}
