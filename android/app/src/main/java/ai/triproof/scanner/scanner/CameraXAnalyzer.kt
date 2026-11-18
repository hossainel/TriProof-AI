package ai.triproof.scanner.scanner

import ai.triproof.scanner.crypto.Label
import androidx.camera.core.ExperimentalGetImage
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import com.google.android.gms.tasks.Task
import com.google.android.gms.tasks.Tasks
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import java.util.Locale
import java.util.concurrent.Executor

enum class Step {
    OUTER,
    SEAL,
    INNER,
    BUSY,
    RESULT,
}

data class Candidate(val text: String, val ticket: String? = null)

object Reader {
    private val inner = Regex("[A-Za-z0-9+/]{86}==")
    private val hex = Regex("[a-f0-9]{64}")
    // Only repair fixed-width numeric slots in OCR. Barcodes and signature bytes are never
    // corrected.
    private val dateSeparators =
        Regex(
            "-([0-9OI]{6})(?:\\s*[|I!1]\\s*|\\s+)([0-9OI]{8})(?:\\s*[|I!1]\\s*|\\s+)([0-9OI]{8})(?![0-9])"
        )
    private val separatorSpaces = Regex("\\s*([|\\-])\\s*")
    private const val PREFIX = "TRIPROOF:HANDOFF:1:"

    fun handoff(outer: String, ticket: String) = "$PREFIX$ticket:$outer"

    fun read(text: String, step: Step, ocr: Boolean = false): Candidate? {
        if (text.length > 4096) return null
        val raw = text.trim()
        if (step == Step.INNER)
            return raw.filterNot(Char::isWhitespace).takeIf(inner::matches)?.let(::Candidate)
        if (step != Step.OUTER) return null
        if (!ocr && raw.startsWith(PREFIX)) {
            val parts = raw.removePrefix(PREFIX).split(':', limit = 2)
            return if (parts.size == 2 && hex.matches(parts[0]) && Label.parse(parts[1]) != null)
                Candidate(parts[1], parts[0])
            else null
        }
        val normalized =
            if (ocr)
                raw.uppercase(Locale.ROOT)
                    .replace('–', '-')
                    .replace('—', '-')
                    .replace('¦', '|')
                    .replace(separatorSpaces, "$1")
            else raw
        val value =
            if (ocr)
                Label.pattern
                    .findAll(
                        dateSeparators.replace(normalized) { m ->
                            "-" +
                                m.groupValues.drop(1).joinToString("|") {
                                    it.replace('O', '0').replace('I', '1')
                                }
                        }
                    )
                    .firstOrNull { Label.parse(it.value) != null }
                    ?.value
            else raw
        return value?.takeIf { Label.parse(it) != null }?.let(::Candidate)
    }
}

/** A frame stays owned by ML Kit until both detectors settle, including failed/cancelled tasks. */
class CameraXAnalyzer(private val step: () -> Step, private val result: (Step, Candidate) -> Unit) :
    ImageAnalysis.Analyzer, AutoCloseable {
    private val barcode = BarcodeScanning.getClient()
    private val text = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
    private val direct = Executor { it.run() }
    private var closed = false
    private var busy = false

    @ExperimentalGetImage
    override fun analyze(frame: ImageProxy) {
        val stage = step()
        val image = frame.image
        synchronized(this) {
            if (closed || busy || image == null || stage !in setOf(Step.OUTER, Step.INNER)) {
                frame.close()
                return
            }
            busy = true
        }
        val tasks = mutableListOf<Task<*>>()
        var candidate: (() -> Candidate?)? = null
        try {
            val input =
                InputImage.fromMediaImage(checkNotNull(image), frame.imageInfo.rotationDegrees)
            val codes = barcode.process(input)
            tasks += codes
            val words = if (stage == Step.OUTER) text.process(input).also { tasks += it } else null
            candidate = {
                (if (codes.isSuccessful)
                    codes.result.firstNotNullOfOrNull {
                        it.rawValue?.let { raw -> Reader.read(raw, stage) }
                    }
                else null)
                    ?: if (words?.isSuccessful == true)
                        Reader.read(words.result.text, stage, ocr = true)
                    else null
            }
        } catch (_: RuntimeException) {
            // Ignore an unusable frame; already-submitted detectors still own its image.
        } finally {
            Tasks.whenAllComplete(tasks).addOnCompleteListener(direct) {
                try {
                    synchronized(this) {
                        if (!closed) candidate?.invoke()?.let { result(stage, it) }
                    }
                } finally {
                    frame.close()
                    synchronized(this) {
                        busy = false
                        if (closed) release()
                    }
                }
            }
        }
    }

    @Synchronized
    override fun close() {
        if (!closed) {
            closed = true
            if (!busy) release()
        }
    }

    private fun release() {
        barcode.close()
        text.close()
    }
}
