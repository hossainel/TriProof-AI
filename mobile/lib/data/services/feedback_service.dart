import 'package:audioplayers/audioplayers.dart';
import 'package:flutter/services.dart';
import 'package:vibration/vibration.dart';

/// Abstraction for audio + haptic feedback so tests can inject a no-op stub.
abstract interface class FeedbackService {
  /// Plays a distinct pleasant chime + gentle confirmation haptic for genuine
  /// products.
  Future<void> playGenuineFeedback();

  /// Plays an urgent alarm tone + sharp double-buzz vibration for fake,
  /// mismatch, expired, clone, or recalled verdicts.
  Future<void> playAlertFeedback();

  /// Plays a subtle tactile tick when Step 1 outer label is captured.
  Future<void> playStepCaptureFeedback();
}

/// Hardware audio + haptic implementation using `audioplayers` and `vibration`.
class DeviceFeedbackService implements FeedbackService {
  /// Creates a [DeviceFeedbackService].
  DeviceFeedbackService({AudioPlayer? player})
      : _player = player ?? AudioPlayer();

  final AudioPlayer _player;

  @override
  Future<void> playGenuineFeedback() async {
    try {
      await _player.stop();
      await _player.play(AssetSource('audio/chime_genuine.wav'));
    } catch (_) {
      // Ignore audio errors on headless/muted platforms.
    }

    try {
      final hasVibrator = await Vibration.hasVibrator();
      if (hasVibrator) {
        await Vibration.vibrate(duration: 90, amplitude: 140);
      } else {
        await HapticFeedback.mediumImpact();
      }
    } catch (_) {
      await HapticFeedback.mediumImpact();
    }
  }

  @override
  Future<void> playAlertFeedback() async {
    try {
      await _player.stop();
      await _player.play(AssetSource('audio/alarm_alert.wav'));
    } catch (_) {
      // Ignore audio errors on headless/muted platforms.
    }

    try {
      final hasVibrator = await Vibration.hasVibrator();
      if (hasVibrator) {
        // Sharp double-buzz alarm pattern: wait 0ms, buzz 190ms, pause 90ms, buzz 280ms.
        await Vibration.vibrate(
          pattern: [0, 190, 90, 280],
          intensities: [0, 255, 0, 255],
        );
      } else {
        await HapticFeedback.heavyImpact();
      }
    } catch (_) {
      await HapticFeedback.heavyImpact();
    }
  }

  @override
  Future<void> playStepCaptureFeedback() async {
    try {
      await HapticFeedback.selectionClick();
    } catch (_) {
      // Safe no-op in non-UI environments.
    }
  }
}
