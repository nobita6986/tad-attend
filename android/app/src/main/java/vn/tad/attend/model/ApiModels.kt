package vn.tad.attend.model

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class DeviceInfo(
    @SerialName("device_id") val deviceId: String,
    val name: String,
    val direction: String,
    @SerialName("server_time") val serverTime: String,
    @SerialName("model_id") val modelId: String,
    @SerialName("model_version") val modelVersion: String,
    @SerialName("embedding_dimensions") val embeddingDimensions: Int = 128,
    @SerialName("min_score") val minScore: Double,
)

@Serializable
data class EnrolledPerson(
    val id: Int,
    val code: String,
    @SerialName("full_name") val fullName: String,
    val gender: String? = null,
    val template: List<Double>,
)

@Serializable
data class RosterResponse(
    val employees: List<EnrolledPerson> = emptyList(),
)

@Serializable
data class ShiftOption(
    val id: Int,
    val code: String,
    val name: String,
    @SerialName("starts_at") val startsAt: String,
    @SerialName("ends_at") val endsAt: String,
)

@Serializable
data class EmployeeOption(
    val id: Int,
    val code: String,
    @SerialName("full_name") val fullName: String,
    val department: String? = null,
    @SerialName("shift_id") val shiftId: Int? = null,
    @SerialName("has_face") val hasFace: Boolean = false,
)

@Serializable
data class EnrollmentOptionsResponse(
    val shifts: List<ShiftOption> = emptyList(),
    val employees: List<EmployeeOption> = emptyList(),
)

@Serializable
data class EnrollmentResponse(
    @SerialName("employee_id") val employeeId: Int,
    @SerialName("enrolled_at") val enrolledAt: String,
)

@Serializable
data class DirectEnrollResponse(
    @SerialName("employee_id") val employeeId: Int,
    val code: String,
    @SerialName("full_name") val fullName: String,
    @SerialName("enrolled_at") val enrolledAt: String,
)

@Serializable
data class AttendanceReceipt(
    @SerialName("event_uuid") val eventUuid: String,
    @SerialName("employee_name") val employeeName: String,
    val direction: String,
    @SerialName("recorded_at") val recordedAt: String,
    @SerialName("review_state") val reviewState: String,
    val duplicate: Boolean = false,
)

@Serializable
data class ApiErrorBody(
    val message: String? = null,
)
