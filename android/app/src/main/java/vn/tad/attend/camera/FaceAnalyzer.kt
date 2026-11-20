package vn.tad.attend.camera

import android.graphics.Bitmap
import android.graphics.Matrix
import android.os.SystemClock
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.ImageProxy
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.face.Face
import com.google.mlkit.vision.face.FaceDetection
import com.google.mlkit.vision.face.FaceDetector
import com.google.mlkit.vision.face.FaceDetectorOptions
import java.io.Closeable
import java.util.concurrent.atomic.AtomicBoolean

data class AnalyzedFrame(
    val bitmap: Bitmap,
    val faces: List<Face>,
    val timestampMs: Long,
)

/**
 * Converts each CameraX frame to an upright Bitmap before running ML Kit Face Detection,
 * guaranteeing that landmark & boundingBox coordinates match the Bitmap pixel coordinates 1:1.
 *
 * Uses FAST mode + eye-open classification + 5-point landmarks (~14-15 fps on mid-range phones)
 * so blink and gentle head-turn challenges respond immediately without dropping turns.
 */
class FaceAnalyzer(
    private val minFrameIntervalMs: Long = 70L,
    private val onAnalyzed: (AnalyzedFrame) -> Unit,
) : ImageAnalysis.Analyzer, Closeable {

    private val detector: FaceDetector = FaceDetection.getClient(
        FaceDetectorOptions.Builder()
            .setPerformanceMode(FaceDetectorOptions.PERFORMANCE_MODE_FAST)
            .setLandmarkMode(FaceDetectorOptions.LANDMARK_MODE_ALL)
            .setClassificationMode(FaceDetectorOptions.CLASSIFICATION_MODE_ALL)
            .setMinFaceSize(0.14f)
            .build(),
    )

    private val busy = AtomicBoolean(false)
    @Volatile
    private var lastProcessedMs = 0L

    override fun analyze(imageProxy: ImageProxy) {
        val now = SystemClock.elapsedRealtime()
        if (now - lastProcessedMs < minFrameIntervalMs || !busy.compareAndSet(false, true)) {
            imageProxy.close()
            return
        }
        lastProcessedMs = now

        val upright = try {
            val raw = imageProxy.toBitmap()
            val rotation = imageProxy.imageInfo.rotationDegrees
            if (rotation == 0) {
                raw
            } else {
                val matrix = Matrix().apply { postRotate(rotation.toFloat()) }
                val rotated = Bitmap.createBitmap(raw, 0, 0, raw.width, raw.height, matrix, true)
                raw.recycle()
                rotated
            }
        } catch (_: Throwable) {
            busy.set(false)
            imageProxy.close()
            return
        }

        imageProxy.close()

        val input = InputImage.fromBitmap(upright, 0)
        detector.process(input)
            .addOnSuccessListener { faces ->
                try {
                    onAnalyzed(AnalyzedFrame(bitmap = upright, faces = faces, timestampMs = now))
                } catch (_: Throwable) {
                    if (!upright.isRecycled) upright.recycle()
                }
            }
            .addOnFailureListener {
                if (!upright.isRecycled) upright.recycle()
            }
            .addOnCompleteListener {
                busy.set(false)
            }
    }

    override fun close() {
        runCatching { detector.close() }
    }
}
