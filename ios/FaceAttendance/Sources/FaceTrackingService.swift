import ARKit
import Foundation

/// ARKit provides face tracking, not certified presentation attack detection (PAD).
final class FaceTrackingService: NSObject, ARSessionDelegate {
    let session = ARSession()
    var onFrame: ((CVPixelBuffer, Float?, Bool) -> Void)?
    private var lastDelivered = Date.distantPast

    override init() {
        super.init()
        session.delegate = self
    }
    func start() throws {
        guard ARFaceTrackingConfiguration.isSupported else {
            throw AppError.network("Thiết bị không hỗ trợ ARKit Face Tracking.")
        }
        let config = ARFaceTrackingConfiguration()
        config.maximumNumberOfTrackedFaces = 1
        session.run(config, options: [.resetTracking, .removeExistingAnchors])
    }
    func stop() { session.pause() }
    func session(_ session: ARSession, didUpdate frame: ARFrame) {
        let now = Date()
        guard now.timeIntervalSince(lastDelivered) >= 0.3 else { return }
        lastDelivered = now
        let faces = frame.anchors.compactMap { $0 as? ARFaceAnchor }.filter { $0.isTracked }
        if faces.count == 1, let face = faces.first {
            // Rotation approximates yaw; MUST calibrate sign/camera orientation on target hardware.
            let yaw = atan2(face.transform.columns.2.x, face.transform.columns.2.z)
            onFrame?(frame.capturedImage, yaw, true)
        } else {
            onFrame?(frame.capturedImage, nil, false)
        }
    }
}
