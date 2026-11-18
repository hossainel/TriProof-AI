package ai.triproof.scanner.ui

import ai.triproof.scanner.R
import ai.triproof.scanner.crypto.*
import ai.triproof.scanner.databinding.*
import ai.triproof.scanner.scanner.*
import android.Manifest
import android.annotation.SuppressLint
import android.content.pm.PackageManager
import android.location.*
import android.os.*
import android.util.Size
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.camera.core.*
import androidx.camera.core.resolutionselector.ResolutionSelector
import androidx.camera.core.resolutionselector.ResolutionStrategy
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.core.content.ContextCompat
import androidx.core.view.isVisible
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import java.util.concurrent.Executors
import kotlinx.coroutines.flow.receiveAsFlow

class ScannerFragment : Fragment(R.layout.fragment_scanner) {
    private val model: ScannerModel by activityViewModels()
    private var b: FragmentScannerBinding? = null
    private var provider: ProcessCameraProvider? = null
    private var lens: Camera? = null
    private var analyzer: CameraXAnalyzer? = null
    private val executor = Executors.newSingleThreadExecutor()
    private var dialog: AlertDialog? = null
    @Volatile private var manualOpen = false
    private var requestingGps = false
    private var gps: LocationManager? = null
    private val locationListener =
        object : LocationListener {
            override fun onLocationChanged(l: Location) {
                @Suppress("DEPRECATION")
                if (
                    l.isFromMockProvider ||
                        SystemClock.elapsedRealtimeNanos() - l.elapsedRealtimeNanos !in
                            0..300_000_000_000L
                )
                    return
                model.fix(Fix(l.latitude, l.longitude), l.time, l.elapsedRealtimeNanos / 1_000_000L)
            }

            @Deprecated("Legacy callback")
            override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) = Unit

            override fun onProviderEnabled(provider: String) = Unit

            override fun onProviderDisabled(provider: String) = Unit
        }
    private val cameraPermission =
        registerForActivityResult(ActivityResultContracts.RequestPermission()) {
            if (it) startCamera() else context?.toast(R.string.camera_unavailable)
        }
    private val gpsPermission =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
            requestingGps = false
            if (it.values.any { allowed -> allowed }) startGps() else b?.location?.isChecked = false
        }

    private fun allowed(p: String) =
        ContextCompat.checkSelfPermission(requireContext(), p) == PackageManager.PERMISSION_GRANTED

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val v = FragmentScannerBinding.bind(view)
        b = v
        v.enableCamera.setOnClickListener {
            if (allowed(Manifest.permission.CAMERA)) startCamera()
            else cameraPermission.launch(Manifest.permission.CAMERA)
        }
        v.modes.check(
            if (model.capture.value.mode == ScanMode.CONSUMER) R.id.consumer else R.id.factory
        )
        v.modes.addOnButtonCheckedListener { _, id, checked ->
            if (checked) {
                val mode = if (id == R.id.factory) ScanMode.FACTORY else ScanMode.CONSUMER
                if (mode != model.capture.value.mode) model.startSession(mode)
            }
        }
        v.intact.setOnClickListener { model.seal() }
        v.broken.setOnClickListener {
            requireContext().feedback(false)
            requireContext().toast(R.string.broken_message)
            model.reset()
        }
        v.reset.setOnClickListener { model.reset() }
        v.newSession.setOnClickListener {
            model.startSession(ScanMode.FACTORY)
            requireContext().toast(R.string.session_started)
        }
        v.manual.setOnClickListener { manual() }
        v.preview.contentDescription = getString(R.string.focus_hint)
        v.preview.setOnClickListener {
            val camera = lens ?: return@setOnClickListener
            val point =
                v.preview.meteringPointFactory.createPoint(
                    v.preview.width / 2f,
                    v.preview.height / 2f,
                )
            val action =
                FocusMeteringAction.Builder(
                        point,
                        FocusMeteringAction.FLAG_AF or FocusMeteringAction.FLAG_AE,
                    )
                    .build()
            if (camera.cameraInfo.isFocusMeteringSupported(action))
                camera.cameraControl.startFocusAndMetering(action)
        }
        v.torch.setOnClickListener {
            lens?.let {
                it.cameraControl.enableTorch(it.cameraInfo.torchState.value != TorchState.ON)
            }
        }
        v.demo.setOnClickListener {
            if (childFragmentManager.findFragmentByTag("demo") == null)
                DemoSheet().show(childFragmentManager, "demo")
        }
        childFragmentManager.setFragmentResultListener("resultClosed", viewLifecycleOwner) { _, _ ->
            if (model.capture.value.step == Step.RESULT) model.reset()
        }
        childFragmentManager.setFragmentResultListener("demoSelected", viewLifecycleOwner) { _, args
            ->
            ResultSheet.forDemo(args.getString("kind")!!).show(childFragmentManager, "result")
        }
        v.location.setOnCheckedChangeListener { _, on ->
            if (on) enableGps()
            else {
                stopGps()
                model.stopLocation()
            }
        }
        viewLifecycleOwner.watch(model.messages.receiveAsFlow()) { requireContext().toast(it) }
        viewLifecycleOwner.watch(model.capture) { c ->
            v.step.setText(
                when (c.step) {
                    Step.OUTER -> R.string.step_outer
                    Step.SEAL -> R.string.step_seal
                    Step.INNER -> R.string.step_inner
                    else -> R.string.verifying
                }
            )
            v.outer.text = c.outer
            v.outer.isVisible = c.outer.isNotEmpty()
            v.sealCard.isVisible = c.step == Step.SEAL
            v.manual.isEnabled = c.step == Step.OUTER || c.step == Step.INNER
            v.reset.isEnabled = c.step != Step.BUSY
            v.consumer.isEnabled = c.step != Step.BUSY
            v.factory.isEnabled = c.step != Step.BUSY
            v.demo.isEnabled = c.step == Step.OUTER
            v.newSession.isVisible = c.mode == ScanMode.FACTORY
            if (
                c.step == Step.RESULT &&
                    !childFragmentManager.isStateSaved &&
                    childFragmentManager.findFragmentByTag("result") == null
            )
                ResultSheet.forRow(c.resultId, true).show(childFragmentManager, "result")
        }
        if (allowed(Manifest.permission.CAMERA)) startCamera() else v.torch.isEnabled = false
    }

    private fun startCamera() {
        val v = b ?: return
        val ctx = requireContext()
        val main = ContextCompat.getMainExecutor(ctx)
        val future = ProcessCameraProvider.getInstance(ctx)
        future.addListener(
            {
                if (b !== v) return@addListener
                try {
                    val p = future.get()
                    provider = p
                    p.unbindAll()
                    analyzer?.close()
                    val preview =
                        Preview.Builder().build().also {
                            it.surfaceProvider = v.preview.surfaceProvider
                        }
                    val analysis =
                        ImageAnalysis.Builder()
                            .setResolutionSelector(
                                ResolutionSelector.Builder()
                                    .setResolutionStrategy(
                                        ResolutionStrategy(
                                            Size(1280, 720),
                                            ResolutionStrategy
                                                .FALLBACK_RULE_CLOSEST_HIGHER_THEN_LOWER,
                                        )
                                    )
                                    .build()
                            )
                            .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                            .build()
                    val detector =
                        CameraXAnalyzer({
                            if (manualOpen) Step.BUSY else model.capture.value.step
                        }) { step, value ->
                            main.execute {
                                if (
                                    b === v &&
                                        !manualOpen &&
                                        childFragmentManager.fragments.none {
                                            it is androidx.fragment.app.DialogFragment && it.isAdded
                                        }
                                )
                                    model.accept(step, value)
                            }
                        }
                    analyzer = detector
                    analysis.setAnalyzer(executor, detector)
                    val selector =
                        if (p.hasCamera(CameraSelector.DEFAULT_BACK_CAMERA))
                            CameraSelector.DEFAULT_BACK_CAMERA
                        else CameraSelector.DEFAULT_FRONT_CAMERA
                    lens = p.bindToLifecycle(viewLifecycleOwner, selector, preview, analysis)
                    v.enableCamera.isVisible = false
                    v.torch.isEnabled = lens?.cameraInfo?.hasFlashUnit() == true
                } catch (_: Exception) {
                    v.enableCamera.isVisible = true
                    v.torch.isEnabled = false
                    ctx.toast(R.string.camera_unavailable)
                }
            },
            main,
        )
    }

    private fun manual() {
        val step = model.capture.value.step
        manualOpen = true
        val binding = DialogManualBinding.inflate(layoutInflater)
        binding.root.hint =
            getString(if (step == Step.OUTER) R.string.step_outer else R.string.step_inner)
        dialog =
            MaterialAlertDialogBuilder(requireContext())
                .setView(binding.root)
                .setNegativeButton(R.string.cancel, null)
                .setPositiveButton(R.string.continue_action, null)
                .create()
                .apply {
                    setOnDismissListener { manualOpen = false }
                    show()
                    getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener {
                        val raw = binding.input.text.toString().trim()
                        val candidate =
                            Reader.read(raw, step)
                                ?: if (step == Step.INNER && raw.isNotEmpty()) Candidate(raw)
                                else null
                        if (candidate == null)
                            binding.root.error = getString(R.string.invalid_input)
                        else {
                            model.accept(step, candidate)
                            dismiss()
                        }
                    }
                }
    }

    private fun enableGps() {
        if (
            allowed(Manifest.permission.ACCESS_COARSE_LOCATION) ||
                allowed(Manifest.permission.ACCESS_FINE_LOCATION)
        )
            startGps()
        else if (!requestingGps) {
            requestingGps = true
            gpsPermission.launch(
                arrayOf(
                    Manifest.permission.ACCESS_COARSE_LOCATION,
                    Manifest.permission.ACCESS_FINE_LOCATION,
                )
            )
        }
    }

    @SuppressLint("MissingPermission")
    private fun startGps() {
        if (b?.location?.isChecked != true || !isResumed) return
        val lm = requireContext().getSystemService(LocationManager::class.java)
        gps = lm
        val name =
            listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER).firstOrNull {
                (it != LocationManager.GPS_PROVIDER ||
                    allowed(Manifest.permission.ACCESS_FINE_LOCATION)) && lm.isProviderEnabled(it)
            }
        if (name == null) {
            requireContext().toast(R.string.location_unavailable)
            return
        }
        try {
            lm.requestLocationUpdates(name, 15_000L, 10f, locationListener, Looper.getMainLooper())
        } catch (_: SecurityException) {
            b?.location?.isChecked = false
        }
    }

    private fun stopGps() {
        gps?.removeUpdates(locationListener)
        gps = null
    }

    override fun onResume() {
        super.onResume()
        if (b?.location?.isChecked == true && !requestingGps) enableGps()
    }

    override fun onPause() {
        stopGps()
        super.onPause()
    }

    override fun onDestroyView() {
        dialog?.dismiss()
        dialog = null
        provider?.unbindAll()
        analyzer?.close()
        analyzer = null
        lens = null
        b = null
        super.onDestroyView()
    }

    override fun onDestroy() {
        executor.shutdown()
        super.onDestroy()
    }
}
