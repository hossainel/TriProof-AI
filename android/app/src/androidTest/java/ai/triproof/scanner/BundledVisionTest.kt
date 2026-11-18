package ai.triproof.scanner

import ai.triproof.scanner.scanner.*
import android.graphics.*
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.google.android.gms.tasks.Tasks
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import java.util.concurrent.TimeUnit
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class BundledVisionTest {
    @get:org.junit.Rule val radios = AirplaneModeRule()

    @Test
    fun decodesInnerQrAndReadsPrintedOuterWithPackagedModels() {
        val size = 512
        val matrix = QRCodeWriter().encode(Demo.INNER, BarcodeFormat.QR_CODE, size, size)
        val qr =
            Bitmap.createBitmap(
                IntArray(size * size) {
                    if (matrix[it % size, it / size]) Color.BLACK else Color.WHITE
                },
                size,
                size,
                Bitmap.Config.ARGB_8888,
            )
        val barcode = BarcodeScanning.getClient()
        try {
            assertEquals(
                Demo.INNER,
                Tasks.await(barcode.process(InputImage.fromBitmap(qr, 0)), 45, TimeUnit.SECONDS)
                    .first()
                    .rawValue,
            )
        } finally {
            barcode.close()
        }
        val label = Bitmap.createBitmap(1700, 140, Bitmap.Config.ARGB_8888)
        Canvas(label).apply {
            drawColor(Color.WHITE)
            drawText(
                Demo.OUTER,
                20f,
                90f,
                Paint(Paint.ANTI_ALIAS_FLAG).apply {
                    textSize = 54f
                    typeface = Typeface.MONOSPACE
                    color = Color.BLACK
                },
            )
        }
        val recognizer = TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS)
        try {
            val text =
                Tasks.await(
                        recognizer.process(InputImage.fromBitmap(label, 0)),
                        45,
                        TimeUnit.SECONDS,
                    )
                    .text
            assertEquals(text, Demo.OUTER, Reader.read(text, Step.OUTER, true)?.text)
        } finally {
            recognizer.close()
        }
    }
}
