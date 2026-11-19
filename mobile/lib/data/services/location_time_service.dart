import 'dart:async';
import 'dart:io';

import 'package:geolocator/geolocator.dart';
import 'package:triproof_ai_scanner/data/services/secure_keystore_service.dart';

/// Captures optional inspection coordinates without blocking <50ms offline
/// verification, and advances the anti-clock-rollback High-Water Mark from
/// GPS timestamps whenever a fix is obtained.
abstract interface class LocationTimeService {
  /// Returns the latest cached `(lat, lng)` immediately (non-blocking) and
  /// triggers an asynchronous GPS refresh in the background.
  ({double? lat, double? lng}) getCachedCoordinates();

  /// Refreshes GPS fix and advances [SecureKeystoreService] High-Water Mark if
  /// the GPS satellite timestamp is newer.
  Future<({double? lat, double? lng, DateTime? timestampUtc})>
      refreshLocationAndClock();
}

/// [Geolocator]-backed implementation of [LocationTimeService].
class GeolocatorTimeService implements LocationTimeService {
  /// Creates a [GeolocatorTimeService].
  GeolocatorTimeService({required SecureKeystoreService keystoreService})
      : _keystoreService = keystoreService;

  final SecureKeystoreService _keystoreService;

  double? _lastLat;
  double? _lastLng;
  bool _refreshInFlight = false;

  @override
  ({double? lat, double? lng}) getCachedCoordinates() {
    return (lat: _lastLat, lng: _lastLng);
  }

  @override
  Future<({double? lat, double? lng, DateTime? timestampUtc})>
      refreshLocationAndClock() async {
    if (_refreshInFlight) {
      return (lat: _lastLat, lng: _lastLng, timestampUtc: null);
    }
    _refreshInFlight = true;

    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        return (lat: _lastLat, lng: _lastLng, timestampUtc: null);
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        return (lat: _lastLat, lng: _lastLng, timestampUtc: null);
      }

      final lastKnown = await Geolocator.getLastKnownPosition();
      if (lastKnown != null) {
        _lastLat = lastKnown.latitude;
        _lastLng = lastKnown.longitude;
        await _keystoreService.updateHighWaterMarkIfNewer(
          lastKnown.timestamp.toUtc(),
        );
      }

      final settings = Platform.isAndroid
          ? AndroidSettings(
              accuracy: LocationAccuracy.medium,
              forceLocationManager: true,
              timeLimit: const Duration(seconds: 3),
            )
          : const LocationSettings(
              accuracy: LocationAccuracy.medium,
              timeLimit: Duration(seconds: 3),
            );

      final current = await Geolocator.getCurrentPosition(
        locationSettings: settings,
      );
      _lastLat = current.latitude;
      _lastLng = current.longitude;
      final gpsUtc = current.timestamp.toUtc();
      await _keystoreService.updateHighWaterMarkIfNewer(gpsUtc);

      return (lat: _lastLat, lng: _lastLng, timestampUtc: gpsUtc);
    } catch (_) {
      return (lat: _lastLat, lng: _lastLng, timestampUtc: null);
    } finally {
      _refreshInFlight = false;
    }
  }
}
