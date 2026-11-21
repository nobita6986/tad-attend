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

/// Dual-mode face embedding engine:
/// 1. Uses bundled `FaceEmbedding.mlmodelc` (112x112 Core ML model) when present.
/// 2. Falls back cleanly to Apple's built-in Vision `VNGenerateImageFeaturePrintRequest`
///    deterministically projected to `targetDimensions` and L2-normalized so the app
///    works immediately out of the box on iOS 16+ without requiring external model weights.
final class FaceEmbeddingEngine {
    private let model: MLModel?
    private let targetDimensions: Int
    private let context = CIContext(options: [.useSoftwareRenderer: false])

    init(targetDimensions: Int = 192) {
        self.targetDimensions = max(16, targetDimensions)
        if let url = Bundle.main.url(forResource: "FaceEmbedding", withExtension: "mlmodelc"),
           let loaded = try? MLModel(contentsOf: url),
           loaded.modelDescription.inputDescriptionsByName["image"]?.imageConstraint != nil,
           loaded.modelDescription.outputDescriptionsByName["embedding"] != nil {
            self.model = loaded
        } else {
            self.model = nil
        }
    }

    var usesBundledCoreML: Bool { model != nil }

    func extract(from pixelBuffer: CVPixelBuffer) throws -> FaceResult {
        let image = CIImage(cvPixelBuffer: pixelBuffer).oriented(.leftMirrored)
        let width = Int(image.extent.width)
        let height = Int(image.extent.height)
        let request = VNDetectFaceRectanglesRequest()
        let handler = VNImageRequestHandler(ciImage: image, options: [:])
        try handler.perform([request])
        guard let faces = request.results, faces.count == 1, let face = faces.first,
              face.boundingBox.width > 0.10, face.boundingBox.height > 0.10 else {
            throw AppError.noSingleFace
        }

        var rect = VNImageRectForNormalizedRect(face.boundingBox, width, height)
        let expandX = rect.width * 0.14
        let expandY = rect.height * 0.14
        rect = rect.insetBy(dx: -expandX, dy: -expandY).intersection(image.extent)
        guard !rect.isNull, rect.width > 20, rect.height > 20 else { throw AppError.noSingleFace }

        let crop = image.cropped(to: rect)
        let rawVector: [Double]
        if let model {
            rawVector = try extractCoreML(model: model, crop: crop)
        } else {
            rawVector = try extractVisionFeaturePrint(crop: crop)
        }

        let projected = Self.project(rawVector, to: targetDimensions)
        let vector = try Self.normalize(projected)
        let jpeg = try makeJPEG(crop)
        return FaceResult(embedding: vector, evidenceJPEG: jpeg)
    }

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
        guard let cg = context.createCGImage(scaled, from: scaled.extent),
              let jpeg = UIImage(cgImage: cg).jpegData(compressionQuality: 0.72),
              jpeg.count <= 240_000 else {
            throw AppError.badModelIO
        }
        return jpeg
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
