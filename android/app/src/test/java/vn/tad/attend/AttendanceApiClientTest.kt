package vn.tad.attend

import kotlinx.coroutines.test.runTest
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import vn.tad.attend.data.ApiException
import vn.tad.attend.data.AttendanceApiClient

class AttendanceApiClientTest {
    private lateinit var server: MockWebServer

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun rejectsCleartextHttpInProductionMode() = runTest {
        val client = AttendanceApiClient(allowCleartextForTest = false)
        var threw = false
        try {
            client.getDeviceInfo("http://example.com", "token-123")
        } catch (e: ApiException) {
            threw = true
            assertTrue(e.message!!.contains("HTTPS"))
        }
        assertTrue(threw)
    }

    @Test
    fun fetchesDeviceInfoAndSubmitsAndroidAttendanceMultipart() = runTest {
        val client = AttendanceApiClient(allowCleartextForTest = true)
        val baseUrl = server.url("/").toString().trimEnd('/')

        server.enqueue(
            MockResponse()
                .setResponseCode(200)
                .setBody(
                    """{"device_id":"d-1","name":"Cổng Android","direction":"both","server_time":"2026-11-19T10:00:00Z","model_id":"sface","model_version":"2021dec","embedding_dimensions":128,"min_score":0.42}""",
                ),
        )
        val info = client.getDeviceInfo(baseUrl, "secret-token")
        assertEquals("sface", info.modelId)
        assertEquals("both", info.direction)
        assertEquals(128, info.embeddingDimensions)
        val deviceReq = server.takeRequest()
        assertEquals("Bearer secret-token", deviceReq.getHeader("Authorization"))
        assertEquals("/api/v1/device", deviceReq.path)

        server.enqueue(
            MockResponse()
                .setResponseCode(201)
                .setBody(
                    """{"event_uuid":"u-1","employee_name":"Nguyễn Văn A","direction":"in","recorded_at":"2026-11-19T10:00:05Z","review_state":"accepted","duplicate":false}""",
                ),
        )
        val receipt = client.submitAttendance(
            baseUrl = baseUrl,
            token = "secret-token",
            employeeId = 7,
            score = 0.72,
            evidenceJpeg = byteArrayOf(0xFF.toByte(), 0xD8.toByte(), 0xFF.toByte()),
            modelId = "sface",
            modelVersion = "2021dec",
            eventUuid = "u-1",
        )
        assertEquals("accepted", receipt.reviewState)
        val punchReq = server.takeRequest()
        val bodyText = punchReq.body.readUtf8()
        assertTrue(Regex("""name="liveness_method"\r\n(?:[^\r\n]+\r\n)*\r\nmlkit_head_turn""").containsMatchIn(bodyText))
        assertTrue(Regex("""name="model_id"\r\n(?:[^\r\n]+\r\n)*\r\nsface""").containsMatchIn(bodyText))
        assertTrue(bodyText.contains("filename=\"evidence.jpg\""))
    }

    @Test
    fun fetchesEnrollmentOptionsAndDirectEnrollsNewEmployee() = runTest {
        val client = AttendanceApiClient(allowCleartextForTest = true)
        val baseUrl = server.url("/").toString().trimEnd('/')

        server.enqueue(
            MockResponse()
                .setResponseCode(200)
                .setBody(
                    """{"shifts":[{"id":1,"code":"HC","name":"Hành chính","starts_at":"08:00:00","ends_at":"17:30:00"}],"employees":[{"id":5,"code":"NV005","full_name":"Trần B","department":"IT","shift_id":1,"has_face":false}]}""",
                ),
        )
        val options = client.getEnrollmentOptions(baseUrl, "tok")
        assertEquals(1, options.shifts.size)
        assertEquals("NV005", options.employees.first().code)
        assertEquals("/api/v1/enrollment-options", server.takeRequest().path)

        server.enqueue(
            MockResponse()
                .setResponseCode(201)
                .setBody("""{"employee_id":9,"code":"NV009","full_name":"Lê C","enrolled_at":"2026-11-20T01:45:00Z"}"""),
        )
        val created = client.createAndEnrollEmployee(
            baseUrl = baseUrl,
            token = "tok",
            code = "nv009",
            fullName = "Lê C",
            gender = "male",
            department = "Kho",
            shiftId = 1,
            modelId = "sface",
            modelVersion = "2021dec",
            embedding = DoubleArray(128) { if (it == 0) 1.0 else 0.0 },
        )
        assertEquals(9, created.employeeId)
        assertEquals("NV009", created.code)
        val createReq = server.takeRequest()
        assertEquals("/api/v1/employees/enroll", createReq.path)
        val jsonBody = createReq.body.readUtf8()
        assertTrue(jsonBody.contains("\"mode\":\"create\""))
        assertTrue(jsonBody.contains("\"code\":\"NV009\""))
        assertTrue(jsonBody.contains("\"consent_confirmed\":true"))
    }

    @Test
    fun activatesDeviceByFixedHardwareId() = runTest {
        val client = AttendanceApiClient(allowCleartextForTest = true)
        val baseUrl = server.url("/").toString().trimEnd('/')

        val formatted = vn.tad.attend.security.DeviceIdentity.formatHardwareId("a1b2c3d4e5f67890")
        assertEquals("AND-A1B2-C3D4-E5F6-7890", formatted)

        server.enqueue(
            MockResponse()
                .setResponseCode(200)
                .setBody(
                    """{"token":"new-tok-64","device_id":"d-2","name":"TB2","direction":"both","hardware_id":"AND-A1B2-C3D4-E5F6-7890"}""",
                ),
        )
        val act = client.activateByHardwareId(baseUrl, formatted)
        assertEquals("new-tok-64", act.token)
        assertEquals("TB2", act.name)
        val req = server.takeRequest()
        assertEquals("/api/v1/devices/activate", req.path)
        assertTrue(req.body.readUtf8().contains("AND-A1B2-C3D4-E5F6-7890"))
    }

    @Test
    fun registersDeviceAndParsesPendingRejectedStatus() = runTest {
        val client = AttendanceApiClient(allowCleartextForTest = true)
        val baseUrl = server.url("/").toString().trimEnd('/')

        server.enqueue(
            MockResponse()
                .setResponseCode(202)
                .setBody(
                    """{"status":"pending","device_id":"d-3","name":"Cổng chính","hardware_id":"AND-1111-2222-3333-4444","message":"Đang chờ duyệt"}""",
                ),
        )
        val reg = client.registerByHardwareId(
            baseUrl = baseUrl,
            hardwareId = "and-1111-2222-3333-4444",
            name = "Cổng chính",
            deviceModel = "Xiaomi 23049PCD8G (Android 14)",
        )
        assertEquals("pending", reg.status)
        assertEquals("d-3", reg.deviceId)
        val regReq = server.takeRequest()
        assertEquals("/api/v1/devices/register", regReq.path)
        val regBody = regReq.body.readUtf8()
        assertTrue(regBody.contains("AND-1111-2222-3333-4444"))
        assertTrue(regBody.contains("Xiaomi 23049PCD8G"))

        server.enqueue(
            MockResponse()
                .setResponseCode(403)
                .setBody("""{"status":"rejected","message":"Bị từ chối"}"""),
        )
        var caught: vn.tad.attend.data.ApiException? = null
        try {
            client.activateByHardwareId(baseUrl, "AND-1111-2222-3333-4444")
        } catch (e: vn.tad.attend.data.ApiException) {
            caught = e
        }
        assertEquals(403, caught?.statusCode)
        assertEquals("rejected", caught?.errorStatus)
    }
}
