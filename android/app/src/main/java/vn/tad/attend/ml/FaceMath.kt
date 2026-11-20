package vn.tad.attend.ml

import vn.tad.attend.model.EnrolledPerson
import kotlin.math.sqrt

data class Point2D(val x: Float, val y: Float)

data class MatchCandidate(
    val person: EnrolledPerson,
    val score: Double,
)

sealed class MatchOutcome {
    data class Matched(val candidate: MatchCandidate, val margin: Double?) : MatchOutcome()
    data class Uncertain(val bestScore: Double?, val margin: Double?) : MatchOutcome()
}

object FaceMath {
    const val INPUT_SIZE = 112
    const val MIN_SECOND_BEST_MARGIN = 0.08

    /**
     * Canonical 5-point ArcFace/SFace reference landmarks in 112x112 space
     * (exact constants from OpenCV FaceRecognizerSFImpl::getSimilarityTransformMatrix):
     * 0 = image-left eye, 1 = image-right eye, 2 = nose tip,
     * 3 = image-left mouth corner, 4 = image-right mouth corner.
     */
    val SFACE_TEMPLATE: List<Point2D> = listOf(
        Point2D(38.2946f, 51.6963f),
        Point2D(73.5318f, 51.5014f),
        Point2D(56.0252f, 71.7366f),
        Point2D(41.5493f, 92.3655f),
        Point2D(70.7299f, 92.2041f),
    )

    /**
     * Orders detected landmarks into image-left/image-right pairs so alignment is
     * invariant to front-camera mirroring and ML Kit anatomical left/right conventions.
     */
    fun orderFiveLandmarks(
        eyeA: Point2D,
        eyeB: Point2D,
        nose: Point2D,
        mouthA: Point2D,
        mouthB: Point2D,
    ): List<Point2D> {
        val (imgLeftEye, imgRightEye) = if (eyeA.x <= eyeB.x) eyeA to eyeB else eyeB to eyeA
        val (imgLeftMouth, imgRightMouth) = if (mouthA.x <= mouthB.x) mouthA to mouthB else mouthB to mouthA
        return listOf(imgLeftEye, imgRightEye, nose, imgLeftMouth, imgRightMouth)
    }

    /**
     * Closed-form 2D similarity transform (uniform scale + rotation + translation, no reflection)
     * mapping [src] points to [dst] points in least-squares sense (Umeyama 2D).
     * Returns 9-element Matrix values: [a, -b, tx, b, a, ty, 0, 0, 1].
     */
    fun estimateSimilarityTransform(
        src: List<Point2D>,
        dst: List<Point2D> = SFACE_TEMPLATE,
    ): FloatArray {
        require(src.size == dst.size && src.size >= 2) { "Cần đúng 5 điểm landmark để căn chỉnh." }
        val n = src.size.toDouble()
        var srcMeanX = 0.0
        var srcMeanY = 0.0
        var dstMeanX = 0.0
        var dstMeanY = 0.0
        for (i in src.indices) {
            srcMeanX += src[i].x
            srcMeanY += src[i].y
            dstMeanX += dst[i].x
            dstMeanY += dst[i].y
        }
        srcMeanX /= n
        srcMeanY /= n
        dstMeanX /= n
        dstMeanY /= n

        var varSrc = 0.0
        var sumDot = 0.0
        var sumCross = 0.0
        for (i in src.indices) {
            val sx = src[i].x - srcMeanX
            val sy = src[i].y - srcMeanY
            val dx = dst[i].x - dstMeanX
            val dy = dst[i].y - dstMeanY
            varSrc += sx * sx + sy * sy
            sumDot += sx * dx + sy * dy
            sumCross += sx * dy - sy * dx
        }
        require(varSrc > 1e-6) { "Các điểm landmark không hợp lệ để căn chỉnh." }

        val a = (sumDot / varSrc).toFloat()
        val b = (sumCross / varSrc).toFloat()
        val tx = (dstMeanX - (a * srcMeanX - b * srcMeanY)).toFloat()
        val ty = (dstMeanY - (b * srcMeanX + a * srcMeanY)).toFloat()
        return floatArrayOf(
            a, -b, tx,
            b, a, ty,
            0f, 0f, 1f,
        )
    }

    fun l2Normalize(raw: FloatArray): DoubleArray {
        require(raw.isNotEmpty()) { "Vector đặc trưng rỗng." }
        var sumSq = 0.0
        for (v in raw) {
            val d = v.toDouble()
            require(d.isFinite()) { "Vector đặc trưng chứa giá trị không hữu hạn." }
            sumSq += d * d
        }
        val norm = sqrt(sumSq)
        require(norm.isFinite() && norm > 1e-5) { "Chuẩn L2 của vector quá nhỏ." }
        return DoubleArray(raw.size) { i -> raw[i].toDouble() / norm }
    }

    fun l2Normalize(raw: DoubleArray): DoubleArray {
        require(raw.isNotEmpty()) { "Vector đặc trưng rỗng." }
        var sumSq = 0.0
        for (v in raw) {
            require(v.isFinite()) { "Vector đặc trưng chứa giá trị không hữu hạn." }
            sumSq += v * v
        }
        val norm = sqrt(sumSq)
        require(norm.isFinite() && norm > 1e-5) { "Chuẩn L2 của vector quá nhỏ." }
        return DoubleArray(raw.size) { i -> raw[i] / norm }
    }

    fun averageAndNormalize(samples: List<DoubleArray>): DoubleArray {
        require(samples.isNotEmpty()) { "Cần ít nhất 1 mẫu để gộp." }
        val dim = samples.first().size
        require(samples.all { it.size == dim }) { "Các mẫu không cùng số chiều." }
        val mean = DoubleArray(dim)
        for (sample in samples) {
            for (i in 0 until dim) {
                mean[i] += sample[i]
            }
        }
        for (i in 0 until dim) {
            mean[i] /= samples.size.toDouble()
        }
        return l2Normalize(mean)
    }

    fun cosineSimilarity(a: DoubleArray, b: List<Double>): Double {
        if (a.size != b.size || a.isEmpty()) return -1.0
        var dot = 0.0
        for (i in a.indices) {
            dot += a[i] * b[i]
        }
        return if (dot.isFinite()) dot else -1.0
    }

    fun matchRoster(
        probe: DoubleArray,
        roster: List<EnrolledPerson>,
        minScore: Double,
        minMargin: Double = MIN_SECOND_BEST_MARGIN,
    ): MatchOutcome {
        if (roster.isEmpty()) return MatchOutcome.Uncertain(bestScore = null, margin = null)
        val ranked = roster
            .map { person -> MatchCandidate(person, cosineSimilarity(probe, person.template)) }
            .sortedByDescending { it.score }

        val top = ranked.first()
        val margin = if (ranked.size >= 2) top.score - ranked[1].score else null
        val passesScore = top.score >= minScore
        val passesMargin = margin == null || margin >= minMargin
        return if (passesScore && passesMargin) {
            MatchOutcome.Matched(top, margin)
        } else {
            MatchOutcome.Uncertain(top.score, margin)
        }
    }
}
