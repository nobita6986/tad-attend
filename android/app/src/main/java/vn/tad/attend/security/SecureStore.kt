package vn.tad.attend.security

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import vn.tad.attend.BuildConfig
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Stores server URL, Android Keystore AES-256-GCM encrypted device bearer token,
 * and salted SHA-256 admin PIN hash. Biometric templates are NEVER written here.
 */
class SecureStore(context: Context) {
    private val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun getServerUrl(): String =
        prefs.getString(KEY_SERVER_URL, BuildConfig.DEFAULT_SERVER_URL)
            ?.trim()
            .orEmpty()
            .ifEmpty { BuildConfig.DEFAULT_SERVER_URL }

    fun setServerUrl(url: String) {
        prefs.edit().putString(KEY_SERVER_URL, url.trim().trimEnd('/')).apply()
    }

    fun hasDeviceToken(): Boolean = !getDeviceToken().isNullOrBlank()

    fun getDeviceToken(): String? {
        val encoded = prefs.getString(KEY_TOKEN_CIPHERTEXT, null) ?: return null
        return runCatching {
            val raw = Base64.decode(encoded, Base64.NO_WRAP)
            if (raw.size <= GCM_IV_LENGTH) return null
            val iv = raw.copyOfRange(0, GCM_IV_LENGTH)
            val cipherBytes = raw.copyOfRange(GCM_IV_LENGTH, raw.size)
            val cipher = Cipher.getInstance(TRANSFORMATION)
            cipher.init(Cipher.DECRYPT_MODE, getOrCreateSecretKey(), GCMParameterSpec(GCM_TAG_BITS, iv))
            String(cipher.doFinal(cipherBytes), Charsets.UTF_8)
        }.getOrNull()
    }

    fun setDeviceToken(token: String) {
        val clean = token.trim()
        if (clean.isEmpty()) return
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateSecretKey())
        val iv = cipher.iv
        val encrypted = cipher.doFinal(clean.toByteArray(Charsets.UTF_8))
        val combined = ByteArray(iv.size + encrypted.size)
        System.arraycopy(iv, 0, combined, 0, iv.size)
        System.arraycopy(encrypted, 0, combined, iv.size, encrypted.size)
        val encoded = Base64.encodeToString(combined, Base64.NO_WRAP)
        prefs.edit().putString(KEY_TOKEN_CIPHERTEXT, encoded).apply()
    }

    fun hasAdminPin(): Boolean = !prefs.getString(KEY_ADMIN_PIN_HASH, null).isNullOrBlank()

    fun saveAdminPin(pin: String): Boolean {
        if (pin.length < PinHash.MIN_LENGTH) return false
        val encoded = Base64.encodeToString(PinHash.hash(pin), Base64.NO_WRAP)
        prefs.edit().putString(KEY_ADMIN_PIN_HASH, encoded).apply()
        return true
    }

    fun verifyAdminPin(pin: String): Boolean {
        val encoded = prefs.getString(KEY_ADMIN_PIN_HASH, null) ?: return false
        val bytes = runCatching { Base64.decode(encoded, Base64.NO_WRAP) }.getOrNull() ?: return false
        return PinHash.verify(pin, bytes)
    }

    private fun getOrCreateSecretKey(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        val existing = keyStore.getKey(KEYSTORE_ALIAS, null) as? SecretKey
        if (existing != null) return existing

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE)
        val spec = KeyGenParameterSpec.Builder(
            KEYSTORE_ALIAS,
            KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
        )
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setKeySize(256)
            .build()
        generator.init(spec)
        return generator.generateKey()
    }

    companion object {
        private const val PREFS_NAME = "tad_attend_secure_prefs"
        private const val KEY_SERVER_URL = "server_url"
        private const val KEY_TOKEN_CIPHERTEXT = "device_token_gcm"
        private const val KEY_ADMIN_PIN_HASH = "admin_pin_hash"
        private const val ANDROID_KEYSTORE = "AndroidKeyStore"
        private const val KEYSTORE_ALIAS = "tad_attend_device_token_v1"
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
        private const val GCM_IV_LENGTH = 12
        private const val GCM_TAG_BITS = 128
    }
}
