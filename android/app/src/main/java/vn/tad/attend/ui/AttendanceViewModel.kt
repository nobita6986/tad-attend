package vn.tad.attend.ui

import android.app.Application
import android.graphics.Bitmap
import android.os.SystemClock
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.google.mlkit.vision.face.Face
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import vn.tad.attend.BuildConfig
import vn.tad.attend.camera.AnalyzedFrame
import vn.tad.attend.data.AttendanceApiClient
import vn.tad.attend.ml.FaceMath
import vn.tad.attend.ml.LivenessStage
import vn.tad.attend.ml.LivenessTracker
import vn.tad.attend.ml.MatchOutcome
import vn.tad.attend.ml.SFaceEngine
import vn.tad.attend.model.AttendanceReceipt
import vn.tad.attend.model.DeviceInfo
import vn.tad.attend.model.EmployeeOption
import vn.tad.attend.model.EnrolledPerson
import vn.tad.attend.model.EnrollmentOptionsResponse
import vn.tad.attend.model.ShiftOption
import vn.tad.attend.security.PinHash
import vn.tad.attend.security.SecureStore
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale
import kotlin.math.abs

enum class AppMode { KIOSK, ENROLLMENT }

sealed class EnrollmentTarget {
    data class CreateNew(
        val code: String,
        val fullName: String,
        val gender: String?,
        val department: String?,
        val shiftId: Int?,
    ) : EnrollmentTarget()

    data class ExistingEmployee(
        val employeeId: Int,
        val label: String,
    ) : EnrollmentTarget()
}

data class AttendUiState(
    val mode: AppMode = AppMode.KIOSK,
    val isConnected: Boolean = false,
    val isConnecting: Boolean = false,
    val isModelReady: Boolean = false,
    val modelStatus: String = "Đang kiểm tra mô hình SFace...",
    val deviceInfo: DeviceInfo? = null,
    val rosterCount: Int = 0,
    val availableShifts: List<ShiftOption> = emptyList(),
    val availableEmployees: List<EmployeeOption> = emptyList(),
    val headline: String = "Chưa kết nối máy chủ",
    val message: String = "Vào mục Cấu hình để nhập URL và token thiết bị.",
    val livenessStage: LivenessStage = LivenessStage.WAITING_FOR_FACE,
    val lastReceipt: AttendanceReceipt? = null,
    val isError: Boolean = false,
    val enrollmentSamples: Int = 0,
    val enrollmentRequired: Int = 5,
    val enrollmentActive: Boolean = false,
    val enrollmentSuccessNonce: Int = 0,
    val enrollmentStatus: String = "Nhập thông tin nhân viên mới hoặc chọn nhân viên đã có, sau đó chụp 5 mẫu khuôn mặt.",
    val enrollmentError: Boolean = false,
    val serverUrl: String = BuildConfig.DEFAULT_SERVER_URL,
    val hasToken: Boolean = false,
    val adminPinSet: Boolean = false,
    val adminUnlocked: Boolean = false,
    val pinError: String? = null,
)

class AttendanceViewModel(application: Application) : AndroidViewModel(application) {
    private val store = SecureStore(application)
    private val api = AttendanceApiClient()
    private val liveness = LivenessTracker()

    private val _ui = MutableStateFlow(
        AttendUiState(
            serverUrl = store.getServerUrl(),
            hasToken = store.hasDeviceToken(),
            adminPinSet = store.hasAdminPin(),
        ),
    )
    val ui: StateFlow<AttendUiState> = _ui.asStateFlow()

    @Volatile
    private var engine: SFaceEngine? = null
    @Volatile
    private var roster: List<EnrolledPerson> = emptyList()
    @Volatile
    private var busyProcessing = false
    @Volatile
    private var lastSampleCaptureMs = 0L

    private var activeEnrollmentTarget: EnrollmentTarget? = null
    private val enrollmentVectors = mutableListOf<DoubleArray>()
    private var failedPinAttempts = 0
    private var pinBlockedUntilMs = 0L

    init {
        viewModelScope.launch {
            initModelAndConnect()
        }
    }

    private suspend fun initModelAndConnect() {
        val loaded = withContext(Dispatchers.Default) {
            runCatching { SFaceEngine(getApplication()) }
        }
        loaded.onSuccess { sface ->
            engine = sface
            _ui.update {
                it.copy(
                    isModelReady = true,
                    modelStatus = "SFace ${BuildConfig.EXPECTED_MODEL_VERSION} (${sface.dimensions()} chiều) — Sẵn sàng",
                )
            }
            if (store.hasDeviceToken()) {
                connect()
            } else {
                _ui.update {
                    it.copy(
                        headline = "Chưa cấu hình thiết bị",
                        message = "Mở mục Cấu hình, dán token thiết bị tạo từ /admin/devices để kết nối.",
                        isError = false,
                    )
                }
            }
        }.onFailure { err ->
            engine = null
            _ui.update {
                it.copy(
                    isModelReady = false,
                    modelStatus = err.message ?: "Không tải được mô hình SFace.",
                    headline = "Thiếu mô hình AI",
                    message = err.message ?: "Không tải được mô hình SFace. Không cho phép chấm công.",
                    isError = true,
                )
            }
        }
    }

    fun connect() {
        viewModelScope.launch {
            val sface = engine
            if (sface == null) {
                _ui.update {
                    it.copy(
                        isConnected = false,
                        headline = "Thiếu mô hình AI",
                        message = "Không thể kết nối khi mô hình SFace chưa sẵn sàng.",
                        isError = true,
                    )
                }
                return@launch
            }
            val url = store.getServerUrl()
            val token = store.getDeviceToken()
            if (token.isNullOrBlank()) {
                _ui.update {
                    it.copy(
                        isConnected = false,
                        isConnecting = false,
                        headline = "Chưa có token thiết bị",
                        message = "Vào Cấu hình để nhập token thiết bị đã cấp từ máy chủ.",
                        isError = true,
                    )
                }
                return@launch
            }

            _ui.update {
                it.copy(
                    isConnecting = true,
                    headline = "Đang kết nối...",
                    message = "Đồng bộ cấu hình và danh sách nhân viên từ $url",
                    isError = false,
                )
            }

            runCatching {
                val info = api.getDeviceInfo(url, token)
                if (info.modelId == "UNCONFIGURED" || info.modelVersion == "UNCONFIGURED") {
                    throw IllegalStateException("Máy chủ chưa cấu hình mô hình nhận diện.")
                }
                if (info.modelId != BuildConfig.EXPECTED_MODEL_ID ||
                    info.modelVersion != BuildConfig.EXPECTED_MODEL_VERSION
                ) {
                    throw IllegalStateException(
                        "Mô hình máy chủ (${info.modelId}/${info.modelVersion}) không khớp app (${BuildConfig.EXPECTED_MODEL_ID}/${BuildConfig.EXPECTED_MODEL_VERSION}).",
                    )
                }
                if (info.embeddingDimensions != sface.dimensions()) {
                    throw IllegalStateException(
                        "Số chiều máy chủ (${info.embeddingDimensions}) không khớp SFace (${sface.dimensions()}).",
                    )
                }
                val people = api.getRoster(url, token, info.modelId, info.modelVersion)
                val options = runCatching { api.getEnrollmentOptions(url, token) }
                    .getOrDefault(EnrollmentOptionsResponse())
                Triple(info, people, options)
            }.onSuccess { (info, people, options) ->
                roster = people
                liveness.reset()
                enrollmentVectors.clear()
                activeEnrollmentTarget = null
                _ui.update {
                    it.copy(
                        mode = AppMode.KIOSK,
                        isConnected = true,
                        isConnecting = false,
                        deviceInfo = info,
                        rosterCount = people.size,
                        availableShifts = options.shifts,
                        availableEmployees = sortEmployeesForEnrollment(options.employees),
                        headline = "Chấm công · ${info.name}",
                        message = if (people.isEmpty()) {
                            "Đã kết nối (${directionLabel(info.direction)}). Vào tab Đăng ký để thêm nhân viên ngay trên máy."
                        } else {
                            "Đã đồng bộ ${people.size} nhân viên. Đưa khuôn mặt vào khung và quay nhẹ đầu."
                        },
                        lastReceipt = null,
                        isError = false,
                        enrollmentActive = false,
                        enrollmentSamples = 0,
                    )
                }
            }.onFailure { err ->
                _ui.update {
                    it.copy(
                        isConnected = false,
                        isConnecting = false,
                        headline = "Không thể kết nối máy chủ",
                        message = err.message ?: "Kiểm tra lại mạng, URL HTTPS và token thiết bị.",
                        isError = true,
                    )
                }
            }
        }
    }

    fun saveSettingsAndConnect(serverUrl: String, newToken: String) {
        val cleanUrl = serverUrl.trim().trimEnd('/')
        store.setServerUrl(cleanUrl)
        if (newToken.isNotBlank()) {
            store.setDeviceToken(newToken.trim())
        }
        _ui.update {
            it.copy(
                serverUrl = store.getServerUrl(),
                hasToken = store.hasDeviceToken(),
            )
        }
        connect()
    }

    fun refreshRoster() {
        val info = _ui.value.deviceInfo ?: run {
            connect()
            return
        }
        viewModelScope.launch {
            runCatching {
                val url = store.getServerUrl()
                val token = store.getDeviceToken()
                val people = api.getRoster(url, token, info.modelId, info.modelVersion)
                val options = runCatching { api.getEnrollmentOptions(url, token) }.getOrNull()
                people to options
            }.onSuccess { (people, options) ->
                roster = people
                _ui.update { prev ->
                    prev.copy(
                        rosterCount = people.size,
                        availableShifts = options?.shifts ?: prev.availableShifts,
                        availableEmployees = options?.employees?.let(::sortEmployeesForEnrollment) ?: prev.availableEmployees,
                        message = "Đã làm mới danh sách: ${people.size} nhân viên.",
                        isError = false,
                    )
                }
            }.onFailure { err ->
                _ui.update {
                    it.copy(
                        message = err.message ?: "Không đồng bộ được danh sách nhân viên.",
                        isError = true,
                    )
                }
            }
        }
    }

    fun unlockAdmin(pin: String, confirmPin: String) {
        val now = SystemClock.elapsedRealtime()
        if (now < pinBlockedUntilMs) {
            val sec = ((pinBlockedUntilMs - now) / 1000L) + 1L
            _ui.update { it.copy(pinError = "Đã khóa tạm thời. Thử lại sau ${sec}s.") }
            return
        }

        if (!store.hasAdminPin()) {
            if (pin.length < PinHash.MIN_LENGTH || pin != confirmPin) {
                _ui.update { it.copy(pinError = "Mã PIN cần ít nhất 6 ký tự và hai lần nhập phải khớp nhau.") }
                return
            }
            if (!store.saveAdminPin(pin)) {
                _ui.update { it.copy(pinError = "Không lưu được mã PIN.") }
                return
            }
            _ui.update { it.copy(adminPinSet = true, adminUnlocked = true, pinError = null) }
            return
        }

        if (!store.verifyAdminPin(pin)) {
            failedPinAttempts++
            if (failedPinAttempts >= 5) {
                pinBlockedUntilMs = now + 60_000L
                failedPinAttempts = 0
                _ui.update { it.copy(pinError = "Nhập sai 5 lần. Khóa tạm thời 60 giây.") }
            } else {
                _ui.update { it.copy(pinError = "Mã PIN không đúng (lần $failedPinAttempts/5).") }
            }
            return
        }

        failedPinAttempts = 0
        _ui.update { it.copy(adminUnlocked = true, pinError = null) }
    }

    fun lockAdmin() {
        cancelEnrollment()
        _ui.update { it.copy(adminUnlocked = false, pinError = null) }
    }

    fun startCreateAndEnroll(
        code: String,
        fullName: String,
        gender: String?,
        department: String?,
        shiftId: Int?,
        consentConfirmed: Boolean,
    ) {
        if (!_ui.value.isConnected || engine == null) {
            _ui.update { it.copy(enrollmentStatus = "Cần kết nối máy chủ trước khi đăng ký.", enrollmentError = true) }
            return
        }
        val cleanCode = code.trim().uppercase(Locale.US)
        val cleanName = fullName.trim()
        if (cleanCode.isEmpty() || cleanName.isEmpty()) {
            _ui.update { it.copy(enrollmentStatus = "Vui lòng nhập đầy đủ Mã nhân viên và Họ tên.", enrollmentError = true) }
            return
        }
        if (_ui.value.availableEmployees.any { it.code.equals(cleanCode, ignoreCase = true) }) {
            _ui.update {
                it.copy(
                    enrollmentStatus = "Mã nhân viên $cleanCode đã tồn tại. Chuyển sang mục \"Cập nhật NV đã có\" nếu muốn đăng ký lại khuôn mặt.",
                    enrollmentError = true,
                )
            }
            return
        }
        if (!consentConfirmed) {
            _ui.update { it.copy(enrollmentStatus = "Cần xác nhận nhân viên đồng ý thu thập khuôn mặt.", enrollmentError = true) }
            return
        }

        beginEnrollmentCapture(
            EnrollmentTarget.CreateNew(
                code = cleanCode,
                fullName = cleanName,
                gender = gender,
                department = department?.trim()?.takeIf { it.isNotEmpty() },
                shiftId = shiftId,
            ),
            "Đang chụp 5 mẫu cho $cleanCode · $cleanName. Nhìn thẳng vào camera...",
        )
    }

    fun startExistingEnrollment(employee: EmployeeOption?, consentConfirmed: Boolean) {
        if (!_ui.value.isConnected || engine == null) {
            _ui.update { it.copy(enrollmentStatus = "Cần kết nối máy chủ trước khi đăng ký.", enrollmentError = true) }
            return
        }
        if (employee == null) {
            _ui.update { it.copy(enrollmentStatus = "Vui lòng chọn nhân viên cần đăng ký khuôn mặt.", enrollmentError = true) }
            return
        }
        if (!consentConfirmed) {
            _ui.update { it.copy(enrollmentStatus = "Cần xác nhận nhân viên đồng ý thu thập khuôn mặt.", enrollmentError = true) }
            return
        }

        val label = "${employee.code} · ${employee.fullName}"
        beginEnrollmentCapture(
            EnrollmentTarget.ExistingEmployee(employeeId = employee.id, label = label),
            "Đang chụp 5 mẫu cho $label. Nhìn thẳng vào camera...",
        )
    }

    private fun beginEnrollmentCapture(target: EnrollmentTarget, initialStatus: String) {
        activeEnrollmentTarget = target
        enrollmentVectors.clear()
        lastSampleCaptureMs = 0L
        _ui.update {
            it.copy(
                mode = AppMode.ENROLLMENT,
                enrollmentSamples = 0,
                enrollmentActive = true,
                enrollmentStatus = initialStatus,
                enrollmentError = false,
            )
        }
    }

    fun cancelEnrollment() {
        enrollmentVectors.clear()
        activeEnrollmentTarget = null
        liveness.reset()
        _ui.update {
            it.copy(
                mode = AppMode.KIOSK,
                enrollmentActive = false,
                enrollmentSamples = 0,
                enrollmentStatus = "Đã hủy phiên chụp mẫu.",
                enrollmentError = false,
            )
        }
    }

    fun onCameraFrame(frame: AnalyzedFrame) {
        val state = _ui.value
        if (!state.isConnected || engine == null || busyProcessing) {
            if (!frame.bitmap.isRecycled) frame.bitmap.recycle()
            return
        }

        if (state.mode == AppMode.ENROLLMENT && state.enrollmentActive) {
            handleEnrollmentFrame(frame)
        } else {
            handleKioskFrame(frame)
        }
    }

    private fun handleEnrollmentFrame(frame: AnalyzedFrame) {
        val faces = frame.faces
        if (faces.size != 1) {
            if (!frame.bitmap.isRecycled) frame.bitmap.recycle()
            _ui.update {
                it.copy(
                    enrollmentStatus = if (faces.isEmpty()) "Đưa khuôn mặt vào khung để thu mẫu." else "Chỉ được có 1 người trong khung khi đăng ký.",
                    enrollmentError = faces.size > 1,
                )
            }
            return
        }
        val face = faces.first()
        val widthRatio = face.boundingBox.width().toFloat() / frame.bitmap.width.coerceAtLeast(1).toFloat()
        if (widthRatio < 0.20f || abs(face.headEulerAngleY) > 18f || abs(face.headEulerAngleZ) > 15f) {
            if (!frame.bitmap.isRecycled) frame.bitmap.recycle()
            _ui.update {
                it.copy(
                    enrollmentStatus = "Giữ khuôn mặt nhìn thẳng và đủ gần camera (${enrollmentVectors.size}/5 mẫu).",
                    enrollmentError = false,
                )
            }
            return
        }
        if (frame.timestampMs - lastSampleCaptureMs < 700L) {
            if (!frame.bitmap.isRecycled) frame.bitmap.recycle()
            return
        }

        busyProcessing = true
        lastSampleCaptureMs = frame.timestampMs
        extractForEnrollment(frame.bitmap, face)
    }

    private fun extractForEnrollment(bitmap: Bitmap, face: Face) {
        val sface = engine ?: run {
            if (!bitmap.isRecycled) bitmap.recycle()
            busyProcessing = false
            return
        }
        viewModelScope.launch {
            val result = withContext(Dispatchers.Default) {
                try {
                    Result.success(sface.extract(bitmap, face))
                } catch (e: Throwable) {
                    Result.failure(e)
                } finally {
                    if (!bitmap.isRecycled) bitmap.recycle()
                }
            }

            result.onFailure { err ->
                _ui.update {
                    it.copy(
                        enrollmentStatus = err.message ?: "Không đọc được đặc trưng khuôn mặt.",
                        enrollmentError = true,
                    )
                }
                busyProcessing = false
            }.onSuccess { extracted ->
                enrollmentVectors.add(extracted.embedding)
                val count = enrollmentVectors.size
                _ui.update {
                    it.copy(
                        enrollmentSamples = count,
                        enrollmentStatus = if (count < 5) "Đã thu $count/5 mẫu, giữ nguyên mặt..." else "Đã đủ 5/5 mẫu. Đang lưu lên máy chủ...",
                        enrollmentError = false,
                    )
                }
                if (count < 5) {
                    busyProcessing = false
                    return@launch
                }

                val info = _ui.value.deviceInfo
                val target = activeEnrollmentTarget
                val averaged = FaceMath.averageAndNormalize(enrollmentVectors.toList())
                enrollmentVectors.clear()
                activeEnrollmentTarget = null

                runCatching {
                    requireNotNull(info) { "Mất kết nối thiết bị." }
                    requireNotNull(target) { "Thiếu thông tin nhân viên đăng ký." }
                    val url = store.getServerUrl()
                    val token = store.getDeviceToken()
                    val resp = when (target) {
                        is EnrollmentTarget.CreateNew -> api.createAndEnrollEmployee(
                            baseUrl = url,
                            token = token,
                            code = target.code,
                            fullName = target.fullName,
                            gender = target.gender,
                            department = target.department,
                            shiftId = target.shiftId,
                            modelId = info.modelId,
                            modelVersion = info.modelVersion,
                            embedding = averaged,
                        )

                        is EnrollmentTarget.ExistingEmployee -> api.enrollExistingEmployee(
                            baseUrl = url,
                            token = token,
                            employeeId = target.employeeId,
                            modelId = info.modelId,
                            modelVersion = info.modelVersion,
                            embedding = averaged,
                        )
                    }
                    val updatedRoster = api.getRoster(url, token, info.modelId, info.modelVersion)
                    val updatedOptions = runCatching { api.getEnrollmentOptions(url, token) }.getOrNull()
                    Triple(resp, updatedRoster, updatedOptions)
                }.onSuccess { (resp, updatedRoster, updatedOptions) ->
                    roster = updatedRoster
                    liveness.reset()
                    _ui.update { prev ->
                        prev.copy(
                            mode = AppMode.KIOSK,
                            enrollmentActive = false,
                            enrollmentSamples = 0,
                            enrollmentSuccessNonce = prev.enrollmentSuccessNonce + 1,
                            rosterCount = updatedRoster.size,
                            availableShifts = updatedOptions?.shifts ?: prev.availableShifts,
                            availableEmployees = updatedOptions?.employees?.let(::sortEmployeesForEnrollment) ?: prev.availableEmployees,
                            enrollmentStatus = "Đã lưu & đăng ký khuôn mặt cho ${resp.code} · ${resp.fullName}! Có thể chấm công ngay.",
                            enrollmentError = false,
                            headline = "Chấm công · ${info?.name.orEmpty()}",
                            message = "Đã đồng bộ ${updatedRoster.size} nhân viên. Đưa khuôn mặt vào khung và quay nhẹ đầu.",
                            isError = false,
                        )
                    }
                }.onFailure { err ->
                    liveness.reset()
                    _ui.update {
                        it.copy(
                            mode = AppMode.KIOSK,
                            enrollmentActive = false,
                            enrollmentSamples = 0,
                            enrollmentStatus = err.message ?: "Lỗi lưu thông tin nhân viên lên máy chủ.",
                            enrollmentError = true,
                        )
                    }
                }
                busyProcessing = false
            }
        }
    }

    private fun handleKioskFrame(frame: AnalyzedFrame) {
        val faces = frame.faces
        val singleFace = faces.singleOrNull()
        val widthRatio = if (singleFace != null) {
            singleFace.boundingBox.width().toFloat() / frame.bitmap.width.coerceAtLeast(1).toFloat()
        } else {
            0f
        }

        val update = liveness.onObservation(
            faceCount = faces.size,
            yawDeg = singleFace?.headEulerAngleY,
            rollDeg = singleFace?.headEulerAngleZ,
            faceWidthRatio = widthRatio,
            nowMs = frame.timestampMs,
        )

        if (!update.shouldCapture || singleFace == null) {
            if (!frame.bitmap.isRecycled) frame.bitmap.recycle()
            _ui.update { prev ->
                val keepSuccessCard = update.stage == LivenessStage.COOLDOWN_WAIT_LEAVE
                prev.copy(
                    livenessStage = update.stage,
                    message = if (keepSuccessCard && prev.lastReceipt != null) prev.message else update.prompt,
                    lastReceipt = if (update.stage == LivenessStage.WAITING_FOR_FACE) null else prev.lastReceipt,
                    isError = update.stage == LivenessStage.MULTIPLE_FACES,
                )
            }
            return
        }

        liveness.markCaptured()
        busyProcessing = true
        _ui.update {
            it.copy(
                livenessStage = LivenessStage.READY_TO_CAPTURE,
                message = "Đang nhận diện khuôn mặt...",
                isError = false,
            )
        }
        extractAndPunch(frame.bitmap, singleFace, Instant.now())
    }

    private fun extractAndPunch(bitmap: Bitmap, face: Face, capturedAt: Instant) {
        val sface = engine ?: run {
            if (!bitmap.isRecycled) bitmap.recycle()
            busyProcessing = false
            return
        }
        val info = _ui.value.deviceInfo ?: run {
            if (!bitmap.isRecycled) bitmap.recycle()
            busyProcessing = false
            return
        }

        viewModelScope.launch {
            val extracted = withContext(Dispatchers.Default) {
                try {
                    Result.success(sface.extract(bitmap, face))
                } catch (e: Throwable) {
                    Result.failure(e)
                } finally {
                    if (!bitmap.isRecycled) bitmap.recycle()
                }
            }

            extracted.onFailure { err ->
                liveness.reset()
                _ui.update {
                    it.copy(
                        headline = "Chưa chụp được khuôn mặt",
                        message = err.message ?: "Vui lòng nhìn thẳng vào camera và thử lại.",
                        isError = true,
                    )
                }
                busyProcessing = false
                return@launch
            }

            val faceData = extracted.getOrThrow()
            when (val match = FaceMath.matchRoster(faceData.embedding, roster, info.minScore)) {
                is MatchOutcome.Uncertain -> {
                    liveness.reset()
                    val scoreNote = match.bestScore?.let { String.format(Locale.US, " (score %.2f < %.2f)", it, info.minScore) }.orEmpty()
                    _ui.update {
                        it.copy(
                            headline = "Chưa thể nhận diện",
                            message = if (roster.isEmpty()) {
                                "Chưa có nhân viên nào đăng ký khuôn mặt trên hệ thống."
                            } else {
                                "Không đủ chắc chắn để ghi công$scoreNote. Hãy thử lại hoặc liên hệ quản trị."
                            },
                            lastReceipt = null,
                            isError = true,
                        )
                    }
                    busyProcessing = false
                }

                is MatchOutcome.Matched -> {
                    val person = match.candidate.person
                    val score = match.candidate.score
                    runCatching {
                        api.submitAttendance(
                            baseUrl = store.getServerUrl(),
                            token = store.getDeviceToken(),
                            employeeId = person.id,
                            score = score,
                            evidenceJpeg = faceData.evidenceJpeg,
                            modelId = info.modelId,
                            modelVersion = info.modelVersion,
                            capturedAt = capturedAt,
                        )
                    }.onSuccess { receipt ->
                        val salutation = when (person.gender) {
                            "female" -> "chị"
                            "male" -> "anh"
                            else -> "anh/chị"
                        }
                        val formatted = formatVnTime(receipt.recordedAt)
                        val headlineText = if (receipt.reviewState == "accepted") {
                            if (receipt.direction == "both") "Chấm công thành công"
                            else "Chấm công thành công (${directionLabel(receipt.direction)})"
                        } else {
                            "Đã ghi nhận — chờ duyệt"
                        }
                        _ui.update {
                            it.copy(
                                lastReceipt = receipt,
                                headline = headlineText,
                                message = "Xin chào $salutation ${receipt.employeeName}! Ghi nhận lúc $formatted.",
                                isError = false,
                            )
                        }
                    }.onFailure { err ->
                        liveness.reset()
                        _ui.update {
                            it.copy(
                                lastReceipt = null,
                                headline = "Không ghi được công",
                                message = err.message ?: "Lỗi gửi lượt chấm công.",
                                isError = true,
                            )
                        }
                    }
                    busyProcessing = false
                }
            }
        }
    }

    override fun onCleared() {
        super.onCleared()
        engine?.close()
    }

    companion object {
        private val VN_ZONE: ZoneId = ZoneId.of("Asia/Ho_Chi_Minh")
        private val VN_FORMATTER: DateTimeFormatter =
            DateTimeFormatter.ofPattern("HH:mm:ss 'ngày' dd/MM/yyyy", Locale("vi", "VN"))

        private fun sortEmployeesForEnrollment(list: List<EmployeeOption>): List<EmployeeOption> =
            list.sortedWith(compareBy<EmployeeOption> { it.hasFace }.thenBy { it.code })

        fun formatVnTime(isoUtc: String): String =
            runCatching { Instant.parse(isoUtc).atZone(VN_ZONE).format(VN_FORMATTER) }.getOrDefault(isoUtc)

        fun directionLabel(direction: String): String = when (direction) {
            "both" -> "Vào / Ra"
            "in" -> "Cổng vào"
            "out" -> "Cổng ra"
            else -> direction
        }
    }
}
