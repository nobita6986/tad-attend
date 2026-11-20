package vn.tad.attend

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import vn.tad.attend.security.PinHash

class PinHashTest {
    @Test
    fun hashesAndVerifiesPinWithRandomSalt() {
        val h1 = PinHash.hash("123456")
        val h2 = PinHash.hash("123456")
        assertFalse(h1.contentEquals(h2))
        assertTrue(PinHash.verify("123456", h1))
        assertTrue(PinHash.verify("123456", h2))
        assertFalse(PinHash.verify("654321", h1))
    }

    @Test(expected = IllegalArgumentException::class)
    fun rejectsShortPin() {
        PinHash.hash("12345")
    }
}
