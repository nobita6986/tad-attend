package vn.tad.attend

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import vn.tad.attend.ml.LivenessStage
import vn.tad.attend.ml.LivenessTracker

class LivenessTrackerTest {
    @Test
    fun passesWithGentleSingleSideTurn_andBlocksDoublePunchUntilLeave() {
        val tracker = LivenessTracker()

        val s1 = tracker.onObservation(faceCount = 1, yawDeg = 2f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 100)
        assertEquals(LivenessStage.VERIFY_LIVENESS, s1.stage)
        assertFalse(s1.shouldCapture)

        // Single gentle turn >= 9 deg passes liveness immediately
        val s2 = tracker.onObservation(faceCount = 1, yawDeg = 10f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 250)
        assertTrue(s2.livenessVerified)
        assertTrue(s2.shouldCapture) // 10 deg <= 14 deg straight tolerance -> immediate capture!

        tracker.markPunchCompleted()
        val after = tracker.onObservation(faceCount = 1, yawDeg = 0f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 500)
        assertEquals(LivenessStage.COOLDOWN_WAIT_LEAVE, after.stage)
        assertFalse(after.shouldCapture)

        // Face leaves for >= 900ms -> re-armed for next employee
        tracker.onObservation(faceCount = 0, nowMs = 1000)
        val reset = tracker.onObservation(faceCount = 0, nowMs = 2000)
        assertEquals(LivenessStage.WAITING_FOR_FACE, reset.stage)
    }

    @Test
    fun passesWithNaturalBlinkWhileLookingStraight() {
        val tracker = LivenessTracker()

        val open = tracker.onObservation(
            faceCount = 1, yawDeg = 1f, rollDeg = 0f, faceWidthRatio = 0.25f,
            leftEyeOpenProb = 0.90f, rightEyeOpenProb = 0.88f, nowMs = 100,
        )
        assertEquals(LivenessStage.VERIFY_LIVENESS, open.stage)

        val closed = tracker.onObservation(
            faceCount = 1, yawDeg = 1f, rollDeg = 0f, faceWidthRatio = 0.25f,
            leftEyeOpenProb = 0.12f, rightEyeOpenProb = 0.15f, nowMs = 240,
        )
        assertEquals(LivenessStage.VERIFY_LIVENESS, closed.stage)

        val reopened = tracker.onObservation(
            faceCount = 1, yawDeg = 2f, rollDeg = 0f, faceWidthRatio = 0.25f,
            leftEyeOpenProb = 0.85f, rightEyeOpenProb = 0.86f, nowMs = 380,
        )
        assertEquals(LivenessStage.READY_TO_CAPTURE, reopened.stage)
        assertTrue(reopened.shouldCapture)
        assertTrue(reopened.livenessVerified)
    }

    @Test
    fun keepsVerifiedLivenessAcrossBlurryRetries_andResetsWhenBudgetExhausted() {
        val tracker = LivenessTracker(maxCaptureAttempts = 3, retrySpacingMs = 200L)

        // Verify via head turn to 18 deg, then return straight
        val turned = tracker.onObservation(faceCount = 1, yawDeg = -18f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 100)
        assertEquals(LivenessStage.LOOK_STRAIGHT, turned.stage)
        assertTrue(turned.livenessVerified)
        assertFalse(turned.shouldCapture)

        val straight1 = tracker.onObservation(faceCount = 1, yawDeg = 2f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 250)
        assertTrue(straight1.shouldCapture)

        // First attempt blurry -> retains liveness (no need to turn again!)
        assertTrue(tracker.markCaptureAttemptFailed(nowMs = 300))
        val tooSoon = tracker.onObservation(faceCount = 1, yawDeg = 2f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 380)
        assertFalse(tooSoon.shouldCapture) // respects retrySpacingMs

        val retry2 = tracker.onObservation(faceCount = 1, yawDeg = 1f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 550)
        assertTrue(retry2.shouldCapture)
        assertTrue(retry2.livenessVerified)

        // Exhaust attempts -> resets cleanly
        assertTrue(tracker.markCaptureAttemptFailed(nowMs = 600))
        assertFalse(tracker.markCaptureAttemptFailed(nowMs = 900))
        val afterReset = tracker.onObservation(faceCount = 1, yawDeg = 0f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 1000)
        assertEquals(LivenessStage.VERIFY_LIVENESS, afterReset.stage)
        assertFalse(afterReset.livenessVerified)
    }

    @Test
    fun multipleFacesFailsSafeAndResetsProgress() {
        val tracker = LivenessTracker()
        tracker.onObservation(faceCount = 1, yawDeg = -18f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 100)
        val multi = tracker.onObservation(faceCount = 2, nowMs = 300)
        assertEquals(LivenessStage.MULTIPLE_FACES, multi.stage)
        assertFalse(multi.shouldCapture)

        val back = tracker.onObservation(faceCount = 1, yawDeg = 1f, rollDeg = 0f, faceWidthRatio = 0.25f, nowMs = 500)
        assertEquals(LivenessStage.VERIFY_LIVENESS, back.stage)
    }
}
