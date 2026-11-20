package vn.tad.attend

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import vn.tad.attend.ml.FaceMath
import vn.tad.attend.ml.MatchOutcome
import vn.tad.attend.ml.Point2D
import vn.tad.attend.model.EnrolledPerson
import kotlin.math.cos
import kotlin.math.sin
import kotlin.math.sqrt

class FaceMathTest {
    @Test
    fun orderFiveLandmarks_isInvariantToMirroring() {
        val ordered = FaceMath.orderFiveLandmarks(
            eyeA = Point2D(200f, 100f),
            eyeB = Point2D(120f, 102f),
            nose = Point2D(160f, 145f),
            mouthA = Point2D(190f, 190f),
            mouthB = Point2D(130f, 188f),
        )
        assertEquals(120f, ordered[0].x, 0.001f)
        assertEquals(200f, ordered[1].x, 0.001f)
        assertEquals(160f, ordered[2].x, 0.001f)
        assertEquals(130f, ordered[3].x, 0.001f)
        assertEquals(190f, ordered[4].x, 0.001f)
    }

    @Test
    fun estimateSimilarityTransform_recoversExactCanonicalTemplate() {
        val scale = 2.4
        val theta = Math.toRadians(18.0)
        val c = cos(theta)
        val s = sin(theta)
        val tx = 140.0
        val ty = 85.0

        val src = FaceMath.SFACE_TEMPLATE.map { p ->
            val x = scale * (c * p.x - s * p.y) + tx
            val y = scale * (s * p.x + c * p.y) + ty
            Point2D(x.toFloat(), y.toFloat())
        }

        val m = FaceMath.estimateSimilarityTransform(src)
        for (i in src.indices) {
            val mappedX = m[0] * src[i].x + m[1] * src[i].y + m[2]
            val mappedY = m[3] * src[i].x + m[4] * src[i].y + m[5]
            assertEquals(FaceMath.SFACE_TEMPLATE[i].x, mappedX, 0.05f)
            assertEquals(FaceMath.SFACE_TEMPLATE[i].y, mappedY, 0.05f)
        }
    }

    @Test
    fun l2NormalizeAndAverage_produceUnitLengthVector() {
        val v1 = FaceMath.l2Normalize(floatArrayOf(3f, 4f, 0f))
        val v2 = FaceMath.l2Normalize(floatArrayOf(4f, 3f, 0f))
        val avg = FaceMath.averageAndNormalize(listOf(v1, v2))
        val norm = sqrt(avg.sumOf { it * it })
        assertEquals(1.0, norm, 1e-6)
        assertEquals(avg[0], avg[1], 1e-6)
    }

    @Test
    fun matchRoster_enforcesThresholdAndSecondBestMargin() {
        val p1 = EnrolledPerson(1, "NV01", "A", "male", listOf(1.0, 0.0, 0.0))
        val p2 = EnrolledPerson(2, "NV02", "B", "female", listOf(0.95, 0.3122, 0.0))

        // Clear match when only p1 exists
        val single = FaceMath.matchRoster(doubleArrayOf(0.99, 0.141, 0.0), listOf(p1), minScore = 0.42)
        assertTrue(single is MatchOutcome.Matched)

        // Ambiguous when p2 is within 0.08 margin
        val ambiguous = FaceMath.matchRoster(doubleArrayOf(0.98, 0.199, 0.0), listOf(p1, p2), minScore = 0.42)
        assertTrue(ambiguous is MatchOutcome.Uncertain)

        // Below threshold fails safe
        val low = FaceMath.matchRoster(doubleArrayOf(0.30, 0.9539, 0.0), listOf(p1), minScore = 0.42)
        assertTrue(low is MatchOutcome.Uncertain)
    }
}
