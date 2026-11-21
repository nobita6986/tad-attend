import ARKit
import CoreLocation
import SwiftUI
import UIKit

struct ARCameraPreview: UIViewRepresentable {
    let session: ARSession
    func makeUIView(context: Context) -> ARSCNView {
        let view = ARSCNView(frame: .zero)
        view.session = session
        view.automaticallyUpdatesLighting = true
        view.backgroundColor = .black
        return view
    }
    func updateUIView(_ uiView: ARSCNView, context: Context) {}
}

struct ContentView: View {
    @StateObject private var coordinator = RecognitionCoordinator()
    @State private var selectedTab = 0
    @State private var serverURL = UserDefaults.standard.string(forKey: "baseURL") ?? APIClient.defaultServerURL
    @State private var deviceNameInput = ""
    @State private var newToken = ""
    @State private var copiedHardwareID = false
    @State private var showAdvancedConfig = false

    // Direct enrollment form state
    @State private var enrollMode = "existing" // "existing" | "create"
    @State private var selectedEmployeeID: Int?
    @State private var newEmpCode = ""
    @State private var newEmpName = ""
    @State private var newEmpDept = ""
    @State private var selectedShiftID: Int?
    @State private var consentChecked = true

    // Admin PIN gate (6-digit tactile keypad)
    @Environment(\.scenePhase) private var scenePhase
    @State private var adminUnlocked = false
    @State private var adminPinSet = AdminPIN.hasPIN
    @State private var enteredPIN = ""
    @State private var firstSetupPIN = ""
    @State private var isConfirmingNewPIN = false
    @State private var pinError = ""
    @State private var invalidAttempts = 0
    @State private var blockedUntil = Date.distantPast

    var body: some View {
        TabView(selection: $selectedTab) {
            kioskView
                .tabItem { Label("Chấm công", systemImage: "faceid") }
                .tag(0)
            gated(enrollmentView)
                .tabItem { Label("Đăng ký", systemImage: "person.badge.plus") }
                .tag(1)
            gated(settingsView)
                .tabItem { Label("Cấu hình", systemImage: "gearshape.fill") }
                .tag(2)
        }
        .tint(TADTheme.brand)
        .task { await coordinator.connect() }
        .onChange(of: selectedTab) { newValue in
            if newValue != 1 { coordinator.cancelEnrollment() }
            if newValue == 1 && adminUnlocked {
                Task {
                    await coordinator.refreshEnrollmentOptions()
                    syncEnrollmentDefaults()
                }
            }
            if newValue == 2 {
                coordinator.refreshLocation()
            }
        }
        .onChange(of: scenePhase) { phase in
            if phase == .background {
                adminUnlocked = false
                enteredPIN = ""
                firstSetupPIN = ""
                isConfirmingNewPIN = false
                coordinator.cancelEnrollment()
            }
        }
    }

    @ViewBuilder
    private func gated<V: View>(_ view: V) -> some View {
        if adminUnlocked { view } else { adminGate }
    }

    // MARK: - Admin PIN Gate (Apple HIG 6-Digit Keypad)

    private var adminGate: some View {
        NavigationStack {
            PINPadView(
                title: pinGateTitle,
                subtitle: pinGateSubtitle,
                errorMessage: pinError,
                digitsCount: enteredPIN.count,
                onDigit: handlePINDigit,
                onDelete: handlePINDelete
            )
            .navigationTitle("Quyền quản trị")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if isConfirmingNewPIN {
                    ToolbarItem(placement: .navigationBarLeading) {
                        Button("Nhập lại") {
                            isConfirmingNewPIN = false
                            firstSetupPIN = ""
                            enteredPIN = ""
                            pinError = ""
                        }
                    }
                }
            }
        }
    }

    private var pinGateTitle: String {
        if !adminPinSet {
            return isConfirmingNewPIN ? "Xác nhận mã PIN" : "Tạo mã PIN quản trị"
        }
        return "Nhập mã PIN quản trị"
    }

    private var pinGateSubtitle: String {
        if !adminPinSet {
            return isConfirmingNewPIN
                ? "Nhập lại 6 chữ số vừa tạo để xác nhận."
                : "Đặt mã PIN 6 số để bảo vệ mục Đăng ký khuôn mặt và Cấu hình thiết bị."
        }
        return "Nhập mã PIN 6 số để mở cài đặt máy chấm công."
    }

    private func handlePINDigit(_ digit: String) {
        guard enteredPIN.count < 6 else { return }
        pinError = ""
        enteredPIN.append(digit)
        if enteredPIN.count == 6 {
            submitPIN()
        }
    }

    private func handlePINDelete() {
        guard !enteredPIN.isEmpty else { return }
        enteredPIN.removeLast()
    }

    private func submitPIN() {
        let feedback = UINotificationFeedbackGenerator()
        guard Date() >= blockedUntil else {
            feedback.notificationOccurred(.error)
            pinError = "Đã khóa tạm 60 giây do nhập sai nhiều lần."
            enteredPIN = ""
            return
        }

        if !adminPinSet {
            if !isConfirmingNewPIN {
                firstSetupPIN = enteredPIN
                enteredPIN = ""
                isConfirmingNewPIN = true
                return
            }
            guard enteredPIN == firstSetupPIN, AdminPIN.save(enteredPIN) else {
                feedback.notificationOccurred(.error)
                pinError = "Mã PIN xác nhận chưa khớp. Vui lòng thử lại."
                enteredPIN = ""
                firstSetupPIN = ""
                isConfirmingNewPIN = false
                return
            }
            adminPinSet = true
        } else if !AdminPIN.verify(enteredPIN) {
            invalidAttempts += 1
            if invalidAttempts >= 5 {
                blockedUntil = Date().addingTimeInterval(60)
                invalidAttempts = 0
            }
            feedback.notificationOccurred(.error)
            pinError = "Mã PIN chưa đúng."
            enteredPIN = ""
            return
        }

        feedback.notificationOccurred(.success)
        adminUnlocked = true
        invalidAttempts = 0
        enteredPIN = ""
        firstSetupPIN = ""
        isConfirmingNewPIN = false
        pinError = ""
        if selectedTab == 1 {
            Task {
                await coordinator.refreshEnrollmentOptions()
                syncEnrollmentDefaults()
            }
        }
    }

    // MARK: - Kiosk Tab

    private var kioskView: some View {
        VStack(spacing: 14) {
            // Compact HIG top header
            HStack(spacing: 10) {
                ZStack {
                    RoundedRectangle(cornerRadius: 10, style: .continuous)
                        .fill(TADTheme.brand)
                        .frame(width: 36, height: 36)
                    Image(systemName: "faceid")
                        .font(.system(size: 18, weight: .semibold))
                        .foregroundStyle(.white)
                }
                VStack(alignment: .leading, spacing: 2) {
                    Text("TAD Attend")
                        .font(.headline)
                    Text(coordinator.deviceInfo?.name ?? "Thiết bị chưa kích hoạt")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
                Spacer()
                connectionPill
            }
            .padding(.horizontal, 16)
            .padding(.top, 8)

            // Edge-to-edge rounded biometric camera stage
            ZStack {
                ARCameraPreview(session: coordinator.tracker.session)
                CameraGuideOverlay(
                    state: coordinator.visualState,
                    isOutsideGeofence: coordinator.isOutsideGeofence
                )

                // Floating top GPS & branch bar
                VStack {
                    HStack {
                        gpsFloatingPill
                        Spacer()
                        if coordinator.pendingOfflineCount > 0 {
                            Button {
                                Task { await coordinator.flushOfflineQueue() }
                            } label: {
                                Label("\(coordinator.pendingOfflineCount)", systemImage: "icloud.and.arrow.up.fill")
                                    .font(.caption.weight(.semibold))
                                    .padding(.horizontal, 10)
                                    .padding(.vertical, 6)
                                    .background(.ultraThinMaterial, in: Capsule())
                                    .foregroundStyle(.orange)
                            }
                        }
                    }
                    .padding(12)
                    Spacer()
                }
            }
            .frame(maxWidth: .infinity)
            .frame(minHeight: 320, maxHeight: 440)
            .clipShape(RoundedRectangle(cornerRadius: 28, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: 28, style: .continuous)
                    .strokeBorder(statusBorderColor, lineWidth: 2.5)
            )
            .shadow(color: Color.black.opacity(0.08), radius: 14, y: 6)
            .padding(.horizontal, 16)

            // Elevated bottom status card
            VStack(spacing: 10) {
                HStack(spacing: 12) {
                    statusIconBadge
                    VStack(alignment: .leading, spacing: 3) {
                        Text(coordinator.headline)
                            .font(.headline)
                            .foregroundStyle(.primary)
                            .lineLimit(2)
                        Text(coordinator.message)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                            .fixedSize(horizontal: false, vertical: true)
                    }
                    Spacer(minLength: 0)
                }

                if let receipt = coordinator.lastReceipt {
                    Divider()
                    HStack {
                        Label(
                            receipt.review_state == "accepted"
                                ? "Đã ghi nhận thành công"
                                : "Đã gửi · Chờ quản trị duyệt",
                            systemImage: receipt.review_state == "accepted"
                                ? "checkmark.seal.fill"
                                : "clock.badge.checkmark.fill"
                        )
                        .font(.footnote.weight(.semibold))
                        .foregroundStyle(receipt.review_state == "accepted" ? TADTheme.brand : .orange)
                        Spacer()
                        Text(receipt.direction == "in" ? "Vào ca" : "Ra ca")
                            .font(.caption.weight(.semibold))
                            .padding(.horizontal, 8)
                            .padding(.vertical, 3)
                            .background(TADTheme.brand.opacity(0.12), in: Capsule())
                            .foregroundStyle(TADTheme.brand)
                    }
                }

                if !coordinator.isRunning {
                    HStack(spacing: 10) {
                        Button {
                            Task { await coordinator.connect() }
                        } label: {
                            Label("Kết nối lại", systemImage: "arrow.clockwise")
                                .font(.subheadline.weight(.semibold))
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.borderedProminent)
                        .controlSize(.regular)

                        Button {
                            selectedTab = 2
                        } label: {
                            Label("Cấu hình", systemImage: "gearshape")
                                .font(.subheadline.weight(.semibold))
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)
                        .controlSize(.regular)
                    }
                    .padding(.top, 2)
                }
            }
            .padding(16)
            .frame(maxWidth: .infinity)
            .background(TADTheme.card, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: 22, style: .continuous)
                    .strokeBorder(Color(.separator).opacity(0.25), lineWidth: 0.5)
            )
            .padding(.horizontal, 16)

            Spacer(minLength: 0)
        }
        .background(TADTheme.canvas.ignoresSafeArea())
    }

    private var connectionPill: some View {
        HStack(spacing: 5) {
            Circle()
                .fill(coordinator.isRunning ? Color.green : Color.orange)
                .frame(width: 7, height: 7)
            Text(coordinator.isRunning ? "Sẵn sàng" : "Chưa kết nối")
                .font(.caption.weight(.medium))
                .foregroundStyle(.secondary)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 5)
        .background(TADTheme.card, in: Capsule())
    }

    @ViewBuilder
    private var gpsFloatingPill: some View {
        if let branch = coordinator.deviceInfo?.branch {
            HStack(spacing: 6) {
                Image(systemName: coordinator.isOutsideGeofence ? "location.slash.fill" : "location.fill")
                    .font(.caption2.weight(.bold))
                    .foregroundStyle(coordinator.isOutsideGeofence ? .red : Color(red: 0.20, green: 0.84, blue: 0.48))
                if let dist = coordinator.currentDistanceMeters {
                    let radiusText = branch.allowed_radius_meters.map { " / \($0) m" } ?? ""
                    Text("\(branch.name) · \(Int(dist.rounded())) m\(radiusText)")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.white)
                } else {
                    Text("\(branch.name) · Đang lấy GPS...")
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.white.opacity(0.9))
                }
            }
            .padding(.horizontal, 11)
            .padding(.vertical, 6)
            .background(.ultraThinMaterial, in: Capsule())
            .environment(\.colorScheme, .dark)
        }
    }

    private var statusIconBadge: some View {
        let iconName: String
        let tint: Color
        if coordinator.isOutsideGeofence {
            iconName = "location.slash.fill"
            tint = .red
        } else {
            switch coordinator.visualState {
            case .idle:
                iconName = "faceid"
                tint = TADTheme.brand
            case .liveness:
                iconName = "checkmark.circle"
                tint = TADTheme.brandLight
            case .processing:
                iconName = "sparkle.magnifyingglass"
                tint = .teal
            case .success:
                iconName = "checkmark.circle.fill"
                tint = .green
            case .warning:
                iconName = "exclamationmark.triangle.fill"
                tint = .orange
            case .error:
                iconName = "xmark.octagon.fill"
                tint = .red
            }
        }
        return ZStack {
            Circle()
                .fill(tint.opacity(0.14))
                .frame(width: 44, height: 44)
            Image(systemName: iconName)
                .font(.system(size: 20, weight: .semibold))
                .foregroundStyle(tint)
        }
    }

    private var statusBorderColor: Color {
        if coordinator.isOutsideGeofence { return .red.opacity(0.85) }
        switch coordinator.visualState {
        case .success: return .green.opacity(0.85)
        case .liveness: return TADTheme.brandLight.opacity(0.85)
        case .warning: return .orange.opacity(0.85)
        case .error: return .red.opacity(0.85)
        default: return Color(.separator).opacity(0.35)
        }
    }

    // MARK: - Direct Enrollment Tab

    private var enrollmentView: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    // Step 1: Select or Create Employee
                    VStack(alignment: .leading, spacing: 14) {
                        HStack {
                            Label("Bước 1 · Chọn nhân viên", systemImage: "person.crop.circle.badge.plus")
                                .font(.headline)
                            Spacer()
                            Button {
                                Task {
                                    await coordinator.refreshEnrollmentOptions()
                                    syncEnrollmentDefaults()
                                }
                            } label: {
                                Image(systemName: "arrow.clockwise")
                                    .font(.subheadline.weight(.semibold))
                            }
                            .buttonStyle(.bordered)
                            .controlSize(.small)
                        }

                        Picker("Chế độ đăng ký", selection: $enrollMode) {
                            Text("Nhân viên đã có").tag("existing")
                            Text("Tạo nhân viên mới").tag("create")
                        }
                        .pickerStyle(.segmented)

                        if enrollMode == "existing" {
                            if let options = coordinator.enrollmentOptions, !options.employees.isEmpty {
                                Menu {
                                    ForEach(options.employees) { emp in
                                        Button {
                                            selectedEmployeeID = emp.id
                                        } label: {
                                            Label(
                                                "\(emp.code) – \(emp.full_name)",
                                                systemImage: emp.has_face ? "checkmark.circle.fill" : "person.circle"
                                            )
                                        }
                                    }
                                } label: {
                                    HStack {
                                        VStack(alignment: .leading, spacing: 2) {
                                            Text("Nhân viên chi nhánh")
                                                .font(.caption)
                                                .foregroundStyle(.secondary)
                                            Text(selectedEmployeeLabel)
                                                .font(.subheadline.weight(.medium))
                                                .foregroundStyle(.primary)
                                        }
                                        Spacer()
                                        Image(systemName: "chevron.up.chevron.down")
                                            .font(.caption.weight(.semibold))
                                            .foregroundStyle(.secondary)
                                    }
                                    .padding(12)
                                    .background(TADTheme.elevated, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                }
                            } else {
                                Text("Chưa tải được danh sách nhân viên. Bấm nút làm mới ở góc phải.")
                                    .font(.footnote)
                                    .foregroundStyle(.secondary)
                            }
                        } else {
                            VStack(spacing: 10) {
                                TextField("Mã nhân viên (tự sinh)", text: $newEmpCode)
                                    .textInputAutocapitalization(.characters)
                                    .autocorrectionDisabled()
                                    .padding(12)
                                    .background(TADTheme.elevated, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                TextField("Họ và tên nhân viên *", text: $newEmpName)
                                    .padding(12)
                                    .background(TADTheme.elevated, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                TextField("Bộ phận (tùy chọn)", text: $newEmpDept)
                                    .padding(12)
                                    .background(TADTheme.elevated, in: RoundedRectangle(cornerRadius: 12, style: .continuous))

                                if let shifts = coordinator.enrollmentOptions?.shifts, !shifts.isEmpty {
                                    Picker("Ca làm việc", selection: $selectedShiftID) {
                                        Text("Theo ca mặc định chi nhánh").tag(Optional<Int>.none)
                                        ForEach(shifts) { s in
                                            Text("\(s.name) (\(s.starts_at.prefix(5))–\(s.ends_at.prefix(5)))")
                                                .tag(Optional(s.id))
                                        }
                                    }
                                    .pickerStyle(.menu)
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    .padding(10)
                                    .background(TADTheme.elevated, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                                }
                            }
                        }

                        Toggle(isOn: $consentChecked) {
                            Text("Nhân viên đồng ý lưu mẫu khuôn mặt để chấm công")
                                .font(.footnote)
                        }
                        .tint(TADTheme.brand)

                        Button {
                            startEnrollment()
                        } label: {
                            Label("Bắt đầu chụp 5 góc mặt", systemImage: "camera.viewfinder")
                                .font(.subheadline.weight(.semibold))
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.borderedProminent)
                        .controlSize(.large)
                        .disabled(!canStartEnrollment)
                    }
                    .padding(16)
                    .background(TADTheme.card, in: RoundedRectangle(cornerRadius: 20, style: .continuous))

                    // Step 2: Live Biometric Capture Stage
                    VStack(alignment: .leading, spacing: 12) {
                        HStack {
                            Label("Bước 2 · Thu mẫu khuôn mặt", systemImage: "faceid")
                                .font(.headline)
                            Spacer()
                            Text("\(coordinator.enrollmentCount)/5 mẫu")
                                .font(.subheadline.weight(.semibold))
                                .foregroundStyle(TADTheme.brand)
                        }

                        // 5-step visual indicator dots
                        HStack(spacing: 8) {
                            ForEach(1...5, id: \.self) { step in
                                Capsule()
                                    .fill(step <= coordinator.enrollmentCount ? TADTheme.brand : Color(.systemFill))
                                    .frame(height: 6)
                                    .animation(.spring(response: 0.28, dampingFraction: 0.8), value: coordinator.enrollmentCount)
                            }
                        }

                        ZStack {
                            ARCameraPreview(session: coordinator.tracker.session)
                            CameraGuideOverlay(
                                state: coordinator.enrollmentCount > 0 ? .liveness : .idle,
                                isOutsideGeofence: false,
                                progress: Double(coordinator.enrollmentCount) / 5.0
                            )
                        }
                        .frame(height: 290)
                        .clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous))

                        Text(coordinator.message)
                            .font(.footnote)
                            .foregroundStyle(.secondary)

                        Button {
                            coordinator.cancelEnrollment()
                            selectedTab = 0
                        } label: {
                            Text("Quay lại màn hình chấm công")
                                .font(.subheadline.weight(.medium))
                                .frame(maxWidth: .infinity)
                        }
                        .buttonStyle(.bordered)
                    }
                    .padding(16)
                    .background(TADTheme.card, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                }
                .padding(16)
            }
            .background(TADTheme.canvas.ignoresSafeArea())
            .navigationTitle("Đăng ký khuôn mặt")
        }
    }

    private var selectedEmployeeLabel: String {
        guard let id = selectedEmployeeID,
              let emp = coordinator.enrollmentOptions?.employees.first(where: { $0.id == id }) else {
            return "Chọn nhân viên..."
        }
        return "\(emp.code) – \(emp.full_name)\(emp.has_face ? " ✓" : "")"
    }

    private var canStartEnrollment: Bool {
        guard coordinator.isRunning, consentChecked else { return false }
        if enrollMode == "existing" {
            return selectedEmployeeID != nil
        }
        return !newEmpName.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
    }

    private func syncEnrollmentDefaults() {
        guard let opt = coordinator.enrollmentOptions else { return }
        if newEmpCode.isEmpty { newEmpCode = opt.next_employee_code }
        if selectedEmployeeID == nil { selectedEmployeeID = opt.employees.first?.id }
    }

    private func startEnrollment() {
        let req = DirectEnrollmentRequest(
            mode: enrollMode,
            employeeID: enrollMode == "existing" ? selectedEmployeeID : nil,
            code: enrollMode == "create" ? newEmpCode.trimmingCharacters(in: .whitespacesAndNewlines).uppercased() : nil,
            fullName: enrollMode == "create" ? newEmpName.trimmingCharacters(in: .whitespacesAndNewlines) : nil,
            department: enrollMode == "create" ? newEmpDept.trimmingCharacters(in: .whitespacesAndNewlines) : nil,
            shiftID: enrollMode == "create" ? selectedShiftID : nil
        )
        coordinator.beginDirectEnrollment(req)
    }

    // MARK: - Settings & First-Time Registration Tab

    private var isLocationDenied: Bool {
        let s = coordinator.locationService.authorizationStatus
        return s == .denied || s == .restricted
    }

    private var hasValidRegistrationGPS: Bool {
        coordinator.locationService.currentLocation != nil
    }

    private var settingsView: some View {
        NavigationStack {
            Form {
                // Section 1: Mandatory Branch HQ GPS Verification
                Section {
                    VStack(alignment: .leading, spacing: 8) {
                        Label("Yêu cầu đứng tại trụ sở chi nhánh", systemImage: "building.2.crop.circle.fill")
                            .font(.subheadline.weight(.semibold))
                            .foregroundStyle(TADTheme.brand)
                        Text("Khi đăng ký thiết bị lần đầu, ứng dụng sẽ gửi kèm tọa độ GPS hiện tại về máy chủ để Quản trị viên xác minh máy đặt đúng chi nhánh.")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 2)

                    if let loc = coordinator.locationService.currentLocation {
                        HStack(spacing: 10) {
                            Image(systemName: "checkmark.circle.fill")
                                .foregroundStyle(.green)
                                .font(.title3)
                            VStack(alignment: .leading, spacing: 2) {
                                Text("Đã sẵn sàng tọa độ GPS")
                                    .font(.subheadline.weight(.semibold))
                                Text(String(format: "%.6f, %.6f (sai số ±%.0f m)", loc.latitude, loc.longitude, loc.accuracyMeters))
                                    .font(.system(.caption, design: .monospaced))
                                    .foregroundStyle(.secondary)
                            }
                            Spacer()
                            Button("Cập nhật") {
                                coordinator.refreshLocation()
                            }
                            .font(.footnote.weight(.semibold))
                            .buttonStyle(.bordered)
                            .controlSize(.small)
                        }
                    } else if isLocationDenied {
                        VStack(alignment: .leading, spacing: 8) {
                            Label("Quyền định vị GPS đang bị tắt", systemImage: "location.slash.fill")
                                .font(.subheadline.weight(.semibold))
                                .foregroundStyle(.red)
                            Button("Mở Cài đặt GPS cho TAD Attend") {
                                if let url = URL(string: UIApplication.openSettingsURLString) {
                                    UIApplication.shared.open(url)
                                }
                            }
                            .buttonStyle(.borderedProminent)
                            .tint(.orange)
                        }
                    } else {
                        HStack(spacing: 10) {
                            ProgressView()
                            Text("Đang xác định tọa độ GPS trụ sở...")
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                            Spacer()
                            Button("Lấy lại") {
                                coordinator.refreshLocation()
                            }
                            .font(.footnote.weight(.semibold))
                            .buttonStyle(.bordered)
                            .controlSize(.small)
                        }
                    }
                } header: {
                    Text("Vị trí trụ sở (Bắt buộc)")
                }

                // Section 2: Self-Service Device Registration
                Section {
                    HStack {
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Mã máy cố định")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                            Text(coordinator.hardwareID)
                                .font(.system(.footnote, design: .monospaced))
                                .foregroundStyle(.primary)
                        }
                        Spacer()
                        Button {
                            UIPasteboard.general.string = coordinator.hardwareID
                            UIImpactFeedbackGenerator(style: .light).impactOccurred()
                            copiedHardwareID = true
                            DispatchQueue.main.asyncAfter(deadline: .now() + 2) {
                                copiedHardwareID = false
                            }
                        } label: {
                            Label(copiedHardwareID ? "Đã chép" : "Sao chép",
                                  systemImage: copiedHardwareID ? "checkmark" : "doc.on.doc")
                                .font(.caption.weight(.semibold))
                        }
                        .buttonStyle(.bordered)
                        .controlSize(.small)
                    }

                    TextField("Địa chỉ máy chủ HTTPS", text: $serverURL)
                        .keyboardType(.URL)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)

                    TextField("Tên thiết bị (VD: iPhone Cổng Hà Nội)", text: $deviceNameInput)

                    Button {
                        UserDefaults.standard.set(serverURL.trimmingCharacters(in: .whitespacesAndNewlines), forKey: "baseURL")
                        Task { await coordinator.requestDeviceRegistration(deviceName: deviceNameInput) }
                    } label: {
                        Label("Gửi yêu cầu đăng ký & Chờ duyệt", systemImage: "paperplane.fill")
                            .font(.subheadline.weight(.semibold))
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderedProminent)
                    .controlSize(.large)
                    .disabled(!hasValidRegistrationGPS)

                    if !hasValidRegistrationGPS {
                        Text("Nút đăng ký sẽ tự mở khóa ngay khi thiết bị lấy được tọa độ GPS tại trụ sở chi nhánh.")
                            .font(.caption)
                            .foregroundStyle(.orange)
                    }

                    if coordinator.isPollingApproval {
                        HStack(spacing: 10) {
                            ProgressView()
                            Text("Đang chờ Quản trị viên bấm Đồng ý trên web...")
                                .font(.footnote)
                                .foregroundStyle(.secondary)
                        }
                    }

                    if !coordinator.registrationStatus.isEmpty {
                        Text(coordinator.registrationStatus)
                            .font(.footnote)
                            .foregroundStyle(TADTheme.brand)
                    }
                } header: {
                    Text("Đăng ký thiết bị tự động")
                }

                // Section 3: Branch & Geofence Status
                Section("Chi nhánh & Phạm vi GPS") {
                    if let branch = coordinator.deviceInfo?.branch {
                        LabeledContent("Chi nhánh", value: "\(branch.code) – \(branch.name)")
                        if let limit = branch.allowed_radius_meters {
                            LabeledContent(
                                "Bán kính cho phép",
                                value: "\(limit) m (\((branch.enforce_geofence ?? false) ? "Bắt buộc" : "Ghi nhận"))"
                            )
                        }
                        if let dist = coordinator.currentDistanceMeters {
                            LabeledContent("Khoảng cách tới trụ sở", value: "\(Int(dist.rounded())) m")
                        }
                    } else {
                        Text("Thiết bị chưa được gán chi nhánh.")
                            .foregroundStyle(.secondary)
                    }
                }

                // Section 4: Collapsible Advanced Token Config
                Section {
                    DisclosureGroup("Cấu hình nâng cao (Token dự phòng)", isExpanded: $showAdvancedConfig) {
                        SecureField("Dán Bearer token thủ công", text: $newToken)
                        Button("Lưu & Kết nối lại") {
                            UserDefaults.standard.set(serverURL.trimmingCharacters(in: .whitespacesAndNewlines), forKey: "baseURL")
                            if !newToken.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                                DeviceTokenStore.save(newToken.trimmingCharacters(in: .whitespacesAndNewlines))
                                newToken = ""
                            }
                            Task { await coordinator.connect() }
                        }
                        Button("Xóa token thiết bị", role: .destructive) {
                            DeviceTokenStore.clear()
                            coordinator.tracker.stop()
                            coordinator.isRunning = false
                            coordinator.deviceInfo = nil
                        }
                    }
                }
            }
            .navigationTitle("Cấu hình thiết bị")
        }
    }
}
