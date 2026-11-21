import SwiftUI
import UIKit

enum TADTheme {
    /// TAD Brand Emerald — high-contrast in both Light & Dark appearances
    static let brand = Color(red: 0.07, green: 0.44, blue: 0.24)
    static let brandLight = Color(red: 0.14, green: 0.58, blue: 0.34)
    static let canvas = Color(.systemGroupedBackground)
    static let card = Color(.secondarySystemGroupedBackground)
    static let elevated = Color(.tertiarySystemGroupedBackground)
}

/// Apple HIG biometric oval guide overlay for live Kiosk and Enrollment camera stages.
struct CameraGuideOverlay: View {
    let state: KioskVisualState
    let isOutsideGeofence: Bool
    var progress: Double? = nil

    private var ringColor: Color {
        if isOutsideGeofence { return .red }
        switch state {
        case .idle: return .white.opacity(0.82)
        case .liveness: return Color(red: 0.16, green: 0.78, blue: 0.44)
        case .processing: return .mint
        case .success: return .green
        case .warning: return .orange
        case .error: return .red
        }
    }

    var body: some View {
        GeometryReader { geo in
            let ovalWidth = min(geo.size.width * 0.62, 236)
            let ovalHeight = min(geo.size.height * 0.70, ovalWidth * 1.28)

            ZStack {
                // Subtle translucent vignette with crisp oval cutout
                Rectangle()
                    .fill(Color.black.opacity(0.32))
                    .overlay(
                        Ellipse()
                            .frame(width: ovalWidth, height: ovalHeight)
                            .blendMode(.destinationOut)
                    )
                    .compositingGroup()

                // Base biometric oval ring
                Ellipse()
                    .strokeBorder(
                        ringColor,
                        style: StrokeStyle(
                            lineWidth: state == .idle ? 2.5 : 4.0,
                            dash: state == .idle ? [8, 6] : []
                        )
                    )
                    .frame(width: ovalWidth, height: ovalHeight)
                    .shadow(color: ringColor.opacity(0.45), radius: state == .idle ? 0 : 10)
                    .animation(.spring(response: 0.32, dampingFraction: 0.78), value: state)

                // Optional enrollment capture progress arc (0.0 -> 1.0)
                if let progress, progress > 0 {
                    Ellipse()
                        .trim(from: 0, to: min(max(progress, 0), 1))
                        .stroke(
                            TADTheme.brandLight,
                            style: StrokeStyle(lineWidth: 5, lineCap: .round)
                        )
                        .rotationEffect(.degrees(-90))
                        .frame(width: ovalWidth + 10, height: ovalHeight + 10)
                        .animation(.easeInOut(duration: 0.25), value: progress)
                }
            }
            .allowsHitTesting(false)
        }
    }
}

/// Tactile native iOS 6-digit PIN pad for Admin Gate (Unlock & First-Time Setup).
struct PINPadView: View {
    let title: String
    let subtitle: String
    let errorMessage: String
    let digitsCount: Int
    let onDigit: (String) -> Void
    let onDelete: () -> Void

    private let impact = UIImpactFeedbackGenerator(style: .light)
    private let rows: [[String]] = [
        ["1", "2", "3"],
        ["4", "5", "6"],
        ["7", "8", "9"],
        ["", "0", "DEL"],
    ]

    var body: some View {
        VStack(spacing: 22) {
            VStack(spacing: 10) {
                ZStack {
                    Circle()
                        .fill(TADTheme.brand.opacity(0.14))
                        .frame(width: 60, height: 60)
                    Image(systemName: "lock.shield.fill")
                        .font(.system(size: 26, weight: .semibold))
                        .foregroundStyle(TADTheme.brand)
                }

                Text(title)
                    .font(.title3.weight(.semibold))
                    .multilineTextAlignment(.center)

                Text(subtitle)
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 24)
            }

            // 6-dot passcode indicator
            HStack(spacing: 16) {
                ForEach(0..<6, id: \.self) { idx in
                    Circle()
                        .fill(idx < digitsCount ? TADTheme.brand : Color.clear)
                        .overlay(
                            Circle()
                                .strokeBorder(
                                    idx < digitsCount ? TADTheme.brand : Color(.tertiaryLabel),
                                    lineWidth: 1.5
                                )
                        )
                        .frame(width: 14, height: 14)
                        .scaleEffect(idx < digitsCount ? 1.08 : 1.0)
                        .animation(.spring(response: 0.2, dampingFraction: 0.7), value: digitsCount)
                }
            }
            .padding(.vertical, 4)

            if !errorMessage.isEmpty {
                Label(errorMessage, systemImage: "exclamationmark.circle.fill")
                    .font(.footnote.weight(.medium))
                    .foregroundStyle(.red)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 20)
            } else {
                Text(" ")
                    .font(.footnote)
            }

            // Numeric keypad
            VStack(spacing: 14) {
                ForEach(rows, id: \.self) { row in
                    HStack(spacing: 24) {
                        ForEach(row, id: \.self) { key in
                            if key.isEmpty {
                                Color.clear.frame(width: 72, height: 72)
                            } else if key == "DEL" {
                                Button {
                                    impact.impactOccurred()
                                    onDelete()
                                } label: {
                                    Image(systemName: "delete.left.fill")
                                        .font(.title3)
                                        .foregroundStyle(.secondary)
                                        .frame(width: 72, height: 72)
                                        .contentShape(Circle())
                                }
                                .disabled(digitsCount == 0)
                            } else {
                                Button {
                                    impact.impactOccurred()
                                    onDigit(key)
                                } label: {
                                    Text(key)
                                        .font(.title2.weight(.medium))
                                        .foregroundStyle(.primary)
                                        .frame(width: 72, height: 72)
                                        .background(TADTheme.card, in: Circle())
                                        .overlay(
                                            Circle()
                                                .strokeBorder(Color(.separator).opacity(0.35), lineWidth: 0.5)
                                        )
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                }
            }
        }
        .padding(.vertical, 20)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(TADTheme.canvas.ignoresSafeArea())
    }
}
