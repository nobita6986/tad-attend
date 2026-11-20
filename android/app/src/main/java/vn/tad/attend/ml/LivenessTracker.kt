package vn.tad.attend.ml

import kotlin.math.abs
import kotlin.math.max
import kotlin.math.min

enum class LivenessStage {
    WAITING_FOR_FACE,
    MULTIPLE_FACES,
    FACE_TOO_SMALL,
    VERIFY_LIVENESS,
    LOOK_STRAIGHT,
    READY_TO_CAPTURE,
    COOLDOWN_WAIT_LEAVE,
}

data class LivenessUpdate(
    val stage: LivenessStage,
    val prompt: String,
    val shouldCapture: Boolean = false,
    val livenessVerified: Boolean = false,
)

/**
 * Fast, natural liveness challenge (`mlkit_head_turn`):
 *  - Step 1: Natural blink (open -> closed -> open) OR gentle 1-side head turn (|yaw| >= 9° or yaw span >= 10°).
 *  - Step 2: Look toward camera (|yaw| <= 14°, |roll| <= 20°) -> captures immediately.
 *  - Smart retry window: if the first frame is blurry or low-score, retains verified liveness
 *    for up to [maxCaptureAttempts] attempts within [retryWindowMs] instead of forcing the user to turn again.
 */
class LivenessTracker(
    private val turnThresholdDeg: Float = 9f,
    private val yawSpanThresholdDeg: Float = 10f,
    private val straightThresholdDeg: Float = 14f,
    private val maxRollDeg: Float = 20f,
    private val minFaceWidthRatio: Float = 0.14f,
    private val leaveResetMs: Long = 900L,
    private val maxCaptureAttempts: Int = 5,
    private val retryWindowMs: Long = 3_000L,
    private val retrySpacingMs: Long = 220L,
) {
    private var livenessVerified: Boolean = false
    private var armed: Boolean = true
    private var absentSinceMs: Long? = null

    private var minYawSeen: Float = Float.POSITIVE_INFINITY
    private var maxYawSeen: Float = Float.NEGATIVE_INFINITY
    private var sawEyesOpen: Boolean = false
    private var sawEyesClosed: Boolean = false

    private var verifiedAtMs: Long? = null
    private var captureAttempts: Int = 0
    private var lastAttemptFailedMs: Long = 0L

    fun reset() {
        livenessVerified = false
        armed = true
        absentSinceMs = null
        minYawSeen = Float.POSITIVE_INFINITY
        maxYawSeen = Float.NEGATIVE_INFINITY
        sawEyesOpen = false
        sawEyesClosed = false
        verifiedAtMs = null
        captureAttempts = 0
        lastAttemptFailedMs = 0L
    }

    /** Called ONLY after attendance is accepted (or server cooldown 409) to prevent immediate re-trigger. */
    fun markPunchCompleted() {
        armed = false
        livenessVerified = false
        verifiedAtMs = null
        captureAttempts = 0
    }

    /**
     * Called when a capture attempt fails (blurry frame, missing landmark, or score below threshold).
     * Returns true if we still have retry budget without forcing a fresh liveness motion;
     * returns false when budget is exhausted and state has been reset.
     */
    fun markCaptureAttemptFailed(nowMs: Long): Boolean {
        captureAttempts++
        lastAttemptFailedMs = nowMs
        val started = verifiedAtMs ?: nowMs
        if (captureAttempts >= maxCaptureAttempts || (nowMs - started) >= retryWindowMs) {
            reset()
            return false
        }
        return true
    }

    fun currentAttempt(): Int = captureAttempts

    fun maxAttempts(): Int = maxCaptureAttempts

    fun onObservation(
        faceCount: Int,
        yawDeg: Float? = null,
        rollDeg: Float? = null,
        faceWidthRatio: Float = 0f,
        leftEyeOpenProb: Float? = null,
        rightEyeOpenProb: Float? = null,
        nowMs: Long,
    ): LivenessUpdate {
        if (faceCount == 0) {
            if (absentSinceMs == null) absentSinceMs = nowMs
            val elapsed = nowMs - (absentSinceMs ?: nowMs)
            if (elapsed >= leaveResetMs) {
                reset()
                return LivenessUpdate(
                    stage = LivenessStage.WAITING_FOR_FACE,
                    prompt = "Mời người tiếp theo. Đưa khuôn mặt vào khung.",
                )
            }
            return if (!armed) {
                LivenessUpdate(
                    stage = LivenessStage.COOLDOWN_WAIT_LEAVE,
                    prompt = "Đã xong. Mời bước ra khỏi khung cho người tiếp theo.",
                )
            } else {
                LivenessUpdate(
                    stage = LivenessStage.WAITING_FOR_FACE,
                    prompt = "Đưa một khuôn mặt vào khung hình.",
                )
            }
        }

        absentSinceMs = null

        if (faceCount > 1) {
            livenessVerified = false
            minYawSeen = Float.POSITIVE_INFINITY
            maxYawSeen = Float.NEGATIVE_INFINITY
            sawEyesOpen = false
            sawEyesClosed = false
            verifiedAtMs = null
            captureAttempts = 0
            return LivenessUpdate(
                stage = LivenessStage.MULTIPLE_FACES,
                prompt = "Phát hiện nhiều khuôn mặt. Vui lòng đứng từng người một.",
            )
        }

        if (!armed) {
            return LivenessUpdate(
                stage = LivenessStage.COOLDOWN_WAIT_LEAVE,
                prompt = "Đã ghi nhận. Mời người tiếp theo.",
            )
        }

        if (faceWidthRatio < minFaceWidthRatio) {
            return LivenessUpdate(
                stage = LivenessStage.FACE_TOO_SMALL,
                prompt = "Đưa khuôn mặt lại gần camera hơn.",
                livenessVerified = livenessVerified,
            )
        }

        val yaw = yawDeg ?: return LivenessUpdate(
            stage = LivenessStage.WAITING_FOR_FACE,
            prompt = "Không đo được góc mặt. Giữ mặt đủ ánh sáng.",
        )

        if (!livenessVerified) {
            minYawSeen = min(minYawSeen, yaw)
            maxYawSeen = max(maxYawSeen, yaw)
            val yawSpan = maxYawSeen - minYawSeen

            val eyeAvg = averageEyeOpen(leftEyeOpenProb, rightEyeOpenProb)
            if (eyeAvg != null) {
                if (!sawEyesOpen && eyeAvg >= 0.65f) {
                    sawEyesOpen = true
                } else if (sawEyesOpen && !sawEyesClosed && eyeAvg <= 0.35f) {
                    sawEyesClosed = true
                } else if (sawEyesClosed && eyeAvg >= 0.55f) {
                    livenessVerified = true
                    verifiedAtMs = nowMs
                }
            }

            if (!livenessVerified && (abs(yaw) >= turnThresholdDeg || yawSpan >= yawSpanThresholdDeg)) {
                livenessVerified = true
                verifiedAtMs = nowMs
            }

            if (!livenessVerified) {
                return LivenessUpdate(
                    stage = LivenessStage.VERIFY_LIVENESS,
                    prompt = "Chớp mắt nhẹ hoặc quay nhẹ đầu sang một bên.",
                    livenessVerified = false,
                )
            }
        }

        // Expire stale verified state if user never looked straight within retryWindowMs
        val started = verifiedAtMs ?: nowMs
        if (nowMs - started > retryWindowMs + 2_000L) {
            reset()
            return LivenessUpdate(
                stage = LivenessStage.VERIFY_LIVENESS,
                prompt = "Chớp mắt nhẹ hoặc quay nhẹ đầu sang một bên.",
                livenessVerified = false,
            )
        }

        val rollOk = rollDeg == null || abs(rollDeg) <= maxRollDeg
        val straightOk = abs(yaw) <= straightThresholdDeg && rollOk
        if (straightOk) {
            val spacingOk = lastAttemptFailedMs == 0L || (nowMs - lastAttemptFailedMs) >= retrySpacingMs
            if (spacingOk) {
                return LivenessUpdate(
                    stage = LivenessStage.READY_TO_CAPTURE,
                    prompt = if (captureAttempts == 0) {
                        "Đang nhận diện khuôn mặt..."
                    } else {
                        "Giữ nguyên mặt nhìn thẳng (lần ${captureAttempts + 1}/$maxCaptureAttempts)..."
                    },
                    shouldCapture = true,
                    livenessVerified = true,
                )
            }
            return LivenessUpdate(
                stage = LivenessStage.LOOK_STRAIGHT,
                prompt = "Giữ nguyên mặt nhìn thẳng vào camera...",
                shouldCapture = false,
                livenessVerified = true,
            )
        }

        return LivenessUpdate(
            stage = LivenessStage.LOOK_STRAIGHT,
            prompt = "Tốt lắm! Nhìn thẳng vào camera để chấm công.",
            shouldCapture = false,
            livenessVerified = true,
        )
    }

    private fun averageEyeOpen(left: Float?, right: Float?): Float? = when {
        left != null && right != null -> (left + right) / 2f
        left != null -> left
        right != null -> right
        else -> null
    }
}
