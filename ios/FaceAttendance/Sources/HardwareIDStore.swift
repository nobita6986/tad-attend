import Foundation
import Security

/// Generates and persists a fixed hardware ID (`IOS-XXXX-XXXX-XXXX-XXXX`) in Keychain
/// so the iPhone is permanently recognized by the backend across app restarts.
enum HardwareIDStore {
    private static let service = "vn.tad.attend"
    private static let account = "device_hardware_id"

    static func loadOrCreate() -> String {
        if let existing = load(), !existing.isEmpty {
            return existing
        }
        let generated = generate()
        save(generated)
        return generated
    }

    static func generate() -> String {
        let hex = UUID().uuidString.replacingOccurrences(of: "-", with: "").uppercased()
        let p1 = hex.prefix(4)
        let p2 = hex.dropFirst(4).prefix(4)
        let p3 = hex.dropFirst(8).prefix(4)
        let p4 = hex.dropFirst(12).prefix(4)
        return "IOS-\(p1)-\(p2)-\(p3)-\(p4)"
    }

    private static func load() -> String? {
        let query: [CFString: Any] = [
            kSecClass: kSecClassGenericPassword,
            kSecAttrService: service,
            kSecAttrAccount: account,
            kSecReturnData: true,
            kSecMatchLimit: kSecMatchLimitOne,
        ]
        var result: AnyObject?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess,
              let data = result as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    private static func save(_ id: String) {
        let base: [CFString: Any] = [
            kSecClass: kSecClassGenericPassword,
            kSecAttrService: service,
            kSecAttrAccount: account,
        ]
        SecItemDelete(base as CFDictionary)
        var item = base
        item[kSecValueData] = Data(id.utf8)
        item[kSecAttrAccessible] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        SecItemAdd(item as CFDictionary, nil)
    }
}
