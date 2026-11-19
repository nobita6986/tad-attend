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
                    """{"device_id":"d-1","name":"Cổng Android","direction":"in","server_time":"2026-11-19T10:00:00Z","model_id":"sface","model_version":"2021dec","embedding_dimensions":128,"min_score":0.42}""",
                ),
        )
        val info = client.getDeviceInfo(baseUrl, "secret-token")
        assertEquals("sface", info.modelId)
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
        assertTrue(bodyText.contains("name=\"liveness_method\"\r\n\r\nmlkit_head_turn"))
        assertTrue(bodyText.contains("name=\"model_id\"\r\n\r\nsface"))
        assertTrue(bodyText.contains("filename=\"evidence.jpg\""))
    }
}
