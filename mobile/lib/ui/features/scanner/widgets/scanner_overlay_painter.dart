import 'package:flutter/material.dart';

/// Draws a high-contrast scanning reticle with darkened letterbox surrounding
/// the active barcode/OCR target box.
class ScannerOverlayPainter extends CustomPainter {
  /// Creates a [ScannerOverlayPainter].
  const ScannerOverlayPainter({
    required this.borderColor,
    required this.isWideOcrBox,
  });

  /// Color of the corner brackets (`green` for Step 1, `amber/cyan` for Step 2).
  final Color borderColor;

  /// When `true` (Step 1), renders a wider rectangular window optimized for
  /// curved text/barcodes; when `false` (Step 2), renders a square QR window.
  final bool isWideOcrBox;

  @override
  void paint(Canvas canvas, Size size) {
    final boxWidth = isWideOcrBox ? size.width * 0.84 : size.width * 0.66;
    final boxHeight = isWideOcrBox ? 165.0 : boxWidth;
    final left = (size.width - boxWidth) / 2;
    final top = (size.height - boxHeight) / 2 - 20;
    final cutout = RRect.fromRectAndRadius(
      Rect.fromLTWH(left, top, boxWidth, boxHeight),
      const Radius.circular(18),
    );

    final backgroundPath = Path()
      ..addRect(Rect.fromLTWH(0, 0, size.width, size.height));
    final cutoutPath = Path()..addRRect(cutout);
    final overlayPath = Path.combine(
      PathOperation.difference,
      backgroundPath,
      cutoutPath,
    );

    canvas.drawPath(
      overlayPath,
      Paint()..color = Colors.black.withValues(alpha: 0.58),
    );

    // Subtle full perimeter line
    canvas.drawRRect(
      cutout,
      Paint()
        ..color = borderColor.withValues(alpha: 0.4)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5,
    );

    // Bold high-contrast corner brackets
    final cornerPaint = Paint()
      ..color = borderColor
      ..style = PaintingStyle.stroke
      ..strokeWidth = 4.5
      ..strokeCap = StrokeCap.round;

    const cornerLen = 28.0;
    final rect = cutout.outerRect;

    // Top-left
    canvas.drawLine(
      Offset(rect.left, rect.top + cornerLen),
      Offset(rect.left, rect.top + 8),
      cornerPaint,
    );
    canvas.drawLine(
      Offset(rect.left + 8, rect.top),
      Offset(rect.left + cornerLen, rect.top),
      cornerPaint,
    );

    // Top-right
    canvas.drawLine(
      Offset(rect.right - cornerLen, rect.top),
      Offset(rect.right - 8, rect.top),
      cornerPaint,
    );
    canvas.drawLine(
      Offset(rect.right, rect.top + 8),
      Offset(rect.right, rect.top + cornerLen),
      cornerPaint,
    );

    // Bottom-left
    canvas.drawLine(
      Offset(rect.left, rect.bottom - cornerLen),
      Offset(rect.left, rect.bottom - 8),
      cornerPaint,
    );
    canvas.drawLine(
      Offset(rect.left + 8, rect.bottom),
      Offset(rect.left + cornerLen, rect.bottom),
      cornerPaint,
    );

    // Bottom-right
    canvas.drawLine(
      Offset(rect.right - cornerLen, rect.bottom),
      Offset(rect.right - 8, rect.bottom),
      cornerPaint,
    );
    canvas.drawLine(
      Offset(rect.right, rect.bottom - 8),
      Offset(rect.right, rect.bottom - cornerLen),
      cornerPaint,
    );
  }

  @override
  bool shouldRepaint(covariant ScannerOverlayPainter oldDelegate) {
    return oldDelegate.borderColor != borderColor ||
        oldDelegate.isWideOcrBox != isWideOcrBox;
  }
}
