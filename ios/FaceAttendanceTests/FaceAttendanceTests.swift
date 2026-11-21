import XCTest
@testable import FaceAttendance

final class FaceAttendanceTests: XCTestCase {
    func testHardwareIDFormat() {
        let id = HardwareIDStore.generate()
        XCTAssertTrue(id.hasPrefix("IOS-"))
        let parts = id.split(separator: "-")
        XCTAssertEqual(parts.count, 5)
        XCTAssertEqual(parts[1].count, 4)
        XCTAssertEqual(parts[4].count, 4)
    }

    func testHaversineDistanceCalculation() {
        // Same point -> 0m
        let zero = LocationService.haversineMeters(lat1: 20.985421, lng1: 105.853192, lat2: 20.985421, lng2: 105.853192)
        XCTAssertEqual(zero, 0.0, accuracy: 0.01)

        // ~0.0001 deg latitude ~= 11.1 meters
        let near = LocationService.haversineMeters(lat1: 20.985421, lng1: 105.853192, lat2: 20.985521, lng2: 105.853192)
        XCTAssertGreaterThan(near, 9.0)
        XCTAssertLessThan(near, 13.0)

        // ~0.01 deg latitude ~= 1112 meters
        let far = LocationService.haversineMeters(lat1: 20.985421, lng1: 105.853192, lat2: 20.995421, lng2: 105.853192)
        XCTAssertGreaterThan(far, 1050.0)
        XCTAssertLessThan(far, 1180.0)
    }

    func testEmbeddingNormalizationAndSimilarity() throws {
        let v = try FaceEmbeddingEngine.normalize([3.0, 4.0, 0.0])
        XCTAssertEqual(v[0], 0.6, accuracy: 0.0001)
        XCTAssertEqual(v[1], 0.8, accuracy: 0.0001)
        XCTAssertEqual(FaceEmbeddingEngine.similarity(v, v), 1.0, accuracy: 0.0001)

        let projected = FaceEmbeddingEngine.project([1.0, 2.0, 3.0, 4.0], to: 2)
        XCTAssertEqual(projected, [3.0, 7.0])
    }

    func testOfflineQueuePersistsStableEventUUID() throws {
        let tempDir = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString, isDirectory: true)
        defer { try? FileManager.default.removeItem(at: tempDir) }

        let store = OfflineQueueStore(baseDirectory: tempDir)
        let uuid = UUID().uuidString
        let loc = LocationSnapshot(latitude: 20.985421, longitude: 105.853192, accuracyMeters: 10.0, capturedAt: Date())
        let jpeg = Data([0xFF, 0xD8, 0xFF, 0xD9])

        _ = try store.enqueue(
            eventUUID: uuid,
            employeeID: 42,
            employeeName: "Nguyễn Văn A",
            matchScore: 0.94,
            modelID: "mobilefacenet",
            modelVersion: "1.0",
            capturedAt: Date(),
            location: loc,
            imageJPEG: jpeg
        )

        let items = store.all()
        XCTAssertEqual(items.count, 1)
        XCTAssertEqual(items[0].eventUUID, uuid)
        XCTAssertEqual(items[0].location?.latitude ?? 0, 20.985421, accuracy: 0.000001)
        XCTAssertEqual(store.imageData(for: items[0]), jpeg)

        store.remove(eventUUID: uuid)
        XCTAssertTrue(store.all().isEmpty)
    }
}
