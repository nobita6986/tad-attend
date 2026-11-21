import Foundation

struct BranchInfo: Decodable, Equatable {
    let id: Int
    let code: String
    let name: String
    let region: String
    let active: Bool?
    let latitude: Double?
    let longitude: Double?
    let allowed_radius_meters: Int?
    let enforce_geofence: Bool?

    var hasCoordinates: Bool { latitude != nil && longitude != nil }
    var hasEnforcedGeofence: Bool {
        (enforce_geofence ?? false) && hasCoordinates && (allowed_radius_meters ?? 0) > 0
    }
}

struct DeviceInfo: Decodable {
    let device_id: String
    let name: String
    let direction: String
    let hardware_id: String?
    let branch: BranchInfo?
    let server_time: String
    let model_id: String
    let model_version: String
    let embedding_dimensions: Int?
    let min_score: Double
}

struct NearestBranchSummary: Decodable, Equatable {
    let id: Int
    let code: String
    let name: String
}

struct DeviceRegistrationResponse: Decodable {
    let status: String
    let message: String?
    let token: String?
    let device_id: String?
    let name: String?
    let direction: String?
    let hardware_id: String?
    let nearest_branch: NearestBranchSummary?
    let nearest_distance_meters: Double?
}

struct EnrolledPerson: Decodable, Identifiable {
    let id: Int
    let code: String
    let full_name: String
    let gender: String?
    let template: [Double]
}

struct RosterResponse: Decodable {
    let employees: [EnrolledPerson]
}

struct ShiftOption: Decodable, Identifiable, Hashable {
    let id: Int
    let code: String
    let name: String
    let starts_at: String
    let ends_at: String
}

struct EnrollmentEmployee: Decodable, Identifiable, Hashable {
    let id: Int
    let code: String
    let full_name: String
    let department: String?
    let shift_id: Int?
    let has_face: Bool
}

struct EnrollmentOptionsResponse: Decodable {
    let next_employee_code: String
    let shifts: [ShiftOption]
    let employees: [EnrollmentEmployee]
}

struct DirectEnrollResponse: Decodable {
    let employee_id: Int
    let code: String
    let full_name: String
    let enrolled_at: String
}

struct EnrollmentResponse: Decodable {
    let employee_id: Int
    let enrolled_at: String
}

struct AttendanceReceipt: Decodable {
    let event_uuid: String
    let employee_name: String
    let direction: String
    let recorded_at: String
    let review_state: String
    let duplicate: Bool
}

struct LocationSnapshot: Codable, Equatable {
    let latitude: Double
    let longitude: Double
    let accuracyMeters: Double
    let capturedAt: Date
}

struct APIMessage: Decodable {
    let message: String?
}

enum AppError: LocalizedError {
    case notConfigured
    case modelMissing
    case noSingleFace
    case badModelIO
    case badEmbedding
    case livenessNotVerified
    case locationUnavailable
    case outsideGeofence(distance: Double, limit: Int, branchName: String)
    case network(String)
    case recognitionUncertain

    var errorDescription: String? {
        switch self {
        case .notConfigured:
            return "Máy chưa được kích hoạt. Nhờ quản lý mở mục Cấu hình để đăng ký máy."
        case .modelMissing:
            return "Không khởi động được tính năng nhận diện khuôn mặt."
        case .noSingleFace:
            return "Vui lòng đứng 1 mình, đưa khuôn mặt rõ vào giữa khung hình."
        case .badModelIO:
            return "Chưa chụp rõ khuôn mặt. Vui lòng giữ yên và thử lại."
        case .badEmbedding:
            return "Chưa nhận diện rõ khuôn mặt. Vui lòng đứng nơi đủ sáng và thử lại."
        case .livenessNotVerified:
            return "Vui lòng quay nhẹ đầu sang trái hoặc phải rồi nhìn thẳng."
        case .locationUnavailable:
            return "Chưa xác định được vị trí. Vui lòng bật Định vị (GPS) trên điện thoại."
        case .outsideGeofence(let distance, let limit, let branchName):
            return "Bạn đang cách \(branchName) khoảng \(Int(distance.rounded())) m (cho phép tối đa \(limit) m)."
        case .network(let text):
            return text
        case .recognitionUncertain:
            return "Chưa nhận diện được. Vui lòng nhìn thẳng, đủ sáng và thử lại."
        }
    }
}
