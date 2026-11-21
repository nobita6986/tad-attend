import ARKit
import Foundation
import SwiftUI
import UIKit

struct DirectEnrollmentRequest {
    let mode: String // "existing" | "create"
    let employeeID: Int?
    let code: String?
    let fullName: String?
    let department: String?
    let shiftID: Int?
}

@MainActor
final class RecognitionCoordinator: ObservableObject {
    enum Mode { case kiosk, enrollment }

    @Published var message = "Đang kết nối máy chủ chấm công..."
    @Published var headline = "Sẵn sàng"
    @Published var deviceInfo: DeviceInfo?
    @Published var enrollmentOptions: EnrollmentOptionsResponse?
    @Published var enrollmentCount = 0
    @Published var isRunning = false
    @Published var lastReceipt: AttendanceReceipt?
    @Published var registrationStatus = ""
    @Published var pendingOfflineCount = 0
    @Published var isPollingApproval = false

    let hardwareID = HardwareIDStore.loadOrCreate()
    let tracker = FaceTrackingService()
    let locationService = LocationService()

    private let api = APIClient()
    private let offlineStore = OfflineQueueStore()
    private var engine: FaceEmbeddingEngine?
    private var roster: [EnrolledPerson] = []
    private var mode: Mode = .kiosk
    private var activeEnrollRequest: DirectEnrollmentRequest?
    private var samples: [[Double]] = []
    private var working = false
    private var lastAnalysis = Date.distantPast
    private var sawTurn = false
    private var armed = true
    private var absentSince: Date?
    private var autoResetTask: Task<Void, Never>?
    private var pollTask: Task<Void, Never>?

    init() {
        pendingOfflineCount = offlineStore.all().count
        tracker.onFrame = { [weak self] buffer, yaw, hasFace in
            Task { @MainActor in self?.onFrame(buffer: buffer, yaw: yaw, hasFace: hasFace) }
        }
    }

    var deviceModelName: String {
        "\(UIDevice.current.model) (iOS \(UIDevice.current.systemVersion))"
    }

    var currentDistanceMeters: Double? {
        locationService.distanceTo(branch: deviceInfo?.branch)
    }

    var isOutsideGeofence: Bool {
        guard let branch = deviceInfo?.branch, branch.hasEnforcedGeofence,
              let limit = branch.allowed_radius_meters,
              let dist = currentDistanceMeters else { return false }
        return dist > Double(limit)
    }

    func connect() async {
        stopPolling()
        tracker.stop()
        locationService.start()
        isRunning = false
        lastReceipt = nil

        // If no token yet, try automatic activation by Hardware ID first
        if DeviceTokenStore.load()?.isEmpty ?? true {
            do {
                let act = try await api.activateDevice(hardwareID: hardwareID, deviceModel: deviceModelName)
                if act.status == "approved", let tok = act.token, !tok.isEmpty {
                    _ = DeviceTokenStore.save(tok)
                    registrationStatus = "Đã tự động kích hoạt thiết bị."
                } else {
                    registrationStatus = act.message ?? "Thiết bị chưa được duyệt."
                    headline = "Chưa kết nối thiết bị"
                    message = registrationStatus
                    return
                }
            } catch {
                headline = "Chưa kết nối thiết bị"
                message = error.localizedDescription
                return
            }
        }

        do {
            let info = try await api.deviceInfo()
            guard info.model_id != "UNCONFIGURED", info.model_version != "UNCONFIGURED" else {
                throw AppError.notConfigured
            }
            let dims = info.embedding_dimensions ?? 192
            engine = FaceEmbeddingEngine(targetDimensions: dims)
            let people = try await api.roster(modelID: info.model_id, version: info.model_version)
            deviceInfo = info
            roster = people
            mode = .kiosk
            resetChallenge()
            try tracker.start()
            isRunning = true
            let branchLabel = info.branch.map { " · \($0.name)" } ?? ""
            headline = "\(info.name)\(branchLabel)"
            message = "Đưa khuôn mặt vào khung và quay nhẹ đầu. Đã đồng bộ \(people.count) nhân viên."
            await flushOfflineQueue()
        } catch {
            engine = nil
            headline = "Chưa thể chấm công"
            message = error.localizedDescription
        }
    }

    func requestDeviceRegistration(deviceName: String?) async {
        stopPolling()
        do {
            let res = try await api.registerDevice(hardwareID: hardwareID, name: deviceName, deviceModel: deviceModelName)
            registrationStatus = res.message ?? res.status
            if res.status == "approved", let tok = res.token, !tok.isEmpty {
                _ = DeviceTokenStore.save(tok)
                await connect()
            } else if res.status == "pending" {
                startApprovalPolling()
            }
        } catch {
            registrationStatus = error.localizedDescription
        }
    }

    func startApprovalPolling() {
        stopPolling()
        isPollingApproval = true
        pollTask = Task {
            for _ in 0..<60 {
                try? await Task.sleep(nanoseconds: 4_000_000_000)
                if Task.isCancelled { break }
                if let res = try? await api.activateDevice(hardwareID: hardwareID, deviceModel: deviceModelName) {
                    if res.status == "approved", let tok = res.token, !tok.isEmpty {
                        _ = DeviceTokenStore.save(tok)
                        registrationStatus = "Quản trị viên đã duyệt! Đang kết nối..."
                        isPollingApproval = false
                        await connect()
                        return
                    } else if res.status == "rejected" || res.status == "revoked" {
                        registrationStatus = res.message ?? "Yêu cầu bị từ chối."
                        isPollingApproval = false
                        return
                    }
                }
            }
            isPollingApproval = false
        }
    }

    func stopPolling() {
        pollTask?.cancel()
        pollTask = nil
        isPollingApproval = false
    }

    func refreshEnrollmentOptions() async {
        do {
            enrollmentOptions = try await api.enrollmentOptions()
        } catch {
            message = error.localizedDescription
        }
    }

    func beginDirectEnrollment(_ req: DirectEnrollmentRequest) {
        guard isRunning else {
            message = "Hãy kết nối máy chủ trước khi đăng ký khuôn mặt."
            return
        }
        mode = .enrollment
        activeEnrollRequest = req
        samples = []
        enrollmentCount = 0
        message = "Nhìn thẳng vào camera và xoay nhẹ mặt, cần thu 5 mẫu."
    }

    func cancelEnrollment() {
        mode = .kiosk
        activeEnrollRequest = nil
        samples = []
        enrollmentCount = 0
        resetChallenge()
        message = "Đã trở về chế độ chấm công."
    }

    func flushOfflineQueue() async {
        let queued = offlineStore.all()
        pendingOfflineCount = queued.count
        guard !queued.isEmpty else { return }
        for item in queued {
            guard let img = offlineStore.imageData(for: item) else {
                offlineStore.remove(eventUUID: item.eventUUID)
                continue
            }
            do {
                _ = try await api.attendance(
                    eventUUID: item.eventUUID,
                    employeeID: item.employeeID,
                    score: item.matchScore,
                    image: img,
                    modelID: item.modelID,
                    version: item.modelVersion,
                    capturedAt: item.capturedAt,
                    offline: true,
                    location: item.location
                )
                offlineStore.remove(eventUUID: item.eventUUID)
            } catch {
                // Stop flushing if network is still down
                break
            }
        }
        pendingOfflineCount = offlineStore.all().count
    }

    private func resetChallenge() {
        sawTurn = false
        armed = true
        absentSince = nil
    }

    private func scheduleAutoReset() {
        autoResetTask?.cancel()
        autoResetTask = Task {
            try? await Task.sleep(nanoseconds: 4_000_000_000)
            if Task.isCancelled || self.mode != .kiosk { return }
            self.lastReceipt = nil
            self.resetChallenge()
            if let info = self.deviceInfo {
                let branchLabel = info.branch.map { " · \($0.name)" } ?? ""
                self.headline = "\(info.name)\(branchLabel)"
            }
            self.message = "Mời người tiếp theo đưa mặt vào khung hình."
        }
    }

    private func onFrame(buffer: CVPixelBuffer, yaw: Float?, hasFace: Bool) {
        guard isRunning, !working else { return }
        if !hasFace {
            if absentSince == nil { absentSince = Date() }
            if let since = absentSince, Date().timeIntervalSince(since) > 1.0 {
                armed = true
                sawTurn = false
                if mode == .kiosk && lastReceipt == nil {
                    message = "Đưa khuôn mặt vào khung, quay nhẹ đầu sang trái hoặc phải."
                }
            }
            return
        }
        absentSince = nil
        guard Date().timeIntervalSince(lastAnalysis) >= 0.65 else { return }
        guard let activeEngine = engine else { return }

        if mode == .kiosk {
            guard armed else { return }
            // Fast 1-motion liveness: 1 gentle turn left OR right, then look straight
            guard let yaw else {
                message = "Không đo được hướng mặt. Giữ mặt ở giữa khung hình."
                return
            }
            if !sawTurn {
                if abs(yaw) > 0.18 {
                    sawTurn = true
                    message = "Rất tốt! Nhìn thẳng vào camera để chấm công."
                } else {
                    message = "Quay nhẹ đầu sang trái hoặc phải để xác nhận."
                }
                return
            }
            guard abs(yaw) < 0.14 else { return }
            armed = false
        }

        lastAnalysis = Date()
        working = true
        DispatchQueue.global(qos: .userInitiated).async { [weak self] in
            do {
                let result = try activeEngine.extract(from: buffer)
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

        if mode == .enrollment, let req = activeEnrollRequest {
            samples.append(result.embedding)
            enrollmentCount = samples.count
            message = "Đã chụp \(samples.count)/5 mẫu..."
            guard samples.count >= 5 else { working = false; return }

            let dims = result.embedding.count
            let mean = (0..<dims).map { i in samples.reduce(0.0) { $0 + $1[i] } / Double(samples.count) }
            mode = .kiosk
            activeEnrollRequest = nil

            Task {
                do {
                    let normalized = try FaceEmbeddingEngine.normalize(mean)
                    let enrolled = try await api.directEnroll(
                        mode: req.mode,
                        employeeID: req.employeeID,
                        code: req.code,
                        fullName: req.fullName,
                        department: req.department,
                        shiftID: req.shiftID,
                        modelID: info.model_id,
                        version: info.model_version,
                        vector: normalized
                    )
                    roster = try await api.roster(modelID: info.model_id, version: info.model_version)
                    enrollmentOptions = try? await api.enrollmentOptions()
                    headline = "Đăng ký thành công"
                    message = "Đã lưu khuôn mặt cho \(enrolled.code) – \(enrolled.full_name)."
                } catch {
                    headline = "Lỗi đăng ký"
                    message = error.localizedDescription
                }
                samples = []
                enrollmentCount = 0
                working = false
                resetChallenge()
            }
            return
        }

        // Geofence client-side pre-check before uploading
        let loc = locationService.currentLocation
        if let branch = info.branch, branch.hasEnforcedGeofence, let limit = branch.allowed_radius_meters {
            guard let loc else {
                headline = "Chưa có tín hiệu GPS"
                message = AppError.locationUnavailable.localizedDescription
                working = false
                scheduleAutoReset()
                return
            }
            if let dist = currentDistanceMeters, dist > Double(limit) {
                headline = "Ngoài khu vực trụ sở"
                message = AppError.outsideGeofence(distance: dist, limit: limit, branchName: branch.name).localizedDescription
                working = false
                scheduleAutoReset()
                return
            }
        }

        let matches = roster
            .map { person in (person, FaceEmbeddingEngine.similarity(result.embedding, person.template)) }
            .sorted { $0.1 > $1.1 }

        guard let top = matches.first, top.1 >= info.min_score,
              matches.count == 1 || top.1 - matches[1].1 >= 0.06 else {
            headline = "Chưa nhận diện được"
            message = "Vui lòng nhìn thẳng, đủ ánh sáng và thử lại."
            working = false
            scheduleAutoReset()
            return
        }

        let eventUUID = UUID().uuidString
        Task {
            do {
                let receipt = try await api.attendance(
                    eventUUID: eventUUID,
                    employeeID: top.0.id,
                    score: top.1,
                    image: result.evidenceJPEG,
                    modelID: info.model_id,
                    version: info.model_version,
                    capturedAt: capturedAt,
                    offline: false,
                    location: loc
                )
                lastReceipt = receipt
                headline = "Xin chào \(receipt.employee_name)"
                message = receipt.duplicate ? "Bạn đã chấm công trước đó." : "Chấm công thành công!"
                await flushOfflineQueue()
            } catch let urlErr as URLError {
                // Network failure -> queue offline with fixed event_uuid
                _ = try? offlineStore.enqueue(
                    eventUUID: eventUUID,
                    employeeID: top.0.id,
                    employeeName: top.0.full_name,
                    matchScore: top.1,
                    modelID: info.model_id,
                    modelVersion: info.model_version,
                    capturedAt: capturedAt,
                    location: loc,
                    imageJPEG: result.evidenceJPEG
                )
                pendingOfflineCount = offlineStore.all().count
                headline = "Đã lưu tạm ngoại tuyến"
                message = "Mất kết nối (\(urlErr.localizedDescription)). Đã lưu lượt của \(top.0.full_name) và sẽ tự gửi khi có mạng."
            } catch {
                headline = "Không thể chấm công"
                message = error.localizedDescription
            }
            working = false
            scheduleAutoReset()
        }
    }
}
