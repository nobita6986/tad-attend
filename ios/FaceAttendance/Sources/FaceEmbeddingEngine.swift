import CoreGraphics
import CoreImage
import CoreML
import Foundation
import ImageIO
import UIKit
import Vision

struct FaceResult {
    let embedding: [Double]
    let evidenceJPEG: Data
}

/// Cross-platform SFace face-embedding engine (100% compatible with Android `SFaceEngine.kt`):
/// - Runs OpenCV Zoo `face_recognition_sface_2021dec` converted to Core ML (`FaceEmbedding.mlmodelc`)
///   on Apple Neural Engine / GPU.
/// - Exact 5-point Umeyama similarity alignment to 112x112 canonical template,
///   RGB [0..255] float32 NCHW [1, 3, 112, 112] -> 128-dim L2-normalized embedding.
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

    private let coreMLModel: MLModel?
    private let inputFeatureName: String?
    private let outputFeatureName: String?
    private let targetDimensions: Int
    private let context = CIContext(options: [.useSoftwareRenderer: false])

    init(targetDimensions: Int = 128) {
        self.targetDimensions = max(16, targetDimensions)

        let config = MLModelConfiguration()
        config.computeUnits = .all

        if let url = Bundle.main.url(forResource: "FaceEmbedding", withExtension: "mlmodelc"),
           let loaded = try? MLModel(contentsOf: url, configuration: config),
           let inName = loaded.modelDescription.inputDescriptionsByName.keys.first,
           let outName = loaded.modelDescription.outputDescriptionsByName.keys.first {
            self.coreMLModel = loaded
            self.inputFeatureName = inName
            self.outputFeatureName = outName
        } else {
            self.coreMLModel = nil
            self.inputFeatureName = nil
            self.outputFeatureName = nil
        }
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

        // Evidence crop around the detected face
        var cropRect = VNImageRectForNormalizedRect(face.boundingBox, width, height)
        let padX = cropRect.width * 0.22
        let padY = cropRect.height * 0.22
        cropRect = cropRect.insetBy(dx: -padX, dy: -padY).intersection(ciImage.extent)
        guard !cropRect.isNull, cropRect.width > 20, cropRect.height > 20 else {
            throw AppError.noSingleFace
        }
        let croppedCI = ciImage.cropped(to: cropRect)

        let rawVector: [Double]
        if let model = coreMLModel, let inName = inputFeatureName, let outName = outputFeatureName {
            let alignedRGBA = try alignFaceTo112RGBA(cgImage: cgImage, face: face)
            rawVector = try extractSFaceCoreML(
                model: model,
                inputName: inName,
                outputName: outName,
                rgba112: alignedRGBA
            )
        } else {
            rawVector = try extractVisionFeaturePrint(crop: croppedCI)
        }

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
        ctx.translateBy(x: 0, y: CGFloat(size))
        ctx.scaleBy(x: 1, y: -1)
        ctx.concatenate(transform)
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

    // MARK: - SFace Core ML Inference (NCHW RGB [0..255] float32 -> 128-dim)

    private func extractSFaceCoreML(
        model: MLModel,
        inputName: String,
        outputName: String,
        rgba112: [UInt8]
    ) throws -> [Double] {
        let size = Self.inputSize
        let pixelCount = size * size
        guard rgba112.count == pixelCount * 4 else { throw AppError.badModelIO }

        let multiArray = try MLMultiArray(
            shape: [1, 3, NSNumber(value: size), NSNumber(value: size)],
            dataType: .float32
        )
        let ptr = multiArray.dataPointer.assumingMemoryBound(to: Float32.self)
        let sC = multiArray.strides[1].intValue
        let sH = multiArray.strides[2].intValue
        let sW = multiArray.strides[3].intValue
        for y in 0..<size {
            let rowPixelBase = y * size
            let rowStrideBase = y * sH
            for x in 0..<size {
                let base = (rowPixelBase + x) * 4
                let idx = rowStrideBase + x * sW
                ptr[idx] = Float32(rgba112[base])
                ptr[sC + idx] = Float32(rgba112[base + 1])
                ptr[2 * sC + idx] = Float32(rgba112[base + 2])
            }
        }

        let provider = try MLDictionaryFeatureProvider(dictionary: [inputName: MLFeatureValue(multiArray: multiArray)])
        let prediction = try model.prediction(from: provider)
        guard let outArray = prediction.featureValue(for: outputName)?.multiArrayValue, outArray.count >= 16 else {
            throw AppError.badModelIO
        }
        return (0..<outArray.count).map { outArray[$0].doubleValue }
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
