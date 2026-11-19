# Required for sqflite_sqlcipher in release builds
-keep class net.sqlcipher.** { *; }
-keep class net.sqlcipher.database.** { *; }

# Keep Google ML Kit on-device vision classes
-keep class com.google.mlkit.** { *; }
-keep class com.google.android.gms.internal.mlkit_vision_barcode.** { *; }
-keep class com.google.android.gms.internal.mlkit_vision_text_common.** { *; }

# Optional ML Kit text-recognition language modules are not bundled with the
# base Latin model; suppress R8 missing-class errors for unused scripts.
-dontwarn com.google.mlkit.vision.text.chinese.**
-dontwarn com.google.mlkit.vision.text.japanese.**
-dontwarn com.google.mlkit.vision.text.korean.**
-dontwarn com.google.mlkit.vision.text.devanagari.**
-dontwarn com.google.mlkit.vision.text.bengali.**
-dontwarn com.google.mlkit.vision.text.telugu.**
-dontwarn com.google.mlkit.vision.text.tamil.**
-dontwarn com.google.mlkit.vision.text.gujarati.**
-dontwarn com.google.mlkit.vision.text.kannada.**
-dontwarn com.google.mlkit.vision.text.malayalam.**
-dontwarn com.google.mlkit.vision.text.odia.**
-dontwarn com.google.mlkit.vision.text.punjabi.**
-dontwarn com.google.mlkit.vision.text.thai.**
-dontwarn com.google.mlkit.vision.text.arabic.**
-dontwarn com.google.mlkit.vision.text.hebrew.**
-dontwarn com.google.mlkit.vision.text.urdu.**
