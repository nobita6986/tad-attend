package vn.tad.attend.security

import java.security.MessageDigest
import java.security.SecureRandom

object PinHash {
    const val MIN_LENGTH = 6
    private const val SALT_BYTES = 16
    private const val DIGEST_BYTES = 32

    fun hash(pin: String, random: SecureRandom = SecureRandom()): ByteArray {
        require(pin.length >= MIN_LENGTH) { "Mã PIN cần ít nhất $MIN_LENGTH ký tự." }
        val salt = ByteArray(SALT_BYTES).also { random.nextBytes(it) }
        val digest = sha256(salt, pin.toByteArray(Charsets.UTF_8))
        return salt + digest
    }

    fun verify(pin: String, payload: ByteArray): Boolean {
        if (payload.size != SALT_BYTES + DIGEST_BYTES) return false
        val salt = payload.copyOfRange(0, SALT_BYTES)
        val expected = payload.copyOfRange(SALT_BYTES, payload.size)
        val actual = sha256(salt, pin.toByteArray(Charsets.UTF_8))
        var diff = 0
        for (i in expected.indices) {
            diff = diff or (expected[i].toInt() xor actual[i].toInt())
        }
        return diff == 0
    }

    private fun sha256(salt: ByteArray, input: ByteArray): ByteArray {
        val md = MessageDigest.getInstance("SHA-256")
        md.update(salt)
        md.update(input)
        return md.digest()
    }
}
