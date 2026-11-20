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
import java.time.Instant
import java.util.Locale
import java.util.UUID
import java.util.concurrent.TimeUnit

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
            ?: throw ApiException(null, "Địa chỉ máy chủ không hợp lệ.")
        if (!allowCleartextForTest && parsed.scheme != "https") {
            throw ApiException(null, "Máy chủ bắt buộc dùng HTTPS.")
        }
        val builder = parsed.newBuilder().addPathSegments("api/v1/$path")
        for ((k, v) in query) {
            builder.addQueryParameter(k, v)
        }
        return builder.build()
    }

    private fun requireToken(token: String?): String {
        val clean = token?.trim().orEmpty()
        if (clean.isEmpty()) throw ApiException(null, "Chưa nhập token thiết bị trong mục Cấu hình.")
        return clean
    }

    private suspend inline fun <reified T> executeJson(request: Request): T = withContext(Dispatchers.IO) {
        httpClient.newCall(request).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                val errBody = runCatching { json.decodeFromString<ApiErrorBody>(raw) }.getOrNull()
                val detail = errBody?.message?.takeIf { it.isNotBlank() } ?: "Lỗi kết nối máy chủ"
                throw ApiException(response.code, "HTTP ${response.code}: $detail", errBody?.status)
            }
            json.decodeFromString<T>(raw)
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
