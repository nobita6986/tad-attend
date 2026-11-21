import Foundation
import UIKit

final class APIClient {
    static let defaultServerURL = "https://chamcong.tananhduong.com.vn"
    private let decoder = JSONDecoder()

    var configuredBaseURLString: String {
        let stored = UserDefaults.standard.string(forKey: "baseURL")?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        return stored.isEmpty ? Self.defaultServerURL : stored
    }

    private var baseURL: URL {
        get throws {
            guard let url = URL(string: configuredBaseURLString),
                  url.scheme?.lowercased() == "https", url.host != nil else {
                throw AppError.notConfigured
            }
            return url
        }
    }

    private func unauthenticatedRequest(_ path: String, method: String = "POST") throws -> URLRequest {
        var req = URLRequest(url: try baseURL.appendingPathComponent("api/v1/" + path))
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.timeoutInterval = 20
        return req
    }

    private func request(_ path: String, method: String = "GET") throws -> URLRequest {
        guard let token = DeviceTokenStore.load(), !token.isEmpty else { throw AppError.notConfigured }
        var req = URLRequest(url: try baseURL.appendingPathComponent("api/v1/" + path))
        req.httpMethod = method
        req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        req.timeoutInterval = 20
        return req
    }

    private func send<T: Decodable>(_ req: URLRequest, _: T.Type) async throws -> T {
        let (data, response) = try await URLSession.shared.data(for: req)
        guard let http = response as? HTTPURLResponse else {
            throw AppError.network("Máy chủ không phản hồi hợp lệ.")
        }
        guard 200..<300 ~= http.statusCode else {
            let reason = (try? decoder.decode(APIMessage.self, from: data))?.message ?? "Lỗi từ máy chủ"
            throw AppError.network("HTTP \(http.statusCode): \(reason)")
        }
        return try decoder.decode(T.self, from: data)
    }

    // MARK: - Self-service device registration & activation

    func registerDevice(hardwareID: String, name: String?, deviceModel: String,
                        location: LocationSnapshot?) async throws -> DeviceRegistrationResponse {
        var req = try unauthenticatedRequest("devices/register", method: "POST")
        var payload: [String: Any] = [
            "hardware_id": hardwareID,
            "device_model": deviceModel,
        ]
        if let name, !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            payload["name"] = name.trimmingCharacters(in: .whitespacesAndNewlines)
        }
        if let location {
            payload["latitude"] = location.latitude
            payload["longitude"] = location.longitude
            payload["gps_accuracy_meters"] = location.accuracyMeters
        }
        req.httpBody = try JSONSerialization.data(withJSONObject: payload)
        let (data, response) = try await URLSession.shared.data(for: req)
        guard let http = response as? HTTPURLResponse else {
            throw AppError.network("Máy chủ không phản hồi hợp lệ.")
        }
        if let parsed = try? decoder.decode(DeviceRegistrationResponse.self, from: data),
           (200..<300).contains(http.statusCode) || http.statusCode == 403 {
            return parsed
        }
        let reason = (try? decoder.decode(APIMessage.self, from: data))?.message ?? "Không thể gửi yêu cầu đăng ký."
        throw AppError.network("HTTP \(http.statusCode): \(reason)")
    }

    func activateDevice(hardwareID: String, deviceModel: String) async throws -> DeviceRegistrationResponse {
        var req = try unauthenticatedRequest("devices/activate", method: "POST")
        req.httpBody = try JSONSerialization.data(withJSONObject: [
            "hardware_id": hardwareID,
            "device_model": deviceModel,
        ])
        let (data, response) = try await URLSession.shared.data(for: req)
        guard let http = response as? HTTPURLResponse else {
            throw AppError.network("Máy chủ không phản hồi hợp lệ.")
        }
        if let parsed = try? decoder.decode(DeviceRegistrationResponse.self, from: data),
           (200..<300).contains(http.statusCode) || http.statusCode == 403 || http.statusCode == 404 {
            return parsed
        }
        let reason = (try? decoder.decode(APIMessage.self, from: data))?.message ?? "Không thể kích hoạt thiết bị."
        throw AppError.network("HTTP \(http.statusCode): \(reason)")
    }

    // MARK: - Authenticated Kiosk & Enrollment APIs

    func deviceInfo() async throws -> DeviceInfo {
        try await send(request("device"), DeviceInfo.self)
    }

    func roster(modelID: String, version: String) async throws -> [EnrolledPerson] {
        var components = URLComponents(url: try request("roster").url!, resolvingAgainstBaseURL: false)!
        components.queryItems = [
            URLQueryItem(name: "model_id", value: modelID),
            URLQueryItem(name: "model_version", value: version),
        ]
        var req = try request("roster")
        req.url = components.url
        return try await send(req, RosterResponse.self).employees
    }

    func enrollmentOptions() async throws -> EnrollmentOptionsResponse {
        try await send(request("enrollment-options"), EnrollmentOptionsResponse.self)
    }

    func directEnroll(mode: String, employeeID: Int?, code: String?, fullName: String?,
                      department: String?, shiftID: Int?, modelID: String, version: String,
                      vector: [Double]) async throws -> DirectEnrollResponse {
        var req = try request("employees/enroll", method: "POST")
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        var payload: [String: Any] = [
            "mode": mode,
            "consent_confirmed": true,
            "model_id": modelID,
            "model_version": version,
            "embedding": vector,
        ]
        if let employeeID { payload["employee_id"] = employeeID }
        if let code, !code.isEmpty { payload["code"] = code }
        if let fullName, !fullName.isEmpty { payload["full_name"] = fullName }
        if let department, !department.isEmpty { payload["department"] = department }
        if let shiftID { payload["shift_id"] = shiftID }
        req.httpBody = try JSONSerialization.data(withJSONObject: payload)
        return try await send(req, DirectEnrollResponse.self)
    }

    func attendance(eventUUID: String, employeeID: Int, score: Double, image: Data,
                    modelID: String, version: String, capturedAt: Date,
                    offline: Bool, location: LocationSnapshot?) async throws -> AttendanceReceipt {
        let boundary = "Boundary-\(UUID().uuidString)"
        var req = try request("attendance", method: "POST")
        req.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        let formatter = ISO8601DateFormatter()
        var body = Data()
        func field(_ name: String, _ value: String) {
            body.append(Data("--\(boundary)\r\nContent-Disposition: form-data; name=\"\(name)\"\r\n\r\n\(value)\r\n".utf8))
        }
        field("event_uuid", eventUUID)
        field("employee_id", String(employeeID))
        field("captured_at", formatter.string(from: capturedAt))
        field("match_score", String(score))
        field("liveness_passed", "1")
        field("liveness_method", "arkit_head_turn")
        field("model_id", modelID)
        field("model_version", version)
        field("offline", offline ? "1" : "0")
        if let location {
            field("latitude", String(format: "%.7f", location.latitude))
            field("longitude", String(format: "%.7f", location.longitude))
            field("gps_accuracy_meters", String(format: "%.2f", location.accuracyMeters))
        }
        body.append(Data("--\(boundary)\r\nContent-Disposition: form-data; name=\"image\"; filename=\"evidence.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".utf8))
        body.append(image)
        body.append(Data("\r\n--\(boundary)--\r\n".utf8))
        req.httpBody = body
        req.timeoutInterval = 30
        return try await send(req, AttendanceReceipt.self)
    }
}
