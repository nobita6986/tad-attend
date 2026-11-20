package vn.tad.attend.security

import android.annotation.SuppressLint
import android.content.Context
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

    fun formatHardwareId(rawAndroidId: String): String {
        val clean = rawAndroidId.trim().uppercase(Locale.US).filter { it.isLetterOrDigit() }
        if (clean.isEmpty()) return "AND-UNKNOWN-DEVICE"
        val grouped = clean.chunked(4).joinToString("-")
        return "AND-$grouped"
    }
}
