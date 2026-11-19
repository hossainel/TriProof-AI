import 'package:flutter/material.dart';

/// High-contrast industrial & consumer Material 3 visual tokens.
abstract final class AppTheme {
  /// Deep trusted navy primary brand color.
  static const Color brandNavy = Color(0xFF0A2540);

  /// Emerald verification green for genuine products.
  static const Color genuineGreen = Color(0xFF0E8A4E);

  /// Soft surface tint for genuine verification cards.
  static const Color genuineSurface = Color(0xFFEAF8F0);

  /// Calm azure-teal accent for repeat-owner verification badges.
  static const Color repeatOwnerBlue = Color(0xFF0B6E99);

  /// Soft surface tint for repeat-owner verification cards.
  static const Color repeatOwnerSurface = Color(0xFFE8F4FA);

  /// High-visibility warning red for fake, mismatch, expired, or clone alerts.
  static const Color alertRed = Color(0xFFC62828);

  /// Soft surface tint for red alert cards.
  static const Color alertSurface = Color(0xFFFDECEC);

  /// Amber warning accent for pending sync or cautionary states.
  static const Color warningAmber = Color(0xFFD97706);

  /// Soft surface tint for amber warning banners.
  static const Color warningSurface = Color(0xFFFEF3C7);

  /// Light neutral page background for sunlight readability in factories/customs.
  static const Color pageBackground = Color(0xFFF4F6F9);

  /// Builds the high-contrast light Material 3 theme.
  static ThemeData buildLightTheme() {
    final colorScheme = ColorScheme.fromSeed(
      seedColor: brandNavy,
      brightness: Brightness.light,
      primary: brandNavy,
      secondary: genuineGreen,
      error: alertRed,
      surface: Colors.white,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: pageBackground,
      appBarTheme: const AppBarTheme(
        backgroundColor: brandNavy,
        foregroundColor: Colors.white,
        elevation: 0,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 1.5,
        shadowColor: Colors.black.withValues(alpha: 0.08),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(48, 52),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
          textStyle: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(48, 48),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: Color(0xFFD0D7DE)),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: Color(0xFFD0D7DE)),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: brandNavy, width: 2),
        ),
      ),
    );
  }
}
