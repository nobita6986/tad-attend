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

/// Contract for external FaceEmbedding.mlmodelc:
/// - input `image`: image 112x112 (must match training preprocessing)
/// - output `embedding`: MLMultiArray length 512 (change backend dimensions if necessary)
/// This starter does not supply a trained/licensed biometric recognition model.
final class FaceEmbeddingEngine {
    private let model: MLModel
    private let context = CIContext(options: [.useSoftwareRenderer: false])
    init() throws {
        guard let url = Bundle.main.url(forResource: "FaceEmbedding", withExtension: "mlmodelc") else {
            throw AppError.modelMissing
        }
        model = try MLModel(contentsOf: url)
        guard model.modelDescription.inputDescriptionsByName["image"]?.imageConstraint != nil,
              model.modelDescription.outputDescriptionsByName["embedding"] != nil else {
            throw AppError.badModelIO
        }
    }

    func extract(from pixelBuffer: CVPixelBuffer) throws -> FaceResult {
        // Apply portrait front-camera orientation to actual pixels BEFORE Vision.
        // This keeps Vision bounding boxes in exactly the same coordinates as CI cropping.
        // Reconfirm this orientation on every supported iPhone / placement.
        let image = CIImage(cvPixelBuffer: pixelBuffer).oriented(.leftMirrored)
        let width = Int(image.extent.width)
        let height = Int(image.extent.height)
        let request = VNDetectFaceRectanglesRequest()
        // For production: landmark alignment must match the chosen model training pipeline.
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
        let input = try makeInput(crop)
        let provider = try MLDictionaryFeatureProvider(dictionary: ["image": MLFeatureValue(pixelBuffer: input)])
        let prediction = try model.prediction(from: provider)
        guard let array = prediction.featureValue(for: "embedding")?.multiArrayValue, array.count > 0 else {
            throw AppError.badModelIO
        }
        let raw = (0..<array.count).map { array[$0].doubleValue }
        let vector = try Self.normalize(raw)
        let jpeg = try makeJPEG(crop)
        return FaceResult(embedding: vector, evidenceJPEG: jpeg)
    }

    private func makeInput(_ image: CIImage) throws -> CVPixelBuffer {
        var buffer: CVPixelBuffer?
        let attrs: [CFString: Any] = [kCVPixelBufferCGImageCompatibilityKey: true,
                                     kCVPixelBufferCGBitmapContextCompatibilityKey: true]
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
        let scale = min(1.0, 800.0 / maxDimension)
        let shifted = cropped.transformed(by: CGAffineTransform(translationX: -cropped.extent.minX,
                                                                y: -cropped.extent.minY))
        let final = shifted.transformed(by: CGAffineTransform(scaleX: scale, y: scale))
        guard let cg = context.createCGImage(final, from: final.extent) else { throw AppError.badEmbedding }
        let uiImage = UIImage(cgImage: cg)
        for quality in [0.70, 0.55, 0.40] {
            if let data = uiImage.jpegData(compressionQuality: quality), data.count <= 250_000 { return data }
        }
        throw AppError.network("Ảnh vượt 250KB; cần giảm độ phân giải.")
    }

    static func normalize(_ vector: [Double]) throws -> [Double] {
        let norm = sqrt(vector.reduce(0.0) { $0 + $1 * $1 })
        guard norm > 0.00001, norm.isFinite, vector.allSatisfy({ $0.isFinite }) else { throw AppError.badEmbedding }
        return vector.map { $0 / norm }
    }
    static func similarity(_ a: [Double], _ b: [Double]) -> Double {
        guard a.count == b.count, !a.isEmpty else { return -1 }
        return zip(a,b).reduce(0.0) { $0 + $1.0 * $1.1 }
    }
}
