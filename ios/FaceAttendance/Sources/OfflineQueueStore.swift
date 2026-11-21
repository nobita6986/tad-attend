import Foundation

struct QueuedAttendanceEvent: Codable, Identifiable, Equatable {
    let eventUUID: String
    let employeeID: Int
    let employeeName: String
    let matchScore: Double
    let modelID: String
    let modelVersion: String
    let capturedAt: Date
    let location: LocationSnapshot?
    let imageFilename: String

    var id: String { eventUUID }
}

/// Local disk-backed offline attendance queue that preserves the exact `event_uuid`
/// across network retries so the backend never records duplicate punches.
final class OfflineQueueStore {
    private let directory: URL
    private let indexFile: URL
    private let encoder = JSONEncoder()
    private let decoder = JSONDecoder()

    init(baseDirectory: URL? = nil) {
        let root = baseDirectory ?? FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask).first!
        self.directory = root.appendingPathComponent("TADOfflineQueue", isDirectory: true)
        self.indexFile = directory.appendingPathComponent("queue.json")
        encoder.dateEncodingStrategy = .iso8601
        decoder.dateDecodingStrategy = .iso8601
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
    }

    func all() -> [QueuedAttendanceEvent] {
        guard let data = try? Data(contentsOf: indexFile),
              let items = try? decoder.decode([QueuedAttendanceEvent].self, from: data) else {
            return []
        }
        return items
    }

    func enqueue(eventUUID: String, employeeID: Int, employeeName: String, matchScore: Double,
                 modelID: String, modelVersion: String, capturedAt: Date,
                 location: LocationSnapshot?, imageJPEG: Data) throws -> QueuedAttendanceEvent {
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
        let imgName = "\(eventUUID).jpg"
        try imageJPEG.write(to: directory.appendingPathComponent(imgName), options: .atomic)

        let item = QueuedAttendanceEvent(
            eventUUID: eventUUID,
            employeeID: employeeID,
            employeeName: employeeName,
            matchScore: matchScore,
            modelID: modelID,
            modelVersion: modelVersion,
            capturedAt: capturedAt,
            location: location,
            imageFilename: imgName
        )
        var list = all().filter { $0.eventUUID != eventUUID }
        list.append(item)
        try save(list)
        return item
    }

    func imageData(for item: QueuedAttendanceEvent) -> Data? {
        try? Data(contentsOf: directory.appendingPathComponent(item.imageFilename))
    }

    func remove(eventUUID: String) {
        let current = all()
        if let hit = current.first(where: { $0.eventUUID == eventUUID }) {
            try? FileManager.default.removeItem(at: directory.appendingPathComponent(hit.imageFilename))
        }
        let remaining = current.filter { $0.eventUUID != eventUUID }
        try? save(remaining)
    }

    private func save(_ list: [QueuedAttendanceEvent]) throws {
        let data = try encoder.encode(list)
        try data.write(to: indexFile, options: .atomic)
    }
}
