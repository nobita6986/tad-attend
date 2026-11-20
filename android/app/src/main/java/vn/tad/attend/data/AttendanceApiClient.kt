package vn.tad.attend.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import vn.tad.attend.model.ApiErrorBody
import vn.tad.attend.model.AttendanceReceipt
import vn.tad.attend.model.DeviceActivationResponse
import vn.tad.attend.model.DeviceInfo
import vn.tad.attend.model.DeviceRegistrationResponse
import vn.tad.attend.model.DirectEnrollResponse
import vn.tad.attend.model.EnrolledPerson
import vn.tad.attend.model.EnrollmentOptionsResponse
import vn.tad.attend.model.EnrollmentResponse
import vn.tad.attend.model.RosterResponse
import java.io.IOException
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException
import java.time.Instant
import java.util.Locale
import java.util.UUID
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLException

class ApiException(
    val statusCode: Int?,
    message: String,
    val errorStatus: String? = null,
) : IOException(message)

@Serializable
private data class DeviceActivatePayload(
    val hardware_id: String,
    val device_model: String? = null,
)

@Serializable
private data class DeviceRegisterPayload(
    val hardware_id: String,
    val name: String? = null,
    val device_model: String? = null,
)

@Serializable
private data class EnrollmentPayload(
    val ticket: String,
    val model_id: String,
    val model_version: String,
    val embedding: List<Double>,
)

@Serializable
private data class DirectCreateEnrollPayload(
    val mode: String = "create",
    val code: String,
    val full_name: String,
    val gender: String? = null,
    val department: String? = null,
    val shift_id: Int? = null,
    val consent_confirmed: Boolean = true,
    val model_id: String,
    val model_version: String,
    val embedding: List<Double>,
)

@Serializable
private data class DirectExistingEnrollPayload(
    val mode: String = "existing",
    val employee_id: Int,
    val consent_confirmed: Boolean = true,
    val model_id: String,
    val model_version: String,
    val embedding: List<Double>,
)

class AttendanceApiClient(
    private val httpClient: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(25, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        .build(),
    private val allowCleartextForTest: Boolean = false,
) {
    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        encodeDefaults = true
    }

    private fun buildUrl(baseUrl: String, path: String, query: Map<String, String> = emptyMap()): okhttp3.HttpUrl {
        val trimmed = baseUrl.trim().trimEnd('/')
        val parsed = trimmed.toHttpUrlOrNull()
            ?: throw ApiException(null, "Địa chỉ máy chủ chưa đúng. Vui lòng báo quản lý kiểm tra.")
        if (!allowCleartextForTest && parsed.scheme != "https") {
            throw ApiException(null, "Địa chỉ máy chủ cần dùng kết nối bảo mật (https://).")
        }
        val builder = parsed.newBuilder().addPathSegments("api/v1/$path")
        for ((k, v) in query) {
            builder.addQueryParameter(k, v)
        }
        return builder.build()
    }

    private fun requireToken(token: String?): String {
        val clean = token?.trim().orEmpty()
        if (clean.isEmpty()) throw ApiException(null, "Máy chưa được kết nối. Vui lòng bấm Đăng ký thiết bị.")
        return clean
    }

    private fun defaultMessageForCode(code: Int): String = when (code) {
        401 -> "Phiên kết nối của máy đã hết hạn. Vui lòng thử kết nối lại."
        403 -> "Máy chấm công chưa được cấp quyền hoạt động."
        404 -> "Không tìm thấy thông tin thiết bị trên hệ thống."
        409 -> "Bạn vừa chấm công trước đó rồi."
        422 -> "Thông tin gửi lên chưa đầy đủ hoặc chưa đúng."
        429 -> "Thao tác quá nhanh. Vui lòng đợi vài giây rồi thử lại."
        in 500..599 -> "Hệ thống đang bận. Vui lòng thử lại sau ít phút."
        else -> "Không thể kết nối máy chủ. Vui lòng kiểm tra mạng và thử lại."
    }

    private suspend inline fun <reified T> executeJson(request: Request): T = withContext(Dispatchers.IO) {
        val response = try {
            httpClient.newCall(request).execute()
        } catch (_: UnknownHostException) {
            throw ApiException(null, "Không có kết nối mạng. Vui lòng kiểm tra Wi-Fi hoặc 4G.")
        } catch (_: ConnectException) {
            throw ApiException(null, "Không kết nối được tới máy chủ. Vui lòng kiểm tra mạng.")
        } catch (_: SocketTimeoutException) {
            throw ApiException(null, "Kết nối mạng hơi chậm. Vui lòng thử lại.")
        } catch (_: SSLException) {
            throw ApiException(null, "Không thể xác thực kết nối an toàn tới máy chủ. Kiểm tra lại ngày giờ trên điện thoại.")
        } catch (_: IOException) {
            throw ApiException(null, "Mạng đang không ổn định. Vui lòng thử lại sau vài giây.")
        }
        response.use { res ->
            val raw = res.body?.string().orEmpty()
            if (!res.isSuccessful) {
                val errBody = runCatching { json.decodeFromString<ApiErrorBody>(raw) }.getOrNull()
                val detail = errBody?.message?.takeIf { it.isNotBlank() } ?: defaultMessageForCode(res.code)
                throw ApiException(res.code, detail, errBody?.status)
            }
            try {
                json.decodeFromString<T>(raw)
            } catch (_: Throwable) {
                throw ApiException(res.code, "Máy chủ phản hồi chưa đúng định dạng. Vui lòng thử lại.")
            }
        }
    }

    suspend fun registerByHardwareId(
        baseUrl: String,
        hardwareId: String,
        name: String? = null,
        deviceModel: String? = null,
    ): DeviceRegistrationResponse {
        val payload = DeviceRegisterPayload(
            hardware_id = hardwareId.trim().uppercase(Locale.US),
            name = name?.trim()?.takeIf { it.isNotEmpty() },
            device_model = deviceModel?.trim()?.takeIf { it.isNotEmpty() },
        )
        val body = json.encodeToString(payload).toRequestBody("application/json; charset=utf-8".toMediaType())
        val req = Request.Builder()
            .url(buildUrl(baseUrl, "devices/register"))
            .header("Accept", "application/json")
            .post(body)
            .build()
        return executeJson(req)
    }

    suspend fun activateByHardwareId(
        baseUrl: String,
        hardwareId: String,
        deviceModel: String? = null,
    ): DeviceActivationResponse {
        val payload = DeviceActivatePayload(
            hardware_id = hardwareId.trim().uppercase(Locale.US),
            device_model = deviceModel?.trim()?.takeIf { it.isNotEmpty() },
        )
        val body = json.encodeToString(payload).toRequestBody("application/json; charset=utf-8".toMediaType())
        val req = Request.Builder()
            .url(buildUrl(baseUrl, "devices/activate"))
            .header("Accept", "application/json")
            .post(body)
            .build()
        return executeJson(req)
    }

    suspend fun getDeviceInfo(baseUrl: String, token: String?): DeviceInfo {
        val req = Request.Builder()
            .url(buildUrl(baseUrl, "device"))
            .header("Authorization", "Bearer ${requireToken(token)}")
            .header("Accept", "application/json")
            .get()
            .build()
        return executeJson(req)
    }

    suspend fun getRoster(
        baseUrl: String,
        token: String?,
        modelId: String,
        modelVersion: String,
    ): List<EnrolledPerson> {
        val req = Request.Builder()
            .url(buildUrl(baseUrl, "roster", mapOf("model_id" to modelId, "model_version" to modelVersion)))
            .header("Authorization", "Bearer ${requireToken(token)}")
            .header("Accept", "application/json")
            .get()
            .build()
        return executeJson<RosterResponse>(req).employees
    }

    suspend fun getEnrollmentOptions(
        baseUrl: String,
        token: String?,
    ): EnrollmentOptionsResponse {
        val req = Request.Builder()
            .url(buildUrl(baseUrl, "enrollment-options"))
            .header("Authorization", "Bearer ${requireToken(token)}")
            .header("Accept", "application/json")
            .get()
            .build()
        return executeJson(req)
    }

    suspend fun createAndEnrollEmployee(
        baseUrl: String,
        token: String?,
        code: String,
        fullName: String,
        gender: String?,
        department: String?,
        shiftId: Int?,
        modelId: String,
        modelVersion: String,
        embedding: DoubleArray,
    ): DirectEnrollResponse {
        val payload = DirectCreateEnrollPayload(
            code = code.trim().uppercase(Locale.US),
            full_name = fullName.trim(),
            gender = gender?.takeIf { it.isNotBlank() },
            department = department?.trim()?.takeIf { it.isNotEmpty() },
            shift_id = shiftId,
            consent_confirmed = true,
            model_id = modelId,
            model_version = modelVersion,
            embedding = embedding.toList(),
        )
        val body = json.encodeToString(payload).toRequestBody("application/json; charset=utf-8".toMediaType())
        val req = Request.Builder()
            .url(buildUrl(baseUrl, "employees/enroll"))
            .header("Authorization", "Bearer ${requireToken(token)}")
            .header("Accept", "application/json")
            .post(body)
            .build()
        return executeJson(req)
    }

    suspend fun enrollExistingEmployee(
        baseUrl: String,
        token: String?,
        employeeId: Int,
        modelId: String,
        modelVersion: String,
        embedding: DoubleArray,
    ): DirectEnrollResponse {
        val payload = DirectExistingEnrollPayload(
            employee_id = employeeId,
            consent_confirmed = true,
            model_id = modelId,
            model_version = modelVersion,
            embedding = embedding.toList(),
        )
        val body = json.encodeToString(payload).toRequestBody("application/json; charset=utf-8".toMediaType())
        val req = Request.Builder()
            .url(buildUrl(baseUrl, "employees/enroll"))
            .header("Authorization", "Bearer ${requireToken(token)}")
            .header("Accept", "application/json")
            .post(body)
            .build()
        return executeJson(req)
    }

    suspend fun enroll(
        baseUrl: String,
        token: String?,
        ticket: String,
        modelId: String,
        modelVersion: String,
        embedding: DoubleArray,
    ): EnrollmentResponse {
        val payload = EnrollmentPayload(
            ticket = ticket.trim().uppercase(Locale.US),
            model_id = modelId,
            model_version = modelVersion,
            embedding = embedding.toList(),
        )
        val body = json.encodeToString(payload).toRequestBody("application/json; charset=utf-8".toMediaType())
        val req = Request.Builder()
            .url(buildUrl(baseUrl, "enrollments"))
            .header("Authorization", "Bearer ${requireToken(token)}")
            .header("Accept", "application/json")
            .post(body)
            .build()
        return executeJson(req)
    }

    suspend fun submitAttendance(
        baseUrl: String,
        token: String?,
        employeeId: Int,
        score: Double,
        evidenceJpeg: ByteArray,
        modelId: String,
        modelVersion: String,
        capturedAt: Instant = Instant.now(),
        eventUuid: String = UUID.randomUUID().toString(),
    ): AttendanceReceipt {
        val multipart = MultipartBody.Builder()
            .setType(MultipartBody.FORM)
            .addFormDataPart("event_uuid", eventUuid)
            .addFormDataPart("employee_id", employeeId.toString())
            .addFormDataPart("captured_at", capturedAt.toString())
            .addFormDataPart("match_score", String.format(Locale.US, "%.6f", score))
            .addFormDataPart("liveness_passed", "1")
            .addFormDataPart("liveness_method", "mlkit_head_turn")
            .addFormDataPart("model_id", modelId)
            .addFormDataPart("model_version", modelVersion)
            .addFormDataPart("offline", "0")
            .addFormDataPart(
                "image",
                "evidence.jpg",
                evidenceJpeg.toRequestBody("image/jpeg".toMediaType()),
            )
            .build()

        val req = Request.Builder()
            .url(buildUrl(baseUrl, "attendance"))
            .header("Authorization", "Bearer ${requireToken(token)}")
            .header("Accept", "application/json")
            .post(multipart)
            .build()
        return executeJson(req)
    }
}
