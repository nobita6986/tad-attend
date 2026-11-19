package vn.tad.attend.ml

import kotlin.math.abs

enum class LivenessStage {
    WAITING_FOR_FACE,
    MULTIPLE_FACES,
    FACE_TOO_SMALL,
    TURN_FIRST_SIDE,
    TURN_OPPOSITE_SIDE,
    LOOK_STRAIGHT,
    READY_TO_CAPTURE,
    COOLDOWN_WAIT_LEAVE,
}

data class LivenessUpdate(
    val stage: LivenessStage,
    val prompt: String,
    val shouldCapture: Boolean = false,
)

/**
 * Interactive head-turn liveness challenge (`mlkit_head_turn`).
 * Orientation-agnostic across front cameras: user turns slightly to one side,
 * then to the opposite side, then looks straight ahead.
 */
class LivenessTracker(
    private val turnThresholdDeg: Float = 12f,
    private val straightThresholdDeg: Float = 9f,
    private val maxRollDeg: Float = 18f,
    private val minFaceWidthRatio: Float = 0.16f,
    private val leaveResetMs: Long = 1_100L,
) {
    private var firstSideSign: Int = 0 // -1 or +1 once first turn is observed
    private var sawOppositeSide: Boolean = false
    private var armed: Boolean = true
    private var absentSinceMs: Long? = null

    fun reset() {
        firstSideSign = 0
        sawOppositeSide = false
        armed = true
        absentSinceMs = null
    }

    fun markCaptured() {
        armed = false
        firstSideSign = 0
        sawOppositeSide = false
    }

    fun onObservation(
        faceCount: Int,
        yawDeg: Float? = null,
        rollDeg: Float? = null,
        faceWidthRatio: Float = 0f,
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
            firstSideSign = 0
            sawOppositeSide = false
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
            )
        }

        val yaw = yawDeg ?: return LivenessUpdate(
            stage = LivenessStage.WAITING_FOR_FACE,
            prompt = "Không đo được góc mặt. Giữ mặt rõ ánh sáng.",
        )

        if (firstSideSign == 0) {
            if (abs(yaw) >= turnThresholdDeg) {
                firstSideSign = if (yaw < 0f) -1 else 1
                return LivenessUpdate(
                    stage = LivenessStage.TURN_OPPOSITE_SIDE,
                    prompt = "Tốt lắm! Tiếp tục quay nhẹ sang bên ngược lại.",
                )
            }
            return LivenessUpdate(
                stage = LivenessStage.TURN_FIRST_SIDE,
                prompt = "Vui lòng quay nhẹ đầu sang trái hoặc phải.",
            )
        }

        if (!sawOppositeSide) {
            val turnedOpposite = (firstSideSign < 0 && yaw >= turnThresholdDeg) ||
                (firstSideSign > 0 && yaw <= -turnThresholdDeg)
            if (turnedOpposite) {
                sawOppositeSide = true
                return LivenessUpdate(
                    stage = LivenessStage.LOOK_STRAIGHT,
                    prompt = "Nhìn thẳng vào camera để chấm công.",
                )
            }
            return LivenessUpdate(
                stage = LivenessStage.TURN_OPPOSITE_SIDE,
                prompt = "Tiếp tục quay nhẹ sang bên ngược lại.",
            )
        }

        val rollOk = rollDeg == null || abs(rollDeg) <= maxRollDeg
        if (abs(yaw) <= straightThresholdDeg && rollOk) {
            return LivenessUpdate(
                stage = LivenessStage.READY_TO_CAPTURE,
                prompt = "Đang xác thực khuôn mặt...",
                shouldCapture = true,
            )
        }

        return LivenessUpdate(
            stage = LivenessStage.LOOK_STRAIGHT,
            prompt = "Giữ thẳng khuôn mặt và nhìn vào camera.",
        )
    }
}
