import ARKit
import SwiftUI

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

    // Direct enrollment form state
    @State private var enrollMode = "existing" // "existing" | "create"
    @State private var selectedEmployeeID: Int?
    @State private var newEmpCode = ""
    @State private var newEmpName = ""
    @State private var newEmpDept = ""
    @State private var selectedShiftID: Int?
    @State private var consentChecked = true

    // Admin PIN gate
    @Environment(\.scenePhase) private var scenePhase
    @State private var adminUnlocked = false
    @State private var adminPinSet = AdminPIN.hasPIN
    @State private var enteredPIN = ""
    @State private var confirmedPIN = ""
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
                .tabItem { Label("Cấu hình", systemImage: "gearshape") }
                .tag(2)
        }
        .tint(Color(red: 0.09, green: 0.40, blue: 0.20))
        .task { await coordinator.connect() }
        .onChange(of: selectedTab) { newValue in
            if newValue != 1 { coordinator.cancelEnrollment() }
            if newValue == 1 && adminUnlocked {
                Task {
                    await coordinator.refreshEnrollmentOptions()
                    syncEnrollmentDefaults()
                }
            }
        }
        .onChange(of: scenePhase) { phase in
            if phase == .background {
                adminUnlocked = false
                enteredPIN = ""
                coordinator.cancelEnrollment()
            }
        }
    }

    @ViewBuilder
    private func gated<V: View>(_ view: V) -> some View {
        if adminUnlocked { view } else { adminGate }
    }

    // MARK: - Admin PIN Gate

    private var adminGate: some View {
        NavigationStack {
            Form {
                Section(adminPinSet ? "Nhập mã PIN quản trị" : "Tạo mã PIN quản trị lần đầu") {
                    Text(adminPinSet
                         ? "Nhập mã PIN để mở mục Đăng ký khuôn mặt và Cấu hình thiết bị."
                         : "Đặt mã PIN từ 6 ký tự trở lên để bảo vệ cấu hình máy chấm công.")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                    SecureField("Mã PIN quản trị", text: $enteredPIN)
                    if !adminPinSet {
                        SecureField("Nhập lại mã PIN", text: $confirmedPIN)
                    }
                    if !pinError.isEmpty {
                        Text(pinError).foregroundStyle(.red).font(.footnote)
                    }
                    Button(adminPinSet ? "Mở khóa" : "Lưu mã PIN") { unlockAdmin() }
                        .disabled(enteredPIN.isEmpty)
                }
            }
            .navigationTitle("Quyền quản trị")
        }
    }

    private func unlockAdmin() {
        guard Date() >= blockedUntil else {
            pinError = "Đã khóa tạm 60 giây do nhập sai nhiều lần."
            return
        }
        if !adminPinSet {
            guard enteredPIN.count >= 6, enteredPIN == confirmedPIN, AdminPIN.save(enteredPIN) else {
                pinError = "Mã PIN cần ít nhất 6 ký tự và khớp nhau."
                return
            }
            adminPinSet = true
        } else if !AdminPIN.verify(enteredPIN) {
            invalidAttempts += 1
            if invalidAttempts >= 5 {
                blockedUntil = Date().addingTimeInterval(60)
                invalidAttempts = 0
            }
            pinError = "Mã PIN chưa đúng."
            enteredPIN = ""
            return
        }
        adminUnlocked = true
        invalidAttempts = 0
        enteredPIN = ""
        confirmedPIN = ""
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
        VStack(spacing: 12) {
            VStack(spacing: 4) {
                Text("TAD ATTEND · CHẤM CÔNG")
                    .font(.headline)
                    .foregroundStyle(Color(red: 0.09, green: 0.40, blue: 0.20))
                Text(coordinator.deviceInfo?.name ?? "Thiết bị chưa kích hoạt")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                gpsStatusBanner
            }

            ARCameraPreview(session: coordinator.tracker.session)
                .frame(maxWidth: .infinity, maxHeight: 400)
                .clipShape(RoundedRectangle(cornerRadius: 20))
                .overlay(
                    RoundedRectangle(cornerRadius: 20)
                        .stroke(statusBorderColor, lineWidth: 3)
                )
                .padding(.horizontal)

            VStack(spacing: 8) {
                Text(coordinator.headline).font(.title3).bold().multilineTextAlignment(.center)
                Text(coordinator.message).font(.subheadline).multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                if let receipt = coordinator.lastReceipt {
                    Label(
                        receipt.review_state == "accepted" ? "Đã ghi nhận thành công" : "Đã gửi · Chờ quản trị duyệt",
                        systemImage: receipt.review_state == "accepted" ? "checkmark.seal.fill" : "clock.fill"
                    )
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(receipt.review_state == "accepted" ? .green : .orange)
                }
                if coordinator.pendingOfflineCount > 0 {
                    Button {
                        Task { await coordinator.flushOfflineQueue() }
                    } label: {
                        Label("Đồng bộ \(coordinator.pendingOfflineCount) lượt lưu tạm", systemImage: "arrow.triangle.2.circlepath")
                            .font(.footnote.weight(.semibold))
                    }
                    .buttonStyle(.bordered)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity)
            .background(Color(.secondarySystemGroupedBackground), in: RoundedRectangle(cornerRadius: 16))
            .padding(.horizontal)

            if !coordinator.isRunning {
                HStack(spacing: 12) {
                    Button("Kết nối lại") { Task { await coordinator.connect() } }
                        .buttonStyle(.borderedProminent)
                    Button("Mở Cấu hình") { selectedTab = 2 }
                        .buttonStyle(.bordered)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(.top, 14)
        .background(Color(.systemGroupedBackground))
    }

    @ViewBuilder
    private var gpsStatusBanner: some View {
        if let branch = coordinator.deviceInfo?.branch {
            HStack(spacing: 6) {
                Image(systemName: coordinator.isOutsideGeofence ? "location.slash.fill" : "location.fill")
                    .font(.caption)
                if let dist = coordinator.currentDistanceMeters {
                    let radiusText = branch.allowed_radius_meters.map { " / \($0) m" } ?? ""
                    Text("\(branch.name) · Cách trụ sở \(Int(dist.rounded())) m\(radiusText)")
                        .font(.caption.weight(.medium))
                } else {
                    Text("\(branch.name) · Đang lấy GPS...")
                        .font(.caption.weight(.medium))
                }
            }
            .padding(.horizontal, 10)
            .padding(.vertical, 4)
            .background(
                (coordinator.isOutsideGeofence ? Color.red : Color.green).opacity(0.12),
                in: Capsule()
            )
            .foregroundStyle(coordinator.isOutsideGeofence ? .red : Color(red: 0.09, green: 0.40, blue: 0.20))
        }
    }

    private var statusBorderColor: Color {
        if coordinator.isOutsideGeofence { return .red }
        if let receipt = coordinator.lastReceipt {
            return receipt.review_state == "accepted" ? .green : .orange
        }
        return Color(red: 0.09, green: 0.40, blue: 0.20).opacity(0.55)
    }

    // MARK: - Direct Enrollment Tab

    private var enrollmentView: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    Picker("Chế độ đăng ký", selection: $enrollMode) {
                        Text("Nhân viên đã có").tag("existing")
                        Text("Tạo nhân viên mới").tag("create")
                    }
                    .pickerStyle(.segmented)

                    if enrollMode == "existing" {
                        if let options = coordinator.enrollmentOptions, !options.employees.isEmpty {
                            Picker("Chọn nhân viên chi nhánh", selection: $selectedEmployeeID) {
                                ForEach(options.employees) { emp in
                                    Text("\(emp.code) – \(emp.full_name)\(emp.has_face ? " ✓" : "")")
                                        .tag(Optional(emp.id))
                                }
                            }
                            .pickerStyle(.menu)
                        } else {
                            Text("Chưa tải được danh sách nhân viên. Bấm Làm mới bên dưới.")
                                .font(.footnote)
                                .foregroundStyle(.secondary)
                        }
                    } else {
                        TextField("Mã nhân viên (tự sinh)", text: $newEmpCode)
                            .textFieldStyle(.roundedBorder)
                            .textInputAutocapitalization(.characters)
                            .autocorrectionDisabled()
                        TextField("Họ và tên nhân viên *", text: $newEmpName)
                            .textFieldStyle(.roundedBorder)
                        TextField("Bộ phận (tùy chọn)", text: $newEmpDept)
                            .textFieldStyle(.roundedBorder)
                        if let shifts = coordinator.enrollmentOptions?.shifts, !shifts.isEmpty {
                            Picker("Ca làm việc", selection: $selectedShiftID) {
                                Text("Theo ca mặc định chi nhánh").tag(Optional<Int>.none)
                                ForEach(shifts) { s in
                                    Text("\(s.name) (\(s.starts_at.prefix(5))–\(s.ends_at.prefix(5)))")
                                        .tag(Optional(s.id))
                                }
                            }
                            .pickerStyle(.menu)
                        }
                    }

                    Toggle("Nhân viên đã đồng ý lưu mẫu khuôn mặt chấm công", isOn: $consentChecked)
                        .font(.footnote)

                    HStack(spacing: 10) {
                        Button("Bắt đầu chụp 5 mẫu") { startEnrollment() }
                            .buttonStyle(.borderedProminent)
                            .disabled(!canStartEnrollment)
                        Button("Làm mới") {
                            Task {
                                await coordinator.refreshEnrollmentOptions()
                                syncEnrollmentDefaults()
                            }
                        }
                        .buttonStyle(.bordered)
                    }

                    VStack(alignment: .leading, spacing: 6) {
                        Text("Tiến độ: \(coordinator.enrollmentCount)/5 mẫu").font(.headline)
                        ProgressView(value: Double(coordinator.enrollmentCount), total: 5)
                    }

                    ARCameraPreview(session: coordinator.tracker.session)
                        .frame(height: 290)
                        .clipShape(RoundedRectangle(cornerRadius: 16))

                    Text(coordinator.message).font(.subheadline).foregroundStyle(.secondary)

                    Button("Quay lại chấm công") {
                        coordinator.cancelEnrollment()
                        selectedTab = 0
                    }
                    .buttonStyle(.bordered)
                }
                .padding()
            }
            .navigationTitle("Đăng ký khuôn mặt")
        }
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

    // MARK: - Settings & Device Registration Tab

    private var settingsView: some View {
        NavigationStack {
            Form {
                Section("Đăng ký thiết bị tự động (Khuyên dùng)") {
                    HStack {
                        Text("Mã máy cố định")
                        Spacer()
                        Text(coordinator.hardwareID)
                            .font(.system(.footnote, design: .monospaced))
                            .foregroundStyle(.blue)
                    }
                    Button("Sao chép Mã máy") {
                        UIPasteboard.general.string = coordinator.hardwareID
                    }
                    TextField("Địa chỉ máy chủ HTTPS", text: $serverURL)
                        .keyboardType(.URL)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)
                    TextField("Tên thiết bị (VD: iPhone Cổng Hà Nội)", text: $deviceNameInput)
                    Button("Gửi yêu cầu đăng ký & Chờ duyệt") {
                        UserDefaults.standard.set(serverURL.trimmingCharacters(in: .whitespacesAndNewlines), forKey: "baseURL")
                        Task { await coordinator.requestDeviceRegistration(deviceName: deviceNameInput) }
                    }
                    .buttonStyle(.borderedProminent)
                    if coordinator.isPollingApproval {
                        HStack(spacing: 8) {
                            ProgressView()
                            Text("Đang chờ Quản trị viên bấm Đồng ý trên web...")
                                .font(.footnote)
                                .foregroundStyle(.secondary)
                        }
                    }
                    if !coordinator.registrationStatus.isEmpty {
                        Text(coordinator.registrationStatus)
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                }

                Section("Thông tin GPS & Chi nhánh") {
                    if let branch = coordinator.deviceInfo?.branch {
                        LabeledContent("Chi nhánh", value: "\(branch.code) – \(branch.name)")
                        if let limit = branch.allowed_radius_meters {
                            LabeledContent("Bán kính GPS", value: "\(limit) m (\((branch.enforce_geofence ?? false) ? "Bắt buộc" : "Chỉ ghi nhận"))")
                        }
                    } else {
                        Text("Thiết bị chưa gắn chi nhánh.").foregroundStyle(.secondary)
                    }
                    if let loc = coordinator.locationService.currentLocation {
                        LabeledContent("Tọa độ hiện tại", value: String(format: "%.6f, %.6f (±%.0fm)", loc.latitude, loc.longitude, loc.accuracyMeters))
                    } else {
                        Text("Đang chờ tín hiệu GPS...").foregroundStyle(.secondary)
                    }
                }

                Section("Cấu hình nâng cao (Token dự phòng)") {
                    SecureField("Dán Bearer token thủ công (nếu cần)", text: $newToken)
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
            .navigationTitle("Cấu hình thiết bị")
        }
    }
}
