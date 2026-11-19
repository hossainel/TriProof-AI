import 'dart:convert';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';

/// Compact probabilistic set membership filter persisted in `app_meta`.
///
/// Wire format matches the cloud `bloom_filter` object:
/// `{bits_b64, m_bits, k_hashes, seed}`. Positions are derived with
/// `HMAC-SHA256(key = utf8(seed decimal), message = value:i)`, taking the first
/// four bytes big-endian, masking off the sign bit, and reducing modulo
/// `m_bits`.
class BloomFilter {
  /// Creates a [BloomFilter] from raw bit bytes and parameters.
  const BloomFilter({
    required this.bits,
    required this.mBits,
    required this.kHashes,
    required this.seed,
  });

  /// Default backend seed (`0x54524950`, "TRIP").
  static const int defaultSeed = 0x54524950;

  /// Packed bit array (one bit per slot).
  final Uint8List bits;

  /// Number of bits in the filter.
  final int mBits;

  /// Number of HMAC positions probed per value.
  final int kHashes;

  /// HMAC key seed.
  final int seed;

  /// Whether the filter carries usable parameters and bit storage.
  bool get isValid => mBits >= 8 && kHashes >= 1 && bits.isNotEmpty;

  /// Parses a backend `bloom_filter` map (also accepts `bloom_bits_b64`).
  factory BloomFilter.fromMap(Map<String, dynamic> map) {
    final rawBits = map['bits_b64'] ?? map['bloom_bits_b64'] ?? '';
    Uint8List decoded;
    try {
      decoded = Uint8List.fromList(base64Decode(rawBits.toString().trim()));
    } on FormatException {
      decoded = Uint8List(0);
    }

    return BloomFilter(
      bits: decoded,
      mBits: (map['m_bits'] as num?)?.toInt() ?? 0,
      kHashes: (map['k_hashes'] as num?)?.toInt() ?? 0,
      seed: (map['seed'] as num?)?.toInt() ?? defaultSeed,
    );
  }

  /// Parses a JSON-encoded [BloomFilter].
  factory BloomFilter.fromJson(String raw) =>
      BloomFilter.fromMap(jsonDecode(raw) as Map<String, dynamic>);

  /// Serializes back to the backend wire map.
  Map<String, dynamic> toMap() => {
        'bits_b64': base64Encode(bits),
        'm_bits': mBits,
        'k_hashes': kHashes,
        'seed': seed,
      };

  /// Serializes to a JSON string for `app_meta` persistence.
  String toJson() => jsonEncode(toMap());

  /// Returns `false` only when at least one probed bit is definitely unset.
  ///
  /// A `true` result is a *possible* match and must still be confirmed by the
  /// exact `cached_blacklist` lookup.
  bool mightContain(String value) {
    if (!isValid) return false;
    for (final position in _positions(value)) {
      final byte = position ~/ 8;
      if (byte >= bits.length) return false;
      final mask = 1 << (position % 8);
      if ((bits[byte] & mask) == 0) return false;
    }
    return true;
  }

  /// Builds a filter containing [values] (primarily for parity tests and
  /// offline provisioning).
  static BloomFilter build(
    Iterable<String> values, {
    int mBits = 8192,
    int kHashes = 4,
    int seed = defaultSeed,
  }) {
    final filter = BloomFilter(
      bits: Uint8List((mBits + 7) ~/ 8),
      mBits: mBits,
      kHashes: kHashes,
      seed: seed,
    );
    for (final value in values.toSet()) {
      for (final position in filter._positions(value)) {
        filter.bits[position ~/ 8] |= 1 << (position % 8);
      }
    }
    return filter;
  }

  Iterable<int> _positions(String value) sync* {
    final hmac = Hmac(sha256, utf8.encode(seed.toString()));
    for (var i = 0; i < kHashes; i++) {
      final digest = hmac.convert(utf8.encode('$value:$i')).bytes;
      final word = (digest[0] << 24) |
          (digest[1] << 16) |
          (digest[2] << 8) |
          digest[3];
      yield (word & 0x7fffffff) % mBits;
    }
  }
}
