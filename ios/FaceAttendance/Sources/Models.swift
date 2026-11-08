import Foundation

struct DeviceInfo: Decodable {
    let device_id: String
    let name: String
    let direction: String
    let server_time: String
    let model_id: String
    let model_version: String
    let min_score: Double
}

struct EnrolledPerson: Decodable, Identifiable {
    let id: Int
    let code: String
    let full_name: String
    let gender: String?
    let template: [Double]
}

struct RosterResponse: Decodable { let employees: [EnrolledPerson] }
struct EnrollmentResponse: Decodable { let employee_id: Int; let enrolled_at: String }
struct AttendanceReceipt: Decodable {
    let event_uuid: String
    let employee_name: String
    let direction: String
    let recorded_at: String
    let review_state: String
    let duplicate: Bool
}

struct APIMessage: Decodable { let message: String? }

enum AppError: LocalizedError {
    case notConfigured, modelMissing, noSingleFace, badModelIO, badEmbedding, livenessNotVerified
    case network(String), recognitionUncertain
    var errorDescription: String? {
        switch self {
        case .notConfigured: return "Chưa cấu hình địa chỉ HTTPS và token thiết bị."
        case .modelMissing: return "Chưa có FaceEmbedding.mlmodelc. Không cho phép chấm công."
        case .noSingleFace: return "Cần đúng một khuôn mặt rõ ràng trong khung hình."
        case .badModelIO: return "Input/output của Core ML model không đúng hợp đồng."
        case .badEmbedding: return "Không tạo được đặc trưng khuôn mặt hợp lệ."
        case .livenessNotVerified: return "Chưa hoàn tất thử thách kiểm tra người thật."
        case .network(let text): return text
        case .recognitionUncertain: return "Không xác định chắc chắn. Vui lòng liên hệ quản trị."
        }
    }
}
