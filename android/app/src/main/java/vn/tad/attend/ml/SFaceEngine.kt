package vn.tad.attend.ml

import ai.onnxruntime.OnnxTensor
import ai.onnxruntime.OrtEnvironment
import ai.onnxruntime.OrtSession
import ai.onnxruntime.TensorInfo
import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Matrix
import android.graphics.Paint
import android.graphics.Rect
import com.google.mlkit.vision.face.Face
import com.google.mlkit.vision.face.FaceLandmark
import vn.tad.attend.BuildConfig
import java.io.ByteArrayOutputStream
import java.io.Closeable
import java.nio.FloatBuffer
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt

data class ExtractedFace(
    val embedding: DoubleArray,
    val evidenceJpeg: ByteArray,
)

/**
 * On-device SFace embedding engine (OpenCV Zoo `face_recognition_sface_2021dec.onnx`, Apache-2.0).
 * Preprocessing matches OpenCV `FaceRecognizerSFImpl`:
 *  1. 5-point similarity alignment to 112x112 canonical template
 *  2. RGB float32 NCHW tensor [1, 3, 112, 112] with pixel scale 1.0 (0..255f), mean 0
 *  3. Output [1, 128] L2-normalized embedding
 */
class SFaceEngine(context: Context) : Closeable {
    private val env: OrtEnvironment = OrtEnvironment.getEnvironment()
    private val session: OrtSession
    private val inputName: String
    private val outputDimensions: Int

    init {
        val modelBytes = try {
            context.assets.open(BuildConfig.SFACE_ASSET_PATH).use { it.readBytes() }
        } catch (e: Exception) {
            throw IllegalStateException("Thiếu mô hình SFace (${BuildConfig.SFACE_ASSET_PATH}). Không cho phép chấm công.", e)
        }
        val opts = OrtSession.SessionOptions().apply {
            setIntraOpNumThreads(2)
            setOptimizationLevel(OrtSession.SessionOptions.OptLevel.ALL_OPT)
        }
        session = env.createSession(modelBytes, opts)

        val inputEntry = session.inputInfo.entries.firstOrNull()
            ?: throw IllegalStateException("Mô hình ONNX không có tensor đầu vào.")
        inputName = inputEntry.key
        val inInfo = inputEntry.value.info as? TensorInfo
            ?: throw IllegalStateException("Đầu vào ONNX không phải TensorInfo.")
        val inShape = inInfo.shape
        if (inShape.size != 4 || inShape[1] != 3L || inShape[2] != 112L || inShape[3] != 112L) {
            throw IllegalStateException("Kích thước đầu vào ONNX không đúng [1,3,112,112]: ${inShape.contentToString()}")
        }

        val outEntry = session.outputInfo.entries.firstOrNull()
            ?: throw IllegalStateException("Mô hình ONNX không có tensor đầu ra.")
        val outInfo = outEntry.value.info as? TensorInfo
            ?: throw IllegalStateException("Đầu ra ONNX không phải TensorInfo.")
        val dim = outInfo.shape.lastOrNull()?.toInt() ?: -1
        if (dim != BuildConfig.EMBEDDING_DIMENSIONS) {
            throw IllegalStateException("Số chiều đặc trưng mô hình ($dim) khác cấu hình (${BuildConfig.EMBEDDING_DIMENSIONS}).")
        }
        outputDimensions = dim
    }

    fun dimensions(): Int = outputDimensions

    fun extract(frameBitmap: Bitmap, face: Face): ExtractedFace {
        val leftEye = face.getLandmark(FaceLandmark.LEFT_EYE)?.position
        val rightEye = face.getLandmark(FaceLandmark.RIGHT_EYE)?.position
        val nose = face.getLandmark(FaceLandmark.NOSE_BASE)?.position
        val mouthLeft = face.getLandmark(FaceLandmark.MOUTH_LEFT)?.position
        val mouthRight = face.getLandmark(FaceLandmark.MOUTH_RIGHT)?.position

        if (leftEye == null || rightEye == null || nose == null || mouthLeft == null || mouthRight == null) {
            throw IllegalStateException("Không thấy đủ 5 điểm mắt, mũi, miệng. Giữ mặt rõ trong khung.")
        }

        val orderedSrc = FaceMath.orderFiveLandmarks(
            eyeA = Point2D(leftEye.x, leftEye.y),
            eyeB = Point2D(rightEye.x, rightEye.y),
            nose = Point2D(nose.x, nose.y),
            mouthA = Point2D(mouthLeft.x, mouthLeft.y),
            mouthB = Point2D(mouthRight.x, mouthRight.y),
        )
        val matrixValues = FaceMath.estimateSimilarityTransform(orderedSrc)
        val aligned = warpFace112(frameBitmap, matrixValues)
        val embedding = try {
            runInference(aligned)
        } finally {
            aligned.recycle()
        }
        val jpeg = createEvidenceJpeg(frameBitmap, face.boundingBox)
        return ExtractedFace(embedding = embedding, evidenceJpeg = jpeg)
    }

    private fun warpFace112(source: Bitmap, matrixValues: FloatArray): Bitmap {
        val out = Bitmap.createBitmap(FaceMath.INPUT_SIZE, FaceMath.INPUT_SIZE, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(out)
        val matrix = Matrix().apply { setValues(matrixValues) }
        val paint = Paint(Paint.ANTI_ALIAS_FLAG or Paint.FILTER_BITMAP_FLAG)
        canvas.drawBitmap(source, matrix, paint)
        return out
    }

    private fun runInference(aligned112: Bitmap): DoubleArray {
        val size = FaceMath.INPUT_SIZE
        val pixelCount = size * size
        val pixels = IntArray(pixelCount)
        aligned112.getPixels(pixels, 0, size, 0, 0, size, size)

        // OpenCV FaceRecognizerSFImpl::feature uses blobFromImage(..., scalefactor=1, mean=0, swapRB=true)
        // -> NCHW RGB in [0, 255] float32.
        val floatData = FloatArray(3 * pixelCount)
        for (i in 0 until pixelCount) {
            val px = pixels[i]
            floatData[i] = ((px shr 16) and 0xFF).toFloat()
            floatData[pixelCount + i] = ((px shr 8) and 0xFF).toFloat()
            floatData[2 * pixelCount + i] = (px and 0xFF).toFloat()
        }

        val shape = longArrayOf(1L, 3L, size.toLong(), size.toLong())
        OnnxTensor.createTensor(env, FloatBuffer.wrap(floatData), shape).use { tensor ->
            session.run(mapOf(inputName to tensor)).use { results ->
                val outValue = results[0].value
                val raw: FloatArray = when (outValue) {
                    is Array<*> -> (outValue.firstOrNull() as? FloatArray)
                        ?: throw IllegalStateException("Đầu ra SFace không phải FloatArray.")
                    is FloatArray -> outValue
                    else -> throw IllegalStateException("Định dạng đầu ra SFace không hỗ trợ.")
                }
                if (raw.size != outputDimensions) {
                    throw IllegalStateException("Vector SFace có ${raw.size} chiều, kỳ vọng $outputDimensions.")
                }
                return FaceMath.l2Normalize(raw)
            }
        }
    }

    private fun createEvidenceJpeg(frame: Bitmap, box: Rect): ByteArray {
        val padX = (box.width() * 0.25f).roundToInt()
        val padY = (box.height() * 0.25f).roundToInt()
        val left = max(0, box.left - padX)
        val top = max(0, box.top - padY)
        val right = min(frame.width, box.right + padX)
        val bottom = min(frame.height, box.bottom + padY)
        val cropW = max(1, right - left)
        val cropH = max(1, bottom - top)

        val cropped = Bitmap.createBitmap(frame, left, top, cropW, cropH)
        val maxDim = max(cropped.width, cropped.height)
        val scaled = if (maxDim > 800) {
            val scale = 800f / maxDim.toFloat()
            Bitmap.createScaledBitmap(
                cropped,
                max(1, (cropped.width * scale).roundToInt()),
                max(1, (cropped.height * scale).roundToInt()),
                true,
            )
        } else {
            cropped
        }

        try {
            val baos = ByteArrayOutputStream()
            for (quality in intArrayOf(75, 60, 45, 30)) {
                baos.reset()
                scaled.compress(Bitmap.CompressFormat.JPEG, quality, baos)
                val bytes = baos.toByteArray()
                if (bytes.size <= MAX_EVIDENCE_BYTES) return bytes
            }
            throw IllegalStateException("Ảnh minh chứng vượt 250KB.")
        } finally {
            if (scaled !== cropped) scaled.recycle()
            cropped.recycle()
        }
    }

    override fun close() {
        runCatching { session.close() }
    }

    companion object {
        private const val MAX_EVIDENCE_BYTES = 250_000
    }
}
