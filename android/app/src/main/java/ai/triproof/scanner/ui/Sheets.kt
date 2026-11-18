package ai.triproof.scanner.ui

import ai.triproof.scanner.R
import ai.triproof.scanner.crypto.Verdict
import ai.triproof.scanner.data.local.ScanHistoryEntity
import ai.triproof.scanner.databinding.*
import ai.triproof.scanner.scanner.*
import ai.triproof.scanner.util.bangla
import android.app.Application
import android.app.Dialog
import android.content.DialogInterface
import android.graphics.Bitmap
import android.graphics.Color
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.os.bundleOf
import androidx.core.view.isVisible
import androidx.fragment.app.DialogFragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.lifecycleScope
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.zxing.BarcodeFormat
import com.google.zxing.qrcode.QRCodeWriter
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.*

class DemoSheet : BottomSheetDialogFragment() {
    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?,
    ): View =
        BottomSheetDemoSimulatorBinding.inflate(inflater, container, false).run {
            listOf(genuine to "genuine", expired to "expired", forged to "forged").forEach {
                (btn, kind) ->
                btn.setOnClickListener {
                    parentFragmentManager.setFragmentResult(
                        "demoSelected",
                        bundleOf("kind" to kind),
                    )
                    dismiss()
                }
            }
            root
        }
}

class ResultModel(app: Application, state: SavedStateHandle) : GraphModel(app) {
    val demo: String? = state["demo"]
    val row = flow {
        if (demo != null) emit(Demo.sample(demo))
        else
            emitAll(
                graph
                    .await()
                    .db
                    .history()
                    .observe(checkNotNull(state.get<Long>("id")))
                    .filterNotNull()
            )
    }
    val qr = MutableStateFlow<String?>(null)
    val busy = MutableStateFlow(false)

    fun issue(row: ScanHistoryEntity) {
        if (busy.value) return
        busy.value = true
        launch {
            try {
                if (
                    !scanner.online.value ||
                        graph.await().keys.token.value == null ||
                        row.cloudStatus !in setOf("verified_clean", "owner_repeat_ok")
                )
                    messages.send(R.string.handoff_wait)
                else
                    qr.value =
                        Reader.handoff(row.outerSerial, graph.await().repository.handoff(row))
            } finally {
                busy.value = false
            }
        }
    }
}

class ResultSheet : BottomSheetDialogFragment() {
    private val model: ResultModel by viewModels()

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?,
    ): View = BottomSheetVerificationResultBinding.inflate(inflater, container, false).root

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val b = BottomSheetVerificationResultBinding.bind(view)
        var buzz = savedInstanceState == null && requireArguments().getBoolean("feedback")
        b.demoLabel.isVisible = model.demo != null
        b.transfer.isVisible = false
        b.close.setOnClickListener { dismiss() }
        viewLifecycleOwner.watch(model.messages.receiveAsFlow()) { requireContext().toast(it) }
        viewLifecycleOwner.watch(model.busy) { b.transfer.isEnabled = !it }
        viewLifecycleOwner.watch(model.qr) {
            if (it != null && !childFragmentManager.isStateSaved) {
                QrDialog()
                    .apply { arguments = bundleOf("qr" to it) }
                    .show(childFragmentManager, "qr")
                model.qr.value = null
            }
        }
        viewLifecycleOwner.watch(model.row) { row ->
            val good = Verdict.positive(row.offlineStatus) && !cloudAlarm(row.cloudStatus)
            if (buzz) {
                requireContext().feedback(good)
                buzz = false
            }
            b.verdict.setText(
                if (cloudAlarm(row.cloudStatus)) cloudText(row.cloudStatus)
                else offlineText(row.offlineStatus)
            )
            b.verdict.setBackgroundColor(
                requireContext()
                    .getColor(
                        if (!good) R.color.bad
                        else if (row.offlineStatus == Verdict.REPEAT) R.color.blue else R.color.good
                    )
            )
            b.message.text =
                if (cloudAlarm(row.cloudStatus))
                    (if (requireContext().bangla()) row.feedbackMessageBn
                    else row.feedbackMessageEn)
                else
                    when (row.offlineStatus) {
                        Verdict.REPEAT ->
                            getString(
                                R.string.repeat_msg,
                                date(row.previousVerifiedAt ?: row.scannedAt),
                            )
                        else ->
                            getString(
                                when (row.offlineStatus) {
                                    Verdict.GENUINE -> R.string.genuine_msg
                                    Verdict.EXPIRED -> R.string.expired_msg
                                    Verdict.DUPLICATE -> R.string.duplicate_msg
                                    Verdict.CLOCK -> R.string.clock_msg
                                    Verdict.BLOCKED -> R.string.blacklist_msg
                                    else -> R.string.fake_msg
                                }
                            )
                    }
            b.serial.text = row.outerSerial
            b.details.text =
                getString(
                    R.string.label_details,
                    row.factoryId ?: "—",
                    row.machineId ?: "—",
                    row.batchId ?: "—",
                    row.prodDate ?: "—",
                    row.expDate ?: "—",
                    row.unitHash.take(20) + "…",
                )
            b.cloud.setText(cloudText(row.cloudStatus))
            b.transfer.isVisible = good && model.demo == null
            b.transfer.setOnClickListener { model.issue(row) }
        }
    }

    override fun onDismiss(dialog: DialogInterface) {
        super.onDismiss(dialog)
        if (isAdded) parentFragmentManager.setFragmentResult("resultClosed", Bundle())
    }

    companion object {
        fun forDemo(kind: String) =
            ResultSheet().apply { arguments = bundleOf("demo" to kind, "feedback" to true) }

        fun forRow(id: Long, feedback: Boolean = false) =
            ResultSheet().apply { arguments = bundleOf("id" to id, "feedback" to feedback) }
    }
}

class QrDialog : DialogFragment() {
    override fun onCreateDialog(savedInstanceState: Bundle?): Dialog {
        val b = DialogTransferBinding.inflate(layoutInflater)
        val payload = requireArguments().getString("qr")!!
        lifecycleScope.launch {
            val bitmap =
                withContext(Dispatchers.Default) {
                    val n = 512
                    val matrix = QRCodeWriter().encode(payload, BarcodeFormat.QR_CODE, n, n)
                    Bitmap.createBitmap(
                        IntArray(n * n) {
                            if (matrix[it % n, it / n]) Color.BLACK else Color.WHITE
                        },
                        n,
                        n,
                        Bitmap.Config.ARGB_8888,
                    )
                }
            b.qr.setImageBitmap(bitmap)
        }
        return MaterialAlertDialogBuilder(requireContext())
            .setTitle(R.string.transfer)
            .setView(b.root)
            .setPositiveButton(R.string.close, null)
            .create()
    }
}
