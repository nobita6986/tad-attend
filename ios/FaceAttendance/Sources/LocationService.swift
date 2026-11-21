import CoreLocation
import Foundation

@MainActor
final class LocationService: NSObject, ObservableObject {
    @Published var currentLocation: LocationSnapshot?
    @Published var authorizationStatus: CLAuthorizationStatus = .notDetermined

    private let manager = CLLocationManager()

    override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBest
        manager.distanceFilter = 5
        authorizationStatus = manager.authorizationStatus
    }

    func start() {
        authorizationStatus = manager.authorizationStatus
        if authorizationStatus == .notDetermined {
            manager.requestWhenInUseAuthorization()
        } else if authorizationStatus == .authorizedWhenInUse || authorizationStatus == .authorizedAlways {
            manager.startUpdatingLocation()
        }
    }

    func stop() {
        manager.stopUpdatingLocation()
    }

    func distanceTo(branch: BranchInfo?) -> Double? {
        guard let branch, let bLat = branch.latitude, let bLng = branch.longitude,
              let loc = currentLocation else { return nil }
        return Self.haversineMeters(lat1: loc.latitude, lng1: loc.longitude, lat2: bLat, lng2: bLng)
    }

    nonisolated static func haversineMeters(lat1: Double, lng1: Double, lat2: Double, lng2: Double) -> Double {
        let earthRadius = 6_371_000.0
        let dLat = (lat2 - lat1) * .pi / 180.0
        let dLng = (lng2 - lng1) * .pi / 180.0
        let rLat1 = lat1 * .pi / 180.0
        let rLat2 = lat2 * .pi / 180.0
        let a = pow(sin(dLat / 2.0), 2) + cos(rLat1) * cos(rLat2) * pow(sin(dLng / 2.0), 2)
        let c = 2.0 * atan2(sqrt(a), sqrt(max(0.0, 1.0 - a)))
        return (earthRadius * c * 100.0).rounded() / 100.0
    }
}

extension LocationService: CLLocationManagerDelegate {
    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let status = manager.authorizationStatus
        Task { @MainActor in
            self.authorizationStatus = status
            if status == .authorizedWhenInUse || status == .authorizedAlways {
                self.manager.startUpdatingLocation()
            }
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let latest = locations.last, latest.horizontalAccuracy >= 0 else { return }
        let snap = LocationSnapshot(
            latitude: latest.coordinate.latitude,
            longitude: latest.coordinate.longitude,
            accuracyMeters: latest.horizontalAccuracy,
            capturedAt: latest.timestamp
        )
        Task { @MainActor in
            self.currentLocation = snap
        }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        // Silently keep last known coordinate; geofence check surfaces missing GPS cleanly.
    }
}
