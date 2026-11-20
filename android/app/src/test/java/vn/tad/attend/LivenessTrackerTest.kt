package vn.tad.attend

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import vn.tad.attend.ml.LivenessStage
import vn.tad.attend.ml.LivenessTracker

class LivenessTrackerTest {
    @Test
    fun completesChallengeInEitherTurnOrder_andBlocksUntilLeave() {
        val tracker = LivenessTracker()

        val s1 = tracker.onObservation(faceCount = 1, yawDeg = 2f, rollDeg = 0f, faceWidthRatio = 0.3f, nowMs = 100)
        assertEquals(LivenessStage.TURN_FIRST_SIDE, s1.stage)
        assertFalse(s1.shouldCapture)

        val s2 = tracker.onObservation(faceCount = 1, yawDeg = -16f, rollDeg = 0f, faceWidthRatio = 0.3f, nowMs = 300)
        assertEquals(LivenessStage.TURN_OPPOSITE_SIDE, s2.stage)
        assertFalse(s2.shouldCapture)

        val s3 = tracker.onObservation(faceCount = 1, yawDeg = 15f, rollDeg = 0f, faceWidthRatio = 0.3f, nowMs = 600)
        assertEquals(LivenessStage.LOOK_STRAIGHT, s3.stage)
        assertFalse(s3.shouldCapture)

        val s4 = tracker.onObservation(faceCount = 1, yawDeg = 1f, rollDeg = 2f, faceWidthRatio = 0.3f, nowMs = 900)
        assertEquals(LivenessStage.READY_TO_CAPTURE, s4.stage)
        assertTrue(s4.shouldCapture)

        tracker.markCaptured()
        val after = tracker.onObservation(faceCount = 1, yawDeg = 0f, rollDeg = 0f, faceWidthRatio = 0.3f, nowMs = 1200)
        assertEquals(LivenessStage.COOLDOWN_WAIT_LEAVE, after.stage)
        assertFalse(after.shouldCapture)

        // Face leaves for > 1.1s -> re-armed for next employee
        tracker.onObservation(faceCount = 0, nowMs = 2000)
        val reset = tracker.onObservation(faceCount = 0, nowMs = 3200)
        assertEquals(LivenessStage.WAITING_FOR_FACE, reset.stage)

        val next = tracker.onObservation(faceCount = 1, yawDeg = 18f, rollDeg = 0f, faceWidthRatio = 0.3f, nowMs = 3500)
        assertEquals(LivenessStage.TURN_OPPOSITE_SIDE, next.stage)
    }

    @Test
    fun multipleFacesFailsSafeAndResetsProgress() {
        val tracker = LivenessTracker()
        tracker.onObservation(faceCount = 1, yawDeg = -18f, rollDeg = 0f, faceWidthRatio = 0.3f, nowMs = 100)
        val multi = tracker.onObservation(faceCount = 2, nowMs = 300)
        assertEquals(LivenessStage.MULTIPLE_FACES, multi.stage)
        assertFalse(multi.shouldCapture)

        val back = tracker.onObservation(faceCount = 1, yawDeg = 1f, rollDeg = 0f, faceWidthRatio = 0.3f, nowMs = 500)
        assertEquals(LivenessStage.TURN_FIRST_SIDE, back.stage)
    }
}
