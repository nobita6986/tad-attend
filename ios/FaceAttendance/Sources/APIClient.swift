import Foundation

final class APIClient {
    private let decoder = JSONDecoder()
    private var baseURL: URL {
        get throws {
            guard let text = UserDefaults.standard.string(forKey: "baseURL"),
                  let url = URL(string: text.trimmingCharacters(in: .whitespacesAndNewlines)),
                  url.scheme?.lowercased() == "https", url.host != nil else { throw AppError.notConfigured }
            return url
        }
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
        guard let http = response as? HTTPURLResponse else { throw AppError.network("Máy chủ không phản hồi hợp lệ.") }
        guard 200..<300 ~= http.statusCode else {
            let reason = (try? decoder.decode(APIMessage.self, from: data))?.message ?? "Lỗi từ server"
            throw AppError.network("HTTP \(http.statusCode): \(reason)")
        }
        return try decoder.decode(T.self, from: data)
    }
    func deviceInfo() async throws -> DeviceInfo {
        try await send(request("device"), DeviceInfo.self)
    }
    func roster(modelID: String, version: String) async throws -> [EnrolledPerson] {
        var components = URLComponents(url: try request("roster").url!, resolvingAgainstBaseURL: false)!
        components.queryItems = [URLQueryItem(name: "model_id", value: modelID), URLQueryItem(name: "model_version", value: version)]
        var req = try request("roster"); req.url = components.url
        return try await send(req, RosterResponse.self).employees
    }
    func enroll(ticket: String, modelID: String, version: String, vector: [Double]) async throws -> EnrollmentResponse {
        var req = try request("enrollments", method: "POST")
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.httpBody = try JSONSerialization.data(withJSONObject: [
            "ticket": ticket, "model_id": modelID, "model_version": version, "embedding": vector
        ])
        return try await send(req, EnrollmentResponse.self)
    }
    func attendance(person: EnrolledPerson, score: Double, image: Data,
                    modelID: String, version: String, capturedAt: Date) async throws -> AttendanceReceipt {
        let boundary = "Boundary-\(UUID().uuidString)"
        var req = try request("attendance", method: "POST")
        req.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        let formatter = ISO8601DateFormatter()
        var body = Data()
        func field(_ name: String, _ value: String) {
            body.append(Data("--\(boundary)\r\nContent-Disposition: form-data; name=\"\(name)\"\r\n\r\n\(value)\r\n".utf8))
        }
        // TODO offline queue: reuse the same event_uuid across network retries instead of generating a new one.
        field("event_uuid", UUID().uuidString)
        field("employee_id", String(person.id))
        field("captured_at", formatter.string(from: capturedAt))
        field("match_score", String(score))
        field("liveness_passed", "1")
        field("liveness_method", "arkit_head_turn")
        field("model_id", modelID)
        field("model_version", version)
        field("offline", "0")
        body.append(Data("--\(boundary)\r\nContent-Disposition: form-data; name=\"image\"; filename=\"evidence.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".utf8))
        body.append(image)
        body.append(Data("\r\n--\(boundary)--\r\n".utf8))
        req.httpBody = body
        req.timeoutInterval = 30
        return try await send(req, AttendanceReceipt.self)
    }
}
