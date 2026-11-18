package ai.triproof.scanner.scanner

import android.content.Context
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.*

@Suppress("DEPRECATION")
fun Context.feedback(good: Boolean) {
    val vibrator =
        if (Build.VERSION.SDK_INT >= 31)
            getSystemService(VibratorManager::class.java).defaultVibrator
        else getSystemService(Vibrator::class.java)
    if (vibrator.hasVibrator())
        vibrator.vibrate(
            if (good) VibrationEffect.createOneShot(55, VibrationEffect.DEFAULT_AMPLITUDE)
            else VibrationEffect.createWaveform(longArrayOf(0, 130, 70, 180), -1)
        )
    val tone =
        runCatching { ToneGenerator(AudioManager.STREAM_NOTIFICATION, 65) }.getOrNull() ?: return
    tone.startTone(if (good) ToneGenerator.TONE_PROP_ACK else ToneGenerator.TONE_PROP_NACK, 220)
    Handler(Looper.getMainLooper()).postDelayed({ tone.release() }, 320)
}
