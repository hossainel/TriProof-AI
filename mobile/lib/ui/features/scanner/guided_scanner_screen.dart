import 'dart:async';
import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:google_mlkit_barcode_scanning/google_mlkit_barcode_scanning.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:triproof_ai_scanner/core/constants/app_theme.dart';
import 'package:triproof_ai_scanner/core/utils/input_image_converter.dart';
import 'package:triproof_ai_scanner/core/utils/ocr_serial_normalizer.dart';
import 'package:triproof_ai_scanner/data/services/feedback_service.dart';
import 'package:triproof_ai_scanner/domain/enums/app_language.dart';
import 'package:triproof_ai_scanner/domain/models/verification_result.dart';
import 'package:triproof_ai_scanner/ui/features/scanner/widgets/scanner_overlay_painter.dart';
import 'package:triproof_ai_scanner/ui/features/scanner/widgets/seal_inspection_dialog.dart';
import 'package:triproof_ai_scanner/ui/view_models/app_view_model.dart';

enum _ScannerStep { outerBox, innerSeal }

/// 100% On-Device 2-Step Guided Vision AI & OCR Scanner combining
/// `camera` + `google_mlkit_barcode_scanning` + `google_mlkit_text_recognition`.
class GuidedScannerScreen extends StatefulWidget {
  /// Creates a [GuidedScannerScreen].
  const GuidedScannerScreen({
    super.key,
    required this.viewModel,
    required this.feedbackService,
  });

  /// Central application ViewModel.
  final AppViewModel viewModel;

  /// Tactile/audio feedback service for step transitions.
  final FeedbackService feedbackService;

  @override
  State<GuidedScannerScreen> createState() => _GuidedScannerScreenState();
}

class _GuidedScannerScreenState extends State<GuidedScannerScreen> {
  CameraController? _cameraController;
  CameraDescription? _activeCamera;

  final BarcodeScanner _outerBarcodeScanner = BarcodeScanner(
    formats: const [BarcodeFormat.all],
  );
  final BarcodeScanner _innerBarcodeScanner = BarcodeScanner(
    formats: const [
      BarcodeFormat.qrCode,
      BarcodeFormat.dataMatrix,
      BarcodeFormat.aztec,
      BarcodeFormat.pdf417,
    ],
  );
  final TextRecognizer _textRecognizer = TextRecognizer(
    script: TextRecognitionScript.latin,
  );

  _ScannerStep _step = _ScannerStep.outerBox;
  bool _cameraReady = false;
  String? _cameraError;
  bool _torchOn = false;
  double _currentZoom = 1.0;
  double _minZoom = 1.0;
  double _maxZoom = 1.0;

  bool _isProcessingFrame = false;
  bool _isDialogOpen = false;
  int _frameCounter = 0;

  String? _capturedOuterSerial;
  String _liveOcrPreview = '';

  @override
  void initState() {
    super.initState();
    unawaited(_initCamera());
  }

  Future<void> _initCamera() async {
    try {
      final permissionStatus = await Permission.camera.request();
      if (!permissionStatus.isGranted) {
        if (!mounted) return;
        setState(() {
          _cameraError = widget.viewModel.language == AppLanguage.bangla
              ? 'ক্যামেরার অনুমতি দেওয়া হয়নি। ম্যানুয়াল সিরিয়াল লিখুন অথবা সেটিংস থেকে অনুমতি দিন।'
              : 'Camera permission denied. Use Manual Serial entry or grant permission in Settings.';
        });
        return;
      }

      final cameras = await availableCameras();
      if (cameras.isEmpty) {
        if (!mounted) return;
        setState(() {
          _cameraError = widget.viewModel.language == AppLanguage.bangla
              ? 'কোনো ক্যামেরা পাওয়া যায়নি। ম্যানুয়াল সিরিয়াল ব্যবহার করুন।'
              : 'No camera hardware detected. Use Manual Serial entry below.';
        });
        return;
      }

      final rearCamera = cameras.firstWhere(
        (c) => c.lensDirection == CameraLensDirection.back,
        orElse: () => cameras.first,
      );
      _activeCamera = rearCamera;

      final controller = CameraController(
        rearCamera,
        ResolutionPreset.high,
        enableAudio: false,
        imageFormatGroup: Platform.isAndroid
            ? ImageFormatGroup.nv21
            : ImageFormatGroup.bgra8888,
      );

      await controller.initialize();
      _minZoom = await controller.getMinZoomLevel();
      _maxZoom = (await controller.getMaxZoomLevel())
          .clamp(1.0, 4.0)
          .toDouble();
      _currentZoom = _minZoom;

      if (!mounted) {
        await controller.dispose();
        return;
      }

      _cameraController = controller;
      await controller.startImageStream(_onCameraFrame);
      setState(() {
        _cameraReady = true;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _cameraError = 'Camera initialization unavailable: $e';
      });
    }
  }

  Future<void> _onCameraFrame(CameraImage image) async {
    if (_isProcessingFrame || _isDialogOpen || !mounted) return;
    final controller = _cameraController;
    final camera = _activeCamera;
    if (controller == null || camera == null) return;

    _isProcessingFrame = true;
    _frameCounter++;

    try {
      final inputImage = InputImageConverter.fromCameraImage(
        image: image,
        controller: controller,
        camera: camera,
      );
      if (inputImage == null) return;

      if (_step == _ScannerStep.outerBox) {
        await _handleOuterFrame(inputImage);
      } else {
        await _handleInnerFrame(inputImage);
      }
    } catch (_) {
      // Frame errors should never crash live scanner stream.
    } finally {
      _isProcessingFrame = false;
    }
  }

  Future<void> _handleOuterFrame(InputImage inputImage) async {
    // 1. Fast Barcode/QR detection on outer carton.
    final barcodes = await _outerBarcodeScanner.processImage(inputImage);
    for (final barcode in barcodes) {
      final raw = barcode.rawValue;
      if (raw == null || raw.isEmpty) continue;
      final normalized = OcrSerialNormalizer.extractAndNormalize(raw);
      if (normalized != null) {
        await _onOuterSerialCaptured(normalized);
        return;
      }
    }

    // 2. Fallback/parallel On-Device OCR for degraded/curved printed labels.
    if (_frameCounter % 2 == 0) {
      final recognized = await _textRecognizer.processImage(inputImage);
      final rawText = recognized.text.trim();
      if (rawText.isNotEmpty && mounted) {
        setState(() {
          _liveOcrPreview = rawText.replaceAll('\n', ' ');
        });
      }

      final normalized = OcrSerialNormalizer.extractAndNormalize(rawText);
      if (normalized != null) {
        await _onOuterSerialCaptured(normalized);
      }
    }
  }

  Future<void> _handleInnerFrame(InputImage inputImage) async {
    final barcodes = await _innerBarcodeScanner.processImage(inputImage);
    for (final barcode in barcodes) {
      final raw = barcode.rawValue;
      if (raw == null || raw.trim().isEmpty) continue;
      final cleanSig = OcrSerialNormalizer.normalizeInnerSignature(raw);
      // Ed25519 64-byte Base64 signatures are ~86-88 chars; reject tiny noise.
      if (cleanSig.length >= 60) {
        await _onInnerSerialCaptured(cleanSig);
        return;
      }
    }
  }

  Future<void> _onOuterSerialCaptured(String outerSerial) async {
    if (_isDialogOpen || !mounted) return;
    _isDialogOpen = true;
    await _pauseStreamSafely();
    await widget.feedbackService.playStepCaptureFeedback();

    if (!mounted) return;
    setState(() {
      _capturedOuterSerial = outerSerial;
    });

    final sealConfirmed = await SealInspectionDialog.show(
      context,
      capturedOuterSerial: outerSerial,
      language: widget.viewModel.language,
    );

    if (!mounted) return;
    _isDialogOpen = false;

    if (sealConfirmed == true) {
      setState(() {
        _step = _ScannerStep.innerSeal;
        _liveOcrPreview = '';
      });
      await _resumeStreamSafely();
    } else if (sealConfirmed == false) {
      await _handleTamperedSealWarning();
    } else {
      // Cancelled; reset to Step 1.
      setState(() {
        _capturedOuterSerial = null;
        _step = _ScannerStep.outerBox;
      });
      await _resumeStreamSafely();
    }
  }

  Future<void> _handleTamperedSealWarning() async {
    final isBn = widget.viewModel.language == AppLanguage.bangla;
    if (!mounted) return;

    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(
          isBn ? 'ট্যাম্পার সিল সতর্কতা' : 'Tampered Seal Warning',
          style: const TextStyle(
            color: AppTheme.alertRed,
            fontWeight: FontWeight.w900,
          ),
        ),
        content: Text(
          isBn
              ? 'ভেতরের স্ক্র্যাচ-অফ বা মাইক্রো-ডট সিল আগে থেকেই ভাঙা থাকলে পণ্যটি গ্রহণ বা ব্যবহার করবেন না। এটি রিফিল করা নকল পণ্য হতে পারে।'
              : 'Do NOT accept or consume a unit whose tamper-evident seal or micro-dot was already broken prior to opening. Return or quarantine the carton.',
        ),
        actions: [
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(),
            style: FilledButton.styleFrom(backgroundColor: AppTheme.alertRed),
            child: Text(isBn ? 'বুঝেছি' : 'Understood'),
          ),
        ],
      ),
    );

    if (mounted) {
      Navigator.of(context).pop();
    }
  }

  Future<void> _onInnerSerialCaptured(String innerSerial) async {
    if (_isDialogOpen || _capturedOuterSerial == null || !mounted) return;
    _isDialogOpen = true;
    await _pauseStreamSafely();

    final VerificationResult result = await widget.viewModel.verifyCapturedPair(
      outerSerial: _capturedOuterSerial!,
      innerSerial: innerSerial,
    );

    if (!mounted) return;
    Navigator.of(context).pop(result);
  }

  Future<void> _pauseStreamSafely() async {
    final controller = _cameraController;
    if (controller != null && controller.value.isStreamingImages) {
      try {
        await controller.stopImageStream();
      } catch (_) {
        // Ignore stream state race.
      }
    }
  }

  Future<void> _resumeStreamSafely() async {
    final controller = _cameraController;
    if (controller != null &&
        controller.value.isInitialized &&
        !controller.value.isStreamingImages) {
      try {
        await controller.startImageStream(_onCameraFrame);
      } catch (_) {
        // Ignore stream state race.
      }
    }
  }

  Future<void> _toggleTorch() async {
    final controller = _cameraController;
    if (controller == null || !controller.value.isInitialized) return;
    final next = !_torchOn;
    try {
      await controller.setFlashMode(next ? FlashMode.torch : FlashMode.off);
      setState(() => _torchOn = next);
    } catch (_) {
      // Device lacks torch.
    }
  }

  Future<void> _openManualEntrySheet() async {
    await _pauseStreamSafely();
    if (!mounted) return;

    final isBn = widget.viewModel.language == AppLanguage.bangla;
    final outerCtrl = TextEditingController(text: _capturedOuterSerial ?? '');
    final innerCtrl = TextEditingController();

    final submitted = await showModalBottomSheet<({String outer, String inner})>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
      ),
      builder: (ctx) => Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          18,
          20,
          MediaQuery.viewInsetsOf(ctx).bottom + 24,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              isBn ? 'ম্যানুয়াল সিরিয়াল যাচাই' : 'Manual / Paste Verification',
              style: const TextStyle(
                fontSize: 17,
                fontWeight: FontWeight.w900,
                color: AppTheme.brandNavy,
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: outerCtrl,
              decoration: InputDecoration(
                labelText: isBn
                    ? 'বাইরের বক্সের সিরিয়াল ({Factory}-{Machine}-{Batch}-{Unit}|{Prod}|{Exp})'
                    : 'Outer Serial ({Factory}-{Machine}-{Batch}-{Unit}|{Prod}|{Exp})',
              ),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: innerCtrl,
              decoration: InputDecoration(
                labelText: isBn
                    ? 'ভেতরের প্রাইভেট সিল সিগনেচার (Base64)'
                    : 'Inner Private Serial (Base64 Ed25519 Signature)',
              ),
              maxLines: 2,
            ),
            const SizedBox(height: 14),
            FilledButton(
              onPressed: () {
                Navigator.of(ctx).pop((
                  outer: outerCtrl.text.trim(),
                  inner: innerCtrl.text.trim(),
                ));
              },
              child: Text(isBn ? 'অফলাইনে যাচাই করুন' : 'Verify Offline (<50ms)'),
            ),
          ],
        ),
      ),
    );

    if (submitted != null &&
        submitted.outer.isNotEmpty &&
        submitted.inner.isNotEmpty &&
        mounted) {
      final result = await widget.viewModel.verifyCapturedPair(
        outerSerial: submitted.outer,
        innerSerial: submitted.inner,
      );
      if (mounted) {
        Navigator.of(context).pop(result);
      }
      return;
    }

    if (mounted) {
      await _resumeStreamSafely();
    }
  }

  @override
  void dispose() {
    unawaited(_pauseStreamSafely());
    unawaited(_cameraController?.dispose());
    unawaited(_outerBarcodeScanner.close());
    unawaited(_innerBarcodeScanner.close());
    unawaited(_textRecognizer.close());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.viewModel.strings;
    final isStep1 = _step == _ScannerStep.outerBox;
    final stepTitle = isStep1 ? s.step1Title : s.step2Title;
    final stepInstruction = isStep1 ? s.step1Instruction : s.step2Instruction;

    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        title: Text(stepTitle),
        actions: [
          IconButton(
            icon: Icon(_torchOn ? Icons.flash_on : Icons.flash_off),
            tooltip: 'Toggle Flashlight',
            onPressed: _toggleTorch,
          ),
        ],
      ),
      body: Stack(
        fit: StackFit.expand,
        children: [
          if (_cameraReady && _cameraController != null)
            CameraPreview(_cameraController!)
          else if (_cameraError != null)
            Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(
                      Icons.videocam_off_rounded,
                      color: Colors.white70,
                      size: 56,
                    ),
                    const SizedBox(height: 12),
                    Text(
                      _cameraError!,
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: Colors.white, fontSize: 15),
                    ),
                  ],
                ),
              ),
            )
          else
            const Center(
              child: CircularProgressIndicator(color: Colors.white),
            ),

          // Reticle & step overlay
          IgnorePointer(
            child: CustomPaint(
              painter: ScannerOverlayPainter(
                borderColor:
                    isStep1 ? const Color(0xFF22C55E) : const Color(0xFF38BDF8),
                isWideOcrBox: isStep1,
              ),
            ),
          ),

          // Top instruction card + Step progress pills
          Positioned(
            top: 14,
            left: 14,
            right: 14,
            child: Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.black.withValues(alpha: 0.72),
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: Colors.white24),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      _stepPill('1', active: isStep1, done: !isStep1),
                      const SizedBox(width: 6),
                      const Icon(
                        Icons.arrow_forward,
                        color: Colors.white54,
                        size: 14,
                      ),
                      const SizedBox(width: 6),
                      _stepPill('2', active: !isStep1, done: false),
                      const Spacer(),
                      const Text(
                        'ML Kit On-Device AI',
                        style: TextStyle(
                          color: Color(0xFF4ADE80),
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    stepInstruction,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 12.5,
                      height: 1.35,
                    ),
                  ),
                  if (_liveOcrPreview.isNotEmpty && isStep1) ...[
                    const SizedBox(height: 6),
                    Text(
                      'OCR: $_liveOcrPreview',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.amberAccent,
                        fontFamily: 'monospace',
                        fontSize: 11,
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),

          // Bottom controls: zoom slider + manual entry fallback
          Positioned(
            bottom: 22,
            left: 16,
            right: 16,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (_cameraReady && _maxZoom > _minZoom)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: 0.65),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Row(
                      children: [
                        const Icon(
                          Icons.zoom_in_rounded,
                          color: Colors.white70,
                          size: 20,
                        ),
                        Expanded(
                          child: Slider(
                            value: _currentZoom,
                            min: _minZoom,
                            max: _maxZoom,
                            activeColor: Colors.white,
                            onChanged: (val) async {
                              setState(() => _currentZoom = val);
                              await _cameraController?.setZoomLevel(val);
                            },
                          ),
                        ),
                        Text(
                          '${_currentZoom.toStringAsFixed(1)}x',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ],
                    ),
                  ),
                const SizedBox(height: 10),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _openManualEntrySheet,
                    style: FilledButton.styleFrom(
                      backgroundColor: Colors.white.withValues(alpha: 0.18),
                      foregroundColor: Colors.white,
                    ),
                    icon: const Icon(Icons.keyboard_alt_outlined),
                    label: Text(s.manualEntryButton),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _stepPill(String num, {required bool active, required bool done}) {
    final bg = done
        ? AppTheme.genuineGreen
        : (active ? Colors.white : Colors.white24);
    final fg = done
        ? Colors.white
        : (active ? AppTheme.brandNavy : Colors.white70);
    return Container(
      width: 24,
      height: 24,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
      child: done
          ? const Icon(Icons.check, size: 15, color: Colors.white)
          : Text(
              num,
              style: TextStyle(
                color: fg,
                fontWeight: FontWeight.w900,
                fontSize: 12,
              ),
            ),
    );
  }
}
