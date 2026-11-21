import ARKit
import AudioToolbox
import AVFoundation
import Combine
import Foundation
import SwiftUI
import UIKit

enum KioskVisualState: Equatable {
    case idle
    case liveness
    case processing
    case success
    case warning
    case error
}

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
    @Published var visualState: KioskVisualState = .idle
    @Published var deviceInfo: DeviceInfo?
    @Published var enrollmentOptions: EnrollmentOptionsResponse?
    @Published var enrollmentCount = 0
    @Published var isRunning = false
    @Published var lastReceipt: AttendanceReceipt?
    @Published var lastPunchTimeText = ""
    @Published var registrationStatus = ""
    @Published var pendingOfflineCount = 0
    @Published var isPollingApproval = false

    let hardwareID = HardwareIDStore.loadOrCreate()
    let tracker = FaceTrackingService()
    let locationService = LocationService()

    private let api = APIClient()
    private let offlineStore = OfflineQueueStore()
    private let notificationHaptic = UINotificationFeedbackGenerator()
    private let impactHaptic = UIImpactFeedbackGenerator(style: .light)
    private let speechSynthesizer = AVSpeechSynthesizer()
    private var cancellables = Set<AnyCancellable>()
    private var engine: FaceEmbeddingEngine?
    private var roster: [EnrolledPerson] = []
    private var mode: Mode = .kiosk
    private var activeEnrollRequest: DirectEnrollmentRequest?
    private var samples: [[Double]] = []
    private var working = false
    private var lastAnalysis = Date.distantPast

    // Android-equivalent effortless liveness & smart retry state
    private var armed = true
    private var livenessVerified = false
    private var verifiedAt: Date?
    private var minYawSeen: Float = .infinity
    private var maxYawSeen: Float = -.infinity
    private var sawEyesOpen = false
    private var sawEyesClosed = false
    private var captureAttempts = 0
    private var lastAttemptFailedAt = Date.distantPast
    private var absentSince: Date?
    private var holdFeedbackUntil = Date.distantPast
    private var autoResetTask: Task<Void, Never>?
    private var pollTask: Task<Void, Never>?

    private let turnThresholdDeg: Float = 9.0
    private let yawSpanThresholdDeg: Float = 10.0
    private let straightThresholdDeg: Float = 15.0
    private let maxCaptureAttempts = 5
    private let retryWindowSeconds: TimeInterval = 3.2
    private let retrySpacingSeconds: TimeInterval = 0.25
    private let minSecondBestMargin: Double = 0.08

    init() {
        pendingOfflineCount = offlineStore.all().count
        locationService.objectWillChange
            .receive(on: DispatchQueue.main)
            .sink { [weak self] _ in
                self?.objectWillChange.send()
            }
            .store(in: &cancellables)
        tracker.onFrame = { [weak self] obs in
            Task { @MainActor in self?.onFrame(obs) }
        }
    }

    func refreshLocation() {
        locationService.start()
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
        lastPunchTimeText = ""

        // If no token yet, try automatic activation by Hardware ID first
        if DeviceTokenStore.load()?.isEmpty ?? true {
            do {
                let act = try await api.activateDevice(hardwareID: hardwareID, deviceModel: deviceModelName)
                if act.status == "approved", let tok = act.token, !tok.isEmpty {
                    DeviceTokenStore.save(tok)
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
            let dims = info.embedding_dimensions ?? 128
            engine = FaceEmbeddingEngine(targetDimensions: dims)
            let people = try await api.roster(modelID: info.model_id, version: info.model_version)
            deviceInfo = info
            roster = people
            mode = .kiosk
            resetChallenge()
            try tracker.start()
            isRunning = true
            visualState = .idle
            if people.isEmpty {
                headline = "Chưa có khuôn mặt đăng ký"
                message = "Chi nhánh chưa có nhân viên nào đăng ký khuôn mặt. Vui lòng mở mục Đăng ký."
            } else {
                headline = "Sẵn sàng chấm công"
                message = "Chớp mắt nhẹ hoặc quay nhẹ đầu sang một bên."
            }
            await flushOfflineQueue()
        } catch {
            engine = nil
            visualState = .warning
            headline = "Chưa thể chấm công"
            message = error.localizedDescription
        }
    }

    func requestDeviceRegistration(deviceName: String?) async {
        stopPolling()
        locationService.start()
        guard let location = locationService.currentLocation else {
            notificationHaptic.notificationOccurred(.warning)
            registrationStatus = "Chưa xác định được vị trí. Hãy đứng tại trụ sở chi nhánh, bật Định vị rồi thử lại."
            return
        }
        do {
            let res = try await api.registerDevice(
                hardwareID: hardwareID,
                name: deviceName,
                deviceModel: deviceModelName,
                location: location
            )
            if res.status == "approved", let tok = res.token, !tok.isEmpty {
                DeviceTokenStore.save(tok)
                notificationHaptic.notificationOccurred(.success)
                registrationStatus = res.message ?? "Thiết bị đã được duyệt!"
                await connect()
            } else if res.status == "pending" {
                notificationHaptic.notificationOccurred(.success)
                if let branch = res.nearest_branch, let dist = res.nearest_distance_meters {
                    registrationStatus = "Đã gửi yêu cầu (gần \(branch.name), cách \(Int(dist.rounded())) m). Chờ Quản lý duyệt..."
                } else {
                    registrationStatus = res.message ?? "Đã gửi yêu cầu đăng ký. Chờ Quản lý duyệt..."
                }
                startApprovalPolling()
            } else {
                registrationStatus = res.message ?? res.status
            }
        } catch {
            notificationHaptic.notificationOccurred(.error)
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
                        DeviceTokenStore.save(tok)
                        registrationStatus = "Quản lý đã duyệt! Đang kết nối..."
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
        visualState = .idle
        headline = roster.isEmpty ? "Chưa có khuôn mặt đăng ký" : "Sẵn sàng chấm công"
        message = roster.isEmpty
            ? "Chi nhánh chưa có nhân viên nào đăng ký khuôn mặt."
            : "Chớp mắt nhẹ hoặc quay nhẹ đầu sang một bên."
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
                break
            }
        }
        pendingOfflineCount = offlineStore.all().count
    }

    private func resetChallenge() {
        armed = true
        livenessVerified = false
        verifiedAt = nil
        minYawSeen = .infinity
        maxYawSeen = -.infinity
        sawEyesOpen = false
        sawEyesClosed = false
        captureAttempts = 0
        lastAttemptFailedAt = Date.distantPast
        absentSince = nil
    }

    private func scheduleAutoReset(seconds: Double = 4.0) {
        autoResetTask?.cancel()
        autoResetTask = Task {
            try? await Task.sleep(nanoseconds: UInt64(seconds * 1_000_000_000))
            if Task.isCancelled || self.mode != .kiosk { return }
            self.lastReceipt = nil
            self.lastPunchTimeText = ""
            self.visualState = .idle
            self.resetChallenge()
            self.headline = self.roster.isEmpty ? "Chưa có khuôn mặt đăng ký" : "Sẵn sàng chấm công"
            self.message = self.roster.isEmpty
                ? "Chi nhánh chưa có nhân viên nào đăng ký khuôn mặt."
                : "Mời người tiếp theo. Chớp mắt nhẹ hoặc quay nhẹ đầu."
        }
    }

    private func speakGreeting(_ text: String) {
        AudioServicesPlaySystemSound(1057)
        if speechSynthesizer.isSpeaking {
            speechSynthesizer.stopSpeaking(at: .immediate)
        }
        let utterance = AVSpeechUtterance(string: text)
        utterance.voice = AVSpeechSynthesisVoice(language: "vi-VN")
        utterance.rate = 0.50
        utterance.volume = 0.9
        speechSynthesizer.speak(utterance)
    }

    private func salutation(for gender: String?) -> String {
        switch gender?.lowercased() {
        case "male": return "anh"
        case "female": return "chị"
        default: return "anh/chị"
        }
    }

    private func formatVietnamTime(_ iso: String) -> String {
        let isoFmt = ISO8601DateFormatter()
        isoFmt.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let date = isoFmt.date(from: iso) ?? ISO8601DateFormatter().date(from: iso) ?? Date()
        let df = DateFormatter()
        df.locale = Locale(identifier: "vi_VN")
        df.timeZone = TimeZone(identifier: "Asia/Ho_Chi_Minh")
        df.dateFormat = "HH:mm:ss"
        return df.string(from: date)
    }

    private func onFrame(_ obs: FaceFrameObservation) {
        guard isRunning, !working else { return }
        let now = Date()

        if !obs.hasFace {
            if absentSince == nil { absentSince = now }
            if let since = absentSince, now.timeIntervalSince(since) >= 0.9 {
                // Only clear banner if we aren't holding a fresh success/error message
                if mode == .kiosk && armed && now >= holdFeedbackUntil && lastReceipt == nil {
                    resetChallenge()
                    visualState = .idle
                    headline = roster.isEmpty ? "Chưa có khuôn mặt đăng ký" : "Sẵn sàng chấm công"
                    message = roster.isEmpty
                        ? "Chi nhánh chưa có nhân viên nào đăng ký khuôn mặt."
                        : "Đưa khuôn mặt vào khung, chớp mắt nhẹ hoặc quay nhẹ đầu."
                }
            }
            return
        }
        absentSince = nil

        if mode == .enrollment {
            guard now.timeIntervalSince(lastAnalysis) >= 0.45 else { return }
            guard let activeEngine = engine else { return }
            lastAnalysis = now
            working = true
            let buffer = obs.pixelBuffer
            DispatchQueue.global(qos: .userInitiated).async { [weak self] in
                do {
                    let result = try activeEngine.extract(from: buffer)
                    Task { @MainActor in self?.handleEnrollmentResult(result) }
                } catch {
                    Task { @MainActor in
                        guard let self else { return }
                        self.message = error.localizedDescription
                        self.working = false
                    }
                }
            }
            return
        }

        // Kiosk mode
        guard armed else { return }
        guard now >= holdFeedbackUntil else { return }
        guard let activeEngine = engine else { return }
        guard let yawDeg = obs.yawDeg else {
            visualState = .idle
            headline = "Sẵn sàng chấm công"
            message = "Giữ khuôn mặt ở giữa khung hình."
            return
        }

        // Step 1: Natural blink OR gentle 9° head turn (or 10° yaw span) — identical to Android LivenessTracker
        if !livenessVerified {
            minYawSeen = min(minYawSeen, yawDeg)
            maxYawSeen = max(maxYawSeen, yawDeg)
            let yawSpan = maxYawSeen - minYawSeen

            if let blink = obs.blinkAvg {
                if !sawEyesOpen && blink <= 0.25 {
                    sawEyesOpen = true
                } else if sawEyesOpen && !sawEyesClosed && blink >= 0.45 {
                    sawEyesClosed = true
                } else if sawEyesClosed && blink <= 0.25 {
                    livenessVerified = true
                    verifiedAt = now
                }
            }

            if !livenessVerified && (abs(yawDeg) >= turnThresholdDeg || yawSpan >= yawSpanThresholdDeg) {
                livenessVerified = true
                verifiedAt = now
            }

            if !livenessVerified {
                visualState = .idle
                headline = roster.isEmpty ? "Chưa có khuôn mặt đăng ký" : "Sẵn sàng chấm công"
                message = "Chớp mắt nhẹ hoặc quay nhẹ đầu sang một bên."
                return
            } else {
                impactHaptic.impactOccurred()
            }
        }

        // Expire liveness if user took > 5s without looking straight
        if let started = verifiedAt, now.timeIntervalSince(started) > retryWindowSeconds + 2.0 {
            resetChallenge()
            visualState = .idle
            headline = "Sẵn sàng chấm công"
            message = "Chớp mắt nhẹ hoặc quay nhẹ đầu sang một bên."
            return
        }

        // Step 2: Look straight within ±15° (effortless, matches Android 14°)
        guard abs(yawDeg) <= straightThresholdDeg else {
            visualState = .liveness
            headline = "Tốt lắm! Nhìn thẳng"
            message = "Nhìn thẳng vào camera để chấm công."
            return
        }

        // Respect retry spacing between automatic capture attempts
        guard now.timeIntervalSince(lastAttemptFailedAt) >= retrySpacingSeconds else { return }

        lastAnalysis = now
        working = true
        visualState = .processing
        headline = "Đang nhận diện..."
        message = captureAttempts == 0
            ? "Giữ yên khuôn mặt trong giây lát..."
            : "Giữ yên khuôn mặt nhìn thẳng (lần \(captureAttempts + 1)/\(maxCaptureAttempts))..."

        let buffer = obs.pixelBuffer
        DispatchQueue.global(qos: .userInitiated).async { [weak self] in
            do {
                let result = try activeEngine.extract(from: buffer)
                Task { @MainActor in self?.handleKioskResult(result, capturedAt: Date()) }
            } catch {
                Task { @MainActor in
                    self?.handleKioskRetryOrFail(errorMessage: error.localizedDescription)
                }
            }
        }
    }

    private func handleKioskRetryOrFail(errorMessage: String) {
        let now = Date()
        captureAttempts += 1
        lastAttemptFailedAt = now
        let started = verifiedAt ?? now
        if captureAttempts < maxCaptureAttempts && now.timeIntervalSince(started) < retryWindowSeconds {
            visualState = .liveness
            headline = "Giữ yên khuôn mặt"
            message = "Đang thử chụp lại rõ hơn..."
        } else {
            notificationHaptic.notificationOccurred(.warning)
            resetChallenge()
            holdFeedbackUntil = now.addingTimeInterval(2.5)
            visualState = .warning
            headline = "Chưa nhận diện được"
            message = errorMessage
        }
        working = false
    }

    private func handleEnrollmentResult(_ result: FaceResult) {
        guard let info = deviceInfo, let req = activeEnrollRequest else { working = false; return }
        samples.append(result.embedding)
        enrollmentCount = samples.count
        impactHaptic.impactOccurred()
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
                notificationHaptic.notificationOccurred(.success)
                AudioServicesPlaySystemSound(1057)
                visualState = .success
                headline = "Đăng ký thành công"
                message = "Đã lưu khuôn mặt cho \(enrolled.code) – \(enrolled.full_name)."
            } catch {
                notificationHaptic.notificationOccurred(.error)
                visualState = .error
                headline = "Lỗi đăng ký"
                message = error.localizedDescription
            }
            samples = []
            enrollmentCount = 0
            working = false
            resetChallenge()
        }
    }

    private func handleKioskResult(_ result: FaceResult, capturedAt: Date) {
        guard let info = deviceInfo else { working = false; return }

        // Geofence client-side pre-check before uploading
        let loc = locationService.currentLocation
        if let branch = info.branch, branch.hasEnforcedGeofence, let limit = branch.allowed_radius_meters {
            guard loc != nil else {
                notificationHaptic.notificationOccurred(.warning)
                resetChallenge()
                holdFeedbackUntil = Date().addingTimeInterval(3.0)
                visualState = .warning
                headline = "Chưa có tín hiệu định vị"
                message = AppError.locationUnavailable.localizedDescription
                working = false
                return
            }
            if let dist = currentDistanceMeters, dist > Double(limit) {
                notificationHaptic.notificationOccurred(.error)
                resetChallenge()
                holdFeedbackUntil = Date().addingTimeInterval(3.5)
                visualState = .error
                headline = "Ngoài khu vực chi nhánh"
                message = AppError.outsideGeofence(distance: dist, limit: limit, branchName: branch.name).localizedDescription
                working = false
                return
            }
        }

        if roster.isEmpty {
            notificationHaptic.notificationOccurred(.warning)
            resetChallenge()
            holdFeedbackUntil = Date().addingTimeInterval(3.0)
            visualState = .warning
            headline = "Chưa có khuôn mặt đăng ký"
            message = "Vui lòng nhờ quản lý đăng ký khuôn mặt của bạn trước khi chấm công."
            working = false
            return
        }

        let matches = roster
            .map { person in (person, FaceEmbeddingEngine.similarity(result.embedding, person.template)) }
            .sorted { $0.1 > $1.1 }

        let top = matches[0]
        let passesScore = top.1 >= info.min_score
        let passesMargin = matches.count == 1 || (top.1 - matches[1].1) >= minSecondBestMargin

        guard passesScore && passesMargin else {
            handleKioskRetryOrFail(errorMessage: "Vui lòng đứng đủ sáng, nhìn thẳng vào camera và thử lại.")
            return
        }

        // Disarm while submitting & displaying celebration
        armed = false
        let person = top.0
        let personSalutation = salutation(for: person.gender)
        let eventUUID = UUID().uuidString

        Task {
            do {
                let receipt = try await api.attendance(
                    eventUUID: eventUUID,
                    employeeID: person.id,
                    score: top.1,
                    image: result.evidenceJPEG,
                    modelID: info.model_id,
                    version: info.model_version,
                    capturedAt: capturedAt,
                    offline: false,
                    location: loc
                )
                let vnTime = formatVietnamTime(receipt.recorded_at)
                lastReceipt = receipt
                lastPunchTimeText = vnTime
                notificationHaptic.notificationOccurred(.success)
                visualState = receipt.review_state == "accepted" ? .success : .warning
                headline = receipt.review_state == "accepted"
                    ? "Chấm công thành công"
                    : "Đã ghi nhận · Chờ xác nhận"
                message = receipt.duplicate
                    ? "Bạn đã chấm công trước đó rồi (\(vnTime))."
                    : "Xin chào \(personSalutation) \(receipt.employee_name)! Đã chấm công lúc \(vnTime)."
                speakGreeting("Xin chào \(personSalutation) \(receipt.employee_name), chấm công thành công")
                await flushOfflineQueue()
                scheduleAutoReset(seconds: 4.0)
            } catch let urlErr as URLError {
                _ = try? offlineStore.enqueue(
                    eventUUID: eventUUID,
                    employeeID: person.id,
                    employeeName: person.full_name,
                    matchScore: top.1,
                    modelID: info.model_id,
                    modelVersion: info.model_version,
                    capturedAt: capturedAt,
                    location: loc,
                    imageJPEG: result.evidenceJPEG
                )
                pendingOfflineCount = offlineStore.all().count
                notificationHaptic.notificationOccurred(.warning)
                AudioServicesPlaySystemSound(1057)
                visualState = .warning
                headline = "Đã lưu tạm (mất mạng)"
                message = "Xin chào \(personSalutation) \(person.full_name)! Đã lưu lượt chấm công (\(urlErr.localizedDescription)), máy sẽ tự gửi khi có mạng."
                scheduleAutoReset(seconds: 4.0)
            } catch {
                let errText = error.localizedDescription
                let isCooldown = errText.contains("HTTP 409")
                notificationHaptic.notificationOccurred(isCooldown ? .warning : .error)
                visualState = isCooldown ? .warning : .error
                headline = isCooldown ? "Bạn vừa chấm công rồi" : "Chưa gửi được chấm công"
                message = isCooldown
                    ? "Hệ thống đã ghi nhận lượt chấm công trước đó của \(personSalutation) \(person.full_name)."
                    : errText
                scheduleAutoReset(seconds: 3.5)
            }
            working = false
        }
    }
}
