import ARKit
import Foundation
import simd

struct FaceFrameObservation {
    let pixelBuffer: CVPixelBuffer
    let hasFace: Bool
    let yawDeg: Float?
    let blinkAvg: Float?
}

/// ARKit front-camera face tracking with camera-relative yaw (degrees) and natural eye-blink detection.
final class FaceTrackingService: NSObject, ARSessionDelegate {
    let session = ARSession()
    var onFrame: ((FaceFrameObservation) -> Void)?
    private var lastDelivered = Date.distantPast

    override init() {
        super.init()
        session.delegate = self
    }

    func start() throws {
        guard ARFaceTrackingConfiguration.isSupported else {
            throw AppError.network("Thiết bị không hỗ trợ nhận diện khuôn mặt ARKit.")
        }
        let config = ARFaceTrackingConfiguration()
        config.maximumNumberOfTrackedFaces = 1
        session.run(config, options: [.resetTracking, .removeExistingAnchors])
    }

    func stop() {
        session.pause()
    }

    func session(_ session: ARSession, didUpdate frame: ARFrame) {
        let now = Date()
        guard now.timeIntervalSince(lastDelivered) >= 0.15 else { return }
        lastDelivered = now

        let faces = frame.anchors.compactMap { $0 as? ARFaceAnchor }.filter { $0.isTracked }
        if faces.count == 1, let face = faces.first {
            // Compute face orientation RELATIVE to camera so holding phone at any angle works effortlessly
            let faceInCamera = simd_mul(simd_inverse(frame.camera.transform), face.transform)
            let yawRad = atan2(faceInCamera.columns.2.x, faceInCamera.columns.2.z)
            let yawDeg = yawRad * (180.0 / .pi)

            let blinkL = face.blendShapes[.eyeBlinkLeft]?.floatValue
            let blinkR = face.blendShapes[.eyeBlinkRight]?.floatValue
            let blinkAvg: Float?
            if let l = blinkL, let r = blinkR {
                blinkAvg = (l + r) / 2.0
            } else {
                blinkAvg = blinkL ?? blinkR
            }

            onFrame?(FaceFrameObservation(
                pixelBuffer: frame.capturedImage,
                hasFace: true,
                yawDeg: yawDeg,
                blinkAvg: blinkAvg
            ))
        } else {
            onFrame?(FaceFrameObservation(
                pixelBuffer: frame.capturedImage,
                hasFace: false,
                yawDeg: nil,
                blinkAvg: nil
            ))
        }
    }
}
