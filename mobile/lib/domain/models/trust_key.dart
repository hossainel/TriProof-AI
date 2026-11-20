/// A public Ed25519 verification key provisioned from a TriProof website.
class TrustKey {
  /// Creates a [TrustKey].
  const TrustKey({
    required this.version,
    required this.publicKey,
    this.status = 'active',
    this.buyerId,
  });

  /// Key version such as `K00` or `K01`.
  final String version;

  /// Base64-encoded 32-byte Ed25519 public key.
  final String publicKey;

  /// `active` or `verify_only`.
  final String status;

  /// Optional buyer scope reported by the server.
  final String? buyerId;

  /// Serializes this key for SQLCipher `app_meta` storage.
  Map<String, dynamic> toMap() => {
    'key_version': version,
    'public_key': publicKey,
    'status': status,
    if (buyerId != null) 'buyer_id': buyerId,
  };

  /// Parses a key from persisted JSON.
  factory TrustKey.fromMap(Map<String, dynamic> map) {
    return TrustKey(
      version: (map['key_version'] ?? map['version'] ?? 'K00').toString(),
      publicKey: (map['public_key'] ?? map['public_key_base64'] ?? '')
          .toString(),
      status: (map['status'] ?? 'active').toString(),
      buyerId: map['buyer_id']?.toString(),
    );
  }
}
