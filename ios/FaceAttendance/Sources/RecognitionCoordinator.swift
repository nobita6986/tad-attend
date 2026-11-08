import ARKit
import Foundation
import SwiftUI

@MainActor
final class RecognitionCoordinator: ObservableObject {
    enum Mode { case kiosk, enrollment }
    @Published var message = "Cấu hình máy chủ để bắt đầu."
    @Published var headline = "Sẵn sàng"
    @Published var deviceInfo: DeviceInfo?
    @Published var enrollmentCount = 0
    @Published var isRunning = false
    @Published var lastReceipt: AttendanceReceipt?

    let tracker = FaceTrackingService()
    private let api = APIClient()
    private var engine: FaceEmbeddingEngine?
    private var roster: [EnrolledPerson] = []
    private var mode: Mode = .kiosk
    private var enrollmentTicket = ""
    private var samples: [[Double]] = []
    private var working = false
    private var lastAnalysis = Date.distantPast
    private var sawLeft = false, sawRight = false
    private var armed = true
    private var absentSince: Date?

    init() {
        tracker.onFrame = { [weak self] buffer, yaw, hasFace in
            Task { @MainActor in self?.onFrame(buffer: buffer, yaw: yaw, hasFace: hasFace) }
        }
    }

    func connect() async {
        tracker.stop(); isRunning = false; lastReceipt = nil
        do {
            // Fail closed: no model = no attendance.
            engine = try FaceEmbeddingEngine()
            let info = try await api.deviceInfo()
            guard info.model_id != "UNCONFIGURED", info.model_version != "UNCONFIGURED" else {
                throw AppError.notConfigured
            }
            let people = try await api.roster(modelID: info.model_id, version: info.model_version)
            deviceInfo = info; roster = people; mode = .kiosk
            resetChallenge()
            try tracker.start()
            isRunning = true
            headline = "Chấm công tại \(info.name)"
            message = "Đưa một khuôn mặt vào khung, quay đầu theo hướng dẫn. Đã đồng bộ \(people.count) nhân viên."
        } catch {
            engine = nil
            headline = "Chưa thể chấm công"
            message = error.localizedDescription
        }
    }

    func beginEnrollment(ticket: String) async {
        guard isRunning else { message = "Hãy kết nối server trước."; return }
        guard !ticket.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { message = "Nhập mã đăng ký từ quản trị."; return }
        mode = .enrollment
        enrollmentTicket = ticket.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        samples = []
        enrollmentCount = 0
        message = "Giữ khuôn mặt rõ và di chuyển nhẹ, cần 5 mẫu."
    }

    func cancelEnrollment() {
        mode = .kiosk; samples = []; enrollmentCount = 0; resetChallenge()
        message = "Trở về chế độ chấm công."
    }

    private func resetChallenge() {
        sawLeft = false; sawRight = false; armed = true; absentSince = nil
    }

    private func onFrame(buffer: CVPixelBuffer, yaw: Float?, hasFace: Bool) {
        guard isRunning, !working else { return }
        if !hasFace {
            if absentSince == nil { absentSince = Date() }
            if let since = absentSince, Date().timeIntervalSince(since) > 1.1 {
                armed = true; sawLeft = false; sawRight = false
                if mode == .kiosk { message = "Mời người tiếp theo. Quay đầu trái, rồi phải." }
            }
            return
        }
        absentSince = nil
        guard Date().timeIntervalSince(lastAnalysis) >= 0.8 else { return }
        guard engine != nil else { return }
        if mode == .kiosk {
            guard armed else { return }
            // This is only an interaction challenge, not guaranteed PAD.
            guard let yaw else { message = "Không đo được hướng mặt."; return }
            if !sawLeft {
                message = "Vui lòng quay nhẹ sang trái."
                if yaw < -0.20 { sawLeft = true; message = "Đúng rồi. Tiếp tục quay sang phải." }
                return
            }
            if !sawRight {
                if yaw > 0.20 { sawRight = true; message = "Nhìn thẳng để xác nhận." }
                return
            }
            guard abs(yaw) < 0.16 else { return }
            armed = false
        }
        lastAnalysis = Date()
        working = true
        let engine = self.engine!
        // Heavy Core ML/Vision work is kept away from the main thread.
        DispatchQueue.global(qos: .userInitiated).async { [weak self] in
            do {
                let result = try engine.extract(from: buffer)
                Task { @MainActor in self?.handleResult(result, capturedAt: Date()) }
            } catch {
                Task { @MainActor in
                    guard let self else { return }
                    self.message = error.localizedDescription
                    self.working = false
                    if self.mode == .kiosk { self.resetChallenge() }
                }
            }
        }
    }

    private func handleResult(_ result: FaceResult, capturedAt: Date) {
        guard let info = deviceInfo else { working = false; return }
        if mode == .enrollment {
            samples.append(result.embedding)
            enrollmentCount = samples.count
            message = "Đã ghi \(samples.count)/5 mẫu..."
            guard samples.count >= 5 else { working = false; return }
            // All samples normalized by FaceEmbeddingEngine. Normalize mean again.
            let dimensions = result.embedding.count
            let mean = (0..<dimensions).map { i in samples.reduce(0.0) { $0 + $1[i] } / Double(samples.count) }
            mode = .kiosk
            Task {
                do {
                    let normalized = try FaceEmbeddingEngine.normalize(mean)
                    _ = try await api.enroll(ticket: enrollmentTicket, modelID: info.model_id,
                                             version: info.model_version, vector: normalized)
                    roster = try await api.roster(modelID: info.model_id, version: info.model_version)
                    headline = "Đăng ký thành công"
                    message = "Đã đồng bộ mẫu nhận diện mới."
                } catch { headline = "Lỗi đăng ký"; message = error.localizedDescription }
                samples = []; enrollmentCount = 0; working = false; resetChallenge()
            }
            return
        }
        let matches = roster.map { person in (person, FaceEmbeddingEngine.similarity(result.embedding, person.template)) }
            .sorted { $0.1 > $1.1 }
        guard let top = matches.first, top.1 >= info.min_score,
              matches.count == 1 || top.1 - matches[1].1 >= 0.08 else {
            headline = "Chưa thể nhận diện"
            message = "Không đủ chắc chắn để ghi công; liên hệ quản trị hoặc thử lại."
            working = false; return
        }
        Task {
            do {
                let receipt = try await api.attendance(person: top.0, score: top.1,
                    image: result.evidenceJPEG, modelID: info.model_id,
                    version: info.model_version, capturedAt: capturedAt)
                lastReceipt = receipt
                headline = receipt.review_state == "accepted" ? "Chấm công thành công" : "Cần quản trị kiểm tra"
                let salutation = top.0.gender == "female" ? "chị" : "anh/chị"
                let formatted = Self.vnDate(receipt.recorded_at)
                message = "Xin chào \(salutation) \(receipt.employee_name)! Đã ghi nhận lúc \(formatted)."
                // Prevent retrigger until face leaves the camera. Server also enforces cooldown.
            } catch {
                headline = "Không ghi được công"
                message = error.localizedDescription
                resetChallenge()
            }
            working = false
        }
    }

    private static func vnDate(_ iso: String) -> String {
        let parser = ISO8601DateFormatter()
        guard let date = parser.date(from: iso) else { return iso }
        let format = DateFormatter()
        format.locale = Locale(identifier: "vi_VN")
        format.timeZone = TimeZone(identifier: "Asia/Ho_Chi_Minh")
        format.dateFormat = "HH:mm:ss 'ngày' dd/MM/yyyy"
        return format.string(from: date)
    }
}
