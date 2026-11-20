package vn.tad.attend.security

import android.annotation.SuppressLint
import android.content.Context
import android.os.Build
import android.provider.Settings
import java.util.Locale

/**
 * Provides a stable, human-readable Hardware ID bound to the physical Android device
 * and the app's persistent signing key (`Settings.Secure.ANDROID_ID`).
 * Survives every app upgrade and full uninstall/reinstall on Android 8+.
 */
object DeviceIdentity {
    @SuppressLint("HardwareIds")
    fun getHardwareId(context: Context): String {
        val raw = Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID).orEmpty()
        return formatHardwareId(raw)
    }

    fun getDeviceModelLabel(): String {
        val manufacturer = Build.MANUFACTURER.orEmpty().trim()
        val model = Build.MODEL.orEmpty().trim()
        val release = Build.VERSION.RELEASE.orEmpty().trim()
        val name = when {
            manufacturer.isEmpty() -> model
            model.startsWith(manufacturer, ignoreCase = true) -> model
            else -> "${manufacturer.replaceFirstChar { it.uppercase(Locale.US) }} $model"
        }.ifBlank { "Android Device" }
        return if (release.isNotBlank()) "$name (Android $release)" else name
    }

    fun formatHardwareId(rawAndroidId: String): String {
        val clean = rawAndroidId.trim().uppercase(Locale.US).filter { it.isLetterOrDigit() }
        if (clean.isEmpty()) return "AND-UNKNOWN-DEVICE"
        val grouped = clean.chunked(4).joinToString("-")
        return "AND-$grouped"
    }
}
