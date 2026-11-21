import CoreGraphics
import CoreImage
import CoreML
import Foundation
import ImageIO
import UIKit
import Vision
#if canImport(OnnxRuntimeBindings)
import OnnxRuntimeBindings
#endif

struct FaceResult {
    let embedding: [Double]
    let evidenceJPEG: Data
}

/// Cross-platform SFace face-embedding engine (100% compatible with Android `SFaceEngine.kt`):
/// 1. Primary: OpenCV Zoo `face_recognition_sface_2021dec.onnx` via ONNX Runtime (`OnnxRuntimeBindings`)
///    with 5-point Umeyama similarity alignment to 112x112 canonical template, RGB [0..255] NCHW float32 -> 128-dim L2-normalized.
/// 2. Secondary: Bundled Core ML `FaceEmbedding.mlmodelc` if present.
/// 3. Last-resort fallback: Apple Vision feature print (only if ONNX model is missing in dev builds).
final class FaceEmbeddingEngine {
    static let inputSize = 112

    /// Canonical 5-point ArcFace/SFace reference landmarks in 112x112 top-left space
    /// (exact constants from OpenCV FaceRecognizerSFImpl & Android FaceMath.kt).
    static let sfaceTemplate: [CGPoint] = [
        CGPoint(x: 38.2946, y: 51.6963), // image-left eye
        CGPoint(x: 73.5318, y: 51.5014), // image-right eye
        CGPoint(x: 56.0252, y: 71.7366), // nose tip/base
        CGPoint(x: 41.5493, y: 92.3655), // image-left mouth corner
        CGPoint(x: 70.7299, y: 92.2041), // image-right mouth corner
    ]

    #if canImport(OnnxRuntimeBindings)
    private var ortEnv: ORTEnv?
    private var ortSession: ORTSession?
    private var ortInputName: String?
    private var ortOutputName: String?
    #endif

    private let coreMLModel: MLModel?
    private let targetDimensions: Int
    private let context = CIContext(options: [.useSoftwareRenderer: false])

    init(targetDimensions: Int = 128) {
        self.targetDimensions = max(16, targetDimensions)

        #if canImport(OnnxRuntimeBindings)
        if let onnxPath = Bundle.main.path(forResource: "face_recognition_sface_2021dec", ofType: "onnx"),
           let env = try? ORTEnv(loggingLevel: .warning),
           let opts = try? ORTSessionOptions() {
            _ = try? opts.setIntraOpNumThreads(2)
            _ = try? opts.setGraphOptimizationLevel(.all)
            if let session = try? ORTSession(env: env, modelPath: onnxPath, sessionOptions: opts),
               let inName = try? session.inputNames().first,
               let outName = try? session.outputNames().first {
                self.ortEnv = env
                self.ortSession = session
                self.ortInputName = inName
                self.ortOutputName = outName
            }
        }
        #endif

        if let url = Bundle.main.url(forResource: "FaceEmbedding", withExtension: "mlmodelc"),
           let loaded = try? MLModel(contentsOf: url),
           loaded.modelDescription.inputDescriptionsByName["image"]?.imageConstraint != nil,
           loaded.modelDescription.outputDescriptionsByName["embedding"]?.multiArrayConstraint != nil {
            self.coreMLModel = loaded
        } else {
            self.coreMLModel = nil
        }
    }

    var usesSFaceONNX: Bool {
        #if canImport(OnnxRuntimeBindings)
        return ortSession != nil
        #else
        return false
        #endif
    }

    var usesBundledCoreML: Bool { coreMLModel != nil }

    func extract(from pixelBuffer: CVPixelBuffer) throws -> FaceResult {
        // ARKit front camera buffer oriented to upright portrait selfie view
        let ciImage = CIImage(cvPixelBuffer: pixelBuffer).oriented(.leftMirrored)
        guard let cgImage = context.createCGImage(ciImage, from: ciImage.extent) else {
            throw AppError.badModelIO
        }
        let width = cgImage.width
        let height = cgImage.height

        let request = VNDetectFaceLandmarksRequest()
        let handler = VNImageRequestHandler(cgImage: cgImage, options: [:])
        try handler.perform([request])

        guard let faces = request.results, faces.count == 1, let face = faces.first,
              face.boundingBox.width > 0.10, face.boundingBox.height > 0.10 else {
            throw AppError.noSingleFace
        }

        // Evidence crop around the detected face (bottom-left Vision coords -> CIImage coords)
        var cropRect = VNImageRectForNormalizedRect(face.boundingBox, width, height)
        let padX = cropRect.width * 0.22
        let padY = cropRect.height * 0.22
        cropRect = cropRect.insetBy(dx: -padX, dy: -padY).intersection(ciImage.extent)
        guard !cropRect.isNull, cropRect.width > 20, cropRect.height > 20 else {
            throw AppError.noSingleFace
        }
        let croppedCI = ciImage.cropped(to: cropRect)

        let rawVector: [Double]
        #if canImport(OnnxRuntimeBindings)
        if let session = ortSession, let inputName = ortInputName, let outputName = ortOutputName {
            let alignedRGBA = try alignFaceTo112RGBA(cgImage: cgImage, face: face)
            rawVector = try extractSFaceONNX(
                session: session,
                inputName: inputName,
                outputName: outputName,
                rgba112: alignedRGBA
            )
        } else if let coreMLModel {
            rawVector = try extractCoreML(model: coreMLModel, crop: croppedCI)
        } else {
            rawVector = try extractVisionFeaturePrint(crop: croppedCI)
        }
        #else
        if let coreMLModel {
            rawVector = try extractCoreML(model: coreMLModel, crop: croppedCI)
        } else {
            rawVector = try extractVisionFeaturePrint(crop: croppedCI)
        }
        #endif

        let projected = Self.project(rawVector, to: targetDimensions)
        let vector = try Self.normalize(projected)
        let jpeg = try makeJPEG(croppedCI)
        return FaceResult(embedding: vector, evidenceJPEG: jpeg)
    }

    // MARK: - 5-Point Umeyama Similarity Alignment (matches Android FaceMath.kt)

    private func alignFaceTo112RGBA(cgImage: CGImage, face: VNFaceObservation) throws -> [UInt8] {
        let width = cgImage.width
        let height = cgImage.height
        let imageSize = CGSize(width: width, height: height)

        guard let landmarks = face.landmarks,
              let leftEyeRegion = landmarks.leftPupil ?? landmarks.leftEye,
              let rightEyeRegion = landmarks.rightPupil ?? landmarks.rightEye,
              let noseRegion = landmarks.nose,
              let lipsRegion = landmarks.outerLips else {
            throw AppError.noSingleFace
        }

        let leftEyePts = leftEyeRegion.pointsInImage(imageSize: imageSize)
        let rightEyePts = rightEyeRegion.pointsInImage(imageSize: imageSize)
        let nosePts = noseRegion.pointsInImage(imageSize: imageSize)
        let lipsPts = lipsRegion.pointsInImage(imageSize: imageSize)

        guard !leftEyePts.isEmpty, !rightEyePts.isEmpty, !nosePts.isEmpty, lipsPts.count >= 2,
              let mouthMin = lipsPts.min(by: { $0.x < $1.x }),
              let mouthMax = lipsPts.max(by: { $0.x < $1.x }) else {
            throw AppError.noSingleFace
        }

        func centroid(_ pts: [CGPoint]) -> CGPoint {
            let sum = pts.reduce(CGPoint.zero) { CGPoint(x: $0.x + $1.x, y: $0.y + $1.y) }
            return CGPoint(x: sum.x / CGFloat(pts.count), y: sum.y / CGFloat(pts.count))
        }

        // Convert Vision bottom-left pixel coordinates to top-left pixel coordinates
        func toTopLeft(_ p: CGPoint) -> CGPoint {
            CGPoint(x: p.x, y: CGFloat(height) - p.y)
        }

        let eyeA = toTopLeft(centroid(leftEyePts))
        let eyeB = toTopLeft(centroid(rightEyePts))
        let nose = toTopLeft(centroid(nosePts))
        let mouthA = toTopLeft(mouthMin)
        let mouthB = toTopLeft(mouthMax)

        let orderedSrc = Self.orderFiveLandmarks(
            eyeA: eyeA, eyeB: eyeB, nose: nose, mouthA: mouthA, mouthB: mouthB
        )
        let transform = try Self.estimateSimilarityTransform(src: orderedSrc, dst: Self.sfaceTemplate)

        let size = Self.inputSize
        var rgba = [UInt8](repeating: 0, count: size * size * 4)
        let colorSpace = CGColorSpaceCreateDeviceRGB()
        let bitmapInfo = CGImageAlphaInfo.noneSkipLast.rawValue | CGBitmapInfo.byteOrder32Big.rawValue

        guard let ctx = CGContext(
            data: &rgba,
            width: size,
            height: size,
            bitsPerComponent: 8,
            bytesPerRow: size * 4,
            space: colorSpace,
            bitmapInfo: bitmapInfo
        ) else {
            throw AppError.badModelIO
        }

        ctx.interpolationQuality = .high
        // Work in top-left destination coordinates (0..112)
        ctx.translateBy(x: 0, y: CGFloat(size))
        ctx.scaleBy(x: 1, y: -1)
        // Apply 2D similarity warp (src top-left -> dst 112x112 top-left)
        ctx.concatenate(transform)
        // Flip locally so CGContext.draw maps cgImage bottom-left -> top-left src space
        ctx.translateBy(x: 0, y: CGFloat(height))
        ctx.scaleBy(x: 1, y: -1)
        ctx.draw(cgImage, in: CGRect(x: 0, y: 0, width: width, height: height))

        return rgba
    }

    static func orderFiveLandmarks(
        eyeA: CGPoint,
        eyeB: CGPoint,
        nose: CGPoint,
        mouthA: CGPoint,
        mouthB: CGPoint
    ) -> [CGPoint] {
        let (imgLeftEye, imgRightEye) = eyeA.x <= eyeB.x ? (eyeA, eyeB) : (eyeB, eyeA)
        let (imgLeftMouth, imgRightMouth) = mouthA.x <= mouthB.x ? (mouthA, mouthB) : (mouthB, mouthA)
        return [imgLeftEye, imgRightEye, nose, imgLeftMouth, imgRightMouth]
    }

    /// Closed-form 2D similarity transform (Umeyama 2D) mapping `src` -> `dst`.
    /// Returns CGAffineTransform where x' = a*x - b*y + tx, y' = b*x + a*y + ty.
    static func estimateSimilarityTransform(src: [CGPoint], dst: [CGPoint] = sfaceTemplate) throws -> CGAffineTransform {
        guard src.count == dst.count, src.count >= 2 else { throw AppError.noSingleFace }
        let n = Double(src.count)
        var srcMeanX = 0.0, srcMeanY = 0.0
        var dstMeanX = 0.0, dstMeanY = 0.0
        for i in 0..<src.count {
            srcMeanX += Double(src[i].x)
            srcMeanY += Double(src[i].y)
            dstMeanX += Double(dst[i].x)
            dstMeanY += Double(dst[i].y)
        }
        srcMeanX /= n; srcMeanY /= n
        dstMeanX /= n; dstMeanY /= n

        var varSrc = 0.0, sumDot = 0.0, sumCross = 0.0
        for i in 0..<src.count {
            let sx = Double(src[i].x) - srcMeanX
            let sy = Double(src[i].y) - srcMeanY
            let dx = Double(dst[i].x) - dstMeanX
            let dy = Double(dst[i].y) - dstMeanY
            varSrc += sx * sx + sy * sy
            sumDot += sx * dx + sy * dy
            sumCross += sx * dy - sy * dx
        }
        guard varSrc > 1e-6 else { throw AppError.noSingleFace }

        let a = sumDot / varSrc
        let b = sumCross / varSrc
        let tx = dstMeanX - (a * srcMeanX - b * srcMeanY)
        let ty = dstMeanY - (b * srcMeanX + a * srcMeanY)
        return CGAffineTransform(a: a, b: b, c: -b, d: a, tx: tx, ty: ty)
    }

    // MARK: - SFace ONNX Inference

    #if canImport(OnnxRuntimeBindings)
    private func extractSFaceONNX(
        session: ORTSession,
        inputName: String,
        outputName: String,
        rgba112: [UInt8]
    ) throws -> [Double] {
        let size = Self.inputSize
        let pixelCount = size * size
        guard rgba112.count == pixelCount * 4 else { throw AppError.badModelIO }

        // OpenCV FaceRecognizerSFImpl & Android SFaceEngine.kt:
        // NCHW RGB float32 in [0, 255], mean 0, scale 1.0
        var floats = [Float](repeating: 0, count: 3 * pixelCount)
        for i in 0..<pixelCount {
            let base = i * 4
            floats[i] = Float(rgba112[base])
            floats[pixelCount + i] = Float(rgba112[base + 1])
            floats[2 * pixelCount + i] = Float(rgba112[base + 2])
        }

        let inputBytes = floats.withUnsafeBytes { Data($0) }
        let inputTensor = try ORTValue(
            tensorData: NSMutableData(data: inputBytes),
            elementType: .float,
            shape: [1, 3, NSNumber(value: size), NSNumber(value: size)]
        )

        let outputs = try session.run(
            withInputs: [inputName: inputTensor],
            outputNames: Set([outputName]),
            runOptions: nil
        )
        guard let outVal = outputs[outputName] else { throw AppError.badModelIO }
        let outData = try outVal.tensorData() as Data
        let floatCount = outData.count / MemoryLayout<Float>.size
        guard floatCount >= 16 else { throw AppError.badEmbedding }

        let rawFloats: [Float] = outData.withUnsafeBytes { raw in
            let ptr = raw.bindMemory(to: Float.self)
            return Array(ptr.prefix(floatCount))
        }
        return rawFloats.map { Double($0) }
    }
    #endif

    // MARK: - CoreML & Vision Fallbacks

    private func extractCoreML(model: MLModel, crop: CIImage) throws -> [Double] {
        let input = try makeInput(crop)
        let provider = try MLDictionaryFeatureProvider(dictionary: ["image": MLFeatureValue(pixelBuffer: input)])
        let prediction = try model.prediction(from: provider)
        guard let array = prediction.featureValue(for: "embedding")?.multiArrayValue, array.count > 0 else {
            throw AppError.badModelIO
        }
        return (0..<array.count).map { array[$0].doubleValue }
    }

    private func extractVisionFeaturePrint(crop: CIImage) throws -> [Double] {
        let request = VNGenerateImageFeaturePrintRequest()
        let handler = VNImageRequestHandler(ciImage: crop, options: [:])
        try handler.perform([request])
        guard let obs = request.results?.first as? VNFeaturePrintObservation else {
            throw AppError.badEmbedding
        }
        let count = obs.elementCount
        guard count > 0 else { throw AppError.badEmbedding }
        let data = obs.data
        let floats: [Float] = data.withUnsafeBytes { raw in
            let ptr = raw.bindMemory(to: Float.self)
            return Array(ptr.prefix(count))
        }
        guard floats.count == count else { throw AppError.badEmbedding }
        return floats.map { Double($0) }
    }

    static func project(_ vector: [Double], to dimensions: Int) -> [Double] {
        guard dimensions > 0, !vector.isEmpty else { return [] }
        if vector.count == dimensions { return vector }
        var out = Array(repeating: 0.0, count: dimensions)
        if vector.count > dimensions {
            for (idx, val) in vector.enumerated() {
                let bucket = (idx * dimensions) / vector.count
                out[bucket] += val
            }
        } else {
            for i in 0..<dimensions {
                out[i] = vector[i % vector.count]
            }
        }
        return out
    }

    private func makeInput(_ image: CIImage) throws -> CVPixelBuffer {
        var buffer: CVPixelBuffer?
        let attrs: [CFString: Any] = [
            kCVPixelBufferCGImageCompatibilityKey: true,
            kCVPixelBufferCGBitmapContextCompatibilityKey: true,
        ]
        let code = CVPixelBufferCreate(kCFAllocatorDefault, 112, 112, kCVPixelFormatType_32BGRA,
                                       attrs as CFDictionary, &buffer)
        guard code == kCVReturnSuccess, let buffer else { throw AppError.badModelIO }
        let shifted = image.transformed(by: CGAffineTransform(translationX: -image.extent.minX, y: -image.extent.minY))
        let resized = shifted.transformed(by: CGAffineTransform(scaleX: 112 / image.extent.width,
                                                                y: 112 / image.extent.height))
        context.render(resized, to: buffer, bounds: CGRect(x: 0, y: 0, width: 112, height: 112),
                       colorSpace: CGColorSpaceCreateDeviceRGB())
        return buffer
    }

    private func makeJPEG(_ cropped: CIImage) throws -> Data {
        let maxDimension = max(cropped.extent.width, cropped.extent.height)
        let scale = min(1.0, 640.0 / maxDimension)
        let scaled = cropped.transformed(by: CGAffineTransform(scaleX: scale, y: scale))
        guard let cg = context.createCGImage(scaled, from: scaled.extent) else {
            throw AppError.badModelIO
        }
        let uiImg = UIImage(cgImage: cg)
        for quality in [0.72, 0.55, 0.40, 0.28] {
            if let jpeg = uiImg.jpegData(compressionQuality: quality), jpeg.count <= 240_000 {
                return jpeg
            }
        }
        throw AppError.badModelIO
    }

    static func normalize(_ vector: [Double]) throws -> [Double] {
        guard !vector.isEmpty, vector.allSatisfy({ $0.isFinite }) else { throw AppError.badEmbedding }
        let norm = sqrt(vector.reduce(0) { $0 + $1 * $1 })
        guard norm.isFinite, norm > 0.0001 else { throw AppError.badEmbedding }
        return vector.map { $0 / norm }
    }

    static func similarity(_ a: [Double], _ b: [Double]) -> Double {
        guard a.count == b.count, !a.isEmpty else { return -1 }
        return zip(a, b).reduce(0) { $0 + $1.0 * $1.1 }
    }
}
