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
    func updateUIView(_ uiView: ARSCNView, context: Context) { }
}

struct ContentView: View {
    @StateObject private var coordinator = RecognitionCoordinator()
    @State private var selectedTab = 0
    @State private var ticket = ""
    @State private var serverURL = UserDefaults.standard.string(forKey: "baseURL") ?? ""
    @State private var newToken = ""
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
            kioskView.tabItem { Label("Chấm công", systemImage: "person.crop.rectangle.stack") }.tag(0)
            gated(enrollmentView).tabItem { Label("Đăng ký", systemImage: "person.crop.circle.badge.plus") }.tag(1)
            gated(settingsView).tabItem { Label("Cấu hình", systemImage: "gearshape") }.tag(2)
        }
        .tint(Color(red: 0.10, green: 0.35, blue: 0.68))
        .task { await coordinator.connect() }
        .onChange(of: selectedTab) { newValue in
            if newValue != 1 { coordinator.cancelEnrollment() }
        }
        .onChange(of: scenePhase) { phase in
            if phase == .background {
                adminUnlocked = false
                enteredPIN = ""
                coordinator.cancelEnrollment()
            }
        }
    }

    @ViewBuilder private func gated<V: View>(_ view: V) -> some View {
        if adminUnlocked { view }
        else { adminGate }
    }

    private var adminGate: some View {
        NavigationStack {
            Form {
                Section(adminPinSet ? "Nhập mã quản trị" : "Thiết lập mã quản trị lần đầu") {
                    Text(adminPinSet ? "Nhập mã PIN quản trị để mở đăng ký và cấu hình."
                        : "Đặt mã PIN từ 6 ký tự trở lên trước khi cấu hình iPhone tại cổng.")
                        .font(.subheadline).foregroundStyle(.secondary)
                    SecureField("Mã PIN quản trị", text: $enteredPIN)
                    if !adminPinSet {
                        SecureField("Nhập lại mã PIN", text: $confirmedPIN)
                    }
                    if !pinError.isEmpty { Text(pinError).foregroundStyle(.red).font(.footnote) }
                    Button(adminPinSet ? "Mở khóa" : "Thiết lập mã PIN") { unlockAdmin() }
                        .disabled(enteredPIN.isEmpty)
                }
                Section {
                    Text("Quản trị PIN chỉ giúp khóa màn hình trong pilot. Trên máy đặt tại cổng cần kết hợp Guided Access/MDM, bảo mật vật lý và quyền hạn server.")
                        .font(.footnote)
                }
            }.navigationTitle("Quyền quản trị")
        }
    }

    private func unlockAdmin() {
        guard Date() >= blockedUntil else {
            pinError = "Đã khóa tạm thời do nhập sai quá nhiều lần."
            return
        }
        if !adminPinSet {
            guard enteredPIN.count >= 6, enteredPIN == confirmedPIN, AdminPIN.save(enteredPIN) else {
                pinError = "Mã PIN cần ít nhất 6 ký tự và hai lần nhập phải giống nhau."
                return
            }
            adminPinSet = true
        } else if !AdminPIN.verify(enteredPIN) {
            invalidAttempts += 1
            if invalidAttempts >= 5 { blockedUntil = Date().addingTimeInterval(60); invalidAttempts = 0 }
            pinError = "Mã PIN chưa đúng."
            enteredPIN = ""
            return
        }
        adminUnlocked = true
        invalidAttempts = 0
        enteredPIN = ""
        confirmedPIN = ""
        pinError = ""
    }

    private var kioskView: some View {
        VStack(spacing: 14) {
            Text("CHẤM CÔNG KHUÔN MẶT").font(.headline).foregroundStyle(.blue)
            Text(coordinator.deviceInfo?.name ?? "Thiết bị chưa kết nối")
                .font(.subheadline).foregroundStyle(.secondary)
            ARCameraPreview(session: coordinator.tracker.session)
                .frame(maxWidth: .infinity, maxHeight: 410)
                .clipShape(RoundedRectangle(cornerRadius: 18))
                .overlay(RoundedRectangle(cornerRadius: 18).stroke(.blue.opacity(0.5), lineWidth: 2))
                .padding(.horizontal)
            VStack(spacing: 10) {
                Text(coordinator.headline).font(.title3).bold()
                Text(coordinator.message).font(.subheadline).multilineTextAlignment(.center)
                if let receipt = coordinator.lastReceipt {
                    Label(receipt.review_state == "accepted" ? "Đã được server xác nhận" : "Đang chờ xét duyệt",
                          systemImage: receipt.review_state == "accepted" ? "checkmark.seal.fill" : "clock")
                        .foregroundStyle(receipt.review_state == "accepted" ? .green : .orange)
                }
            }
            .padding(14).frame(maxWidth: .infinity)
            .background(Color(.secondarySystemGroupedBackground), in: RoundedRectangle(cornerRadius: 14))
            .padding(.horizontal)
            if !coordinator.isRunning {
                Button("Kết nối lại") { Task { await coordinator.connect() } }
                    .buttonStyle(.borderedProminent)
            }
            Spacer(minLength: 0)
        }
        .padding(.top, 16)
        .background(Color(.systemGroupedBackground))
    }

    private var enrollmentView: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 17) {
                    Text("Đăng ký khuôn mặt").font(.title2).bold()
                    Text("Quản trị tạo mã dùng một lần cho đúng nhân viên đã đồng ý. Mã hết hạn sau 10 phút.")
                        .foregroundStyle(.secondary)
                    TextField("Mã đăng ký", text: $ticket)
                        .textInputAutocapitalization(.characters)
                        .textFieldStyle(.roundedBorder)
                        .autocorrectionDisabled()
                    Button("Bắt đầu lấy 5 mẫu") {
                        Task { await coordinator.beginEnrollment(ticket: ticket) }
                    }
                    .buttonStyle(.borderedProminent)
                    .disabled(!coordinator.isRunning)
                    Text("Đã thu: \(coordinator.enrollmentCount)/5 mẫu")
                        .font(.headline)
                    ProgressView(value: Double(coordinator.enrollmentCount), total: 5)
                    ARCameraPreview(session: coordinator.tracker.session)
                        .frame(height: 300).clipShape(RoundedRectangle(cornerRadius: 16))
                    Text(coordinator.message).font(.subheadline)
                    Button("Hủy đăng ký") { coordinator.cancelEnrollment(); selectedTab = 0 }
                        .buttonStyle(.bordered)
                    Text("Lưu ý: chức năng đăng ký và cấu hình phải được bảo vệ bằng quyền quản trị/PIN hoặc MDM trước khi triển khai cho nhân viên.")
                        .font(.footnote).foregroundStyle(.orange)
                }.padding()
            }.navigationTitle("Đăng ký")
        }
    }

    private var settingsView: some View {
        NavigationStack {
            Form {
                Section("Kết nối máy chủ") {
                    TextField("https://chamcong.congty.vn", text: $serverURL)
                        .textInputAutocapitalization(.never)
                        .keyboardType(.URL)
                        .autocorrectionDisabled()
                    SecureField("Token thiết bị (để trống nếu không đổi)", text: $newToken)
                    Button("Lưu cấu hình và kết nối") {
                        UserDefaults.standard.set(serverURL.trimmingCharacters(in: .whitespacesAndNewlines), forKey: "baseURL")
                        if !newToken.isEmpty { DeviceTokenStore.save(newToken); newToken = "" }
                        Task { await coordinator.connect() }
                        selectedTab = 0
                    }
                }
                Section("Thiết bị hiện tại") {
                    if let info = coordinator.deviceInfo {
                        LabeledContent("Tên", value: info.name)
                        LabeledContent("Hướng", value: info.direction == "in" ? "Vào" : "Ra")
                        LabeledContent("Mô hình", value: info.model_id + " / " + info.model_version)
                        LabeledContent("Ngưỡng máy chủ", value: String(format: "%.3f", info.min_score))
                    } else {
                        Text("Chưa kết nối").foregroundStyle(.secondary)
                    }
                }
                Section("Cảnh báo pilot") {
                    Text("Không nên dùng để tính lương trước khi có mô hình AI, hiệu chuẩn nhận diện, đánh giá chống giả mạo và khóa chế độ quản trị.")
                        .font(.footnote)
                }
            }.navigationTitle("Cấu hình")
        }
    }
}
