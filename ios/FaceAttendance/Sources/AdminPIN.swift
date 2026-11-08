import CryptoKit
import Foundation
import Security

/// Local admin UI gate for kiosk pilot; not a replacement for MDM or server-side authorization.
enum AdminPIN {
    private static let service = "vn.example.FaceAttendance.AdminPIN"
    private static let account = "gate"

    private static func load() -> Data? {
        let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service, kSecAttrAccount as String: account,
            kSecReturnData as String: true, kSecMatchLimit as String: kSecMatchLimitOne]
        var result: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess else { return nil }
        return result as? Data
    }
    static var hasPIN: Bool { load() != nil }
    static func save(_ pin: String) -> Bool {
        guard pin.count >= 6 else { return false }
        var salt = [UInt8](repeating: 0, count: 16)
        guard SecRandomCopyBytes(kSecRandomDefault, salt.count, &salt) == errSecSuccess else { return false }
        let saltData = Data(salt)
        let digest = Data(SHA256.hash(data: saltData + Data(pin.utf8)))
        let payload = saltData + digest
        let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service, kSecAttrAccount as String: account]
        SecItemDelete(query as CFDictionary)
        let value: [String: Any] = query.merging([
            kSecValueData as String: payload,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        ]) { _, b in b }
        return SecItemAdd(value as CFDictionary, nil) == errSecSuccess
    }
    static func verify(_ pin: String) -> Bool {
        guard let payload = load(), payload.count == 48 else { return false }
        let salt = payload.prefix(16)
        let expected = payload.dropFirst(16)
        let received = Data(SHA256.hash(data: salt + Data(pin.utf8)))
        var difference: UInt8 = 0
        for (a,b) in zip(expected, received) { difference |= a ^ b }
        return difference == 0
    }
}
