/// Retroactive clone alert or precision recall notice stored in SQLite.
class AppNotification {
  /// Creates an [AppNotification].
  const AppNotification({
    this.id,
    required this.outerSerial,
    required this.severity,
    required this.titleEn,
    required this.messageEn,
    required this.titleBn,
    required this.messageBn,
    required this.isRead,
    required this.createdAt,
  });

  /// SQLite primary key.
  final int? id;

  /// Related product outer serial or batch identifier.
  final String outerSerial;

  /// Alert severity level (`critical`, `high`, `warning`, `info`).
  final String severity;

  /// English notification title.
  final String titleEn;

  /// English notification detail body.
  final String messageEn;

  /// Bangla notification title.
  final String titleBn;

  /// Bangla notification detail body.
  final String messageBn;

  /// Whether the user has acknowledged this notification.
  final bool isRead;

  /// UTC creation timestamp.
  final DateTime createdAt;

  /// Converts this notification to a SQLite row map.
  Map<String, Object?> toMap() {
    return {
      if (id != null) 'id': id,
      'outer_serial': outerSerial,
      'severity': severity,
      'title_en': titleEn,
      'message_en': messageEn,
      'title_bn': titleBn,
      'message_bn': messageBn,
      'is_read': isRead ? 1 : 0,
      'created_at': createdAt.toUtc().toIso8601String(),
    };
  }

  /// Reconstructs an [AppNotification] from a SQLite row map.
  factory AppNotification.fromMap(Map<String, Object?> map) {
    return AppNotification(
      id: map['id'] as int?,
      outerSerial: map['outer_serial'] as String,
      severity: map['severity'] as String,
      titleEn: map['title_en'] as String,
      messageEn: map['message_en'] as String,
      titleBn: map['title_bn'] as String,
      messageBn: map['message_bn'] as String,
      isRead: (map['is_read'] as int? ?? 0) == 1,
      createdAt: DateTime.tryParse(
            (map['created_at'] as String?) ?? '',
          )?.toUtc() ??
          DateTime.now().toUtc(),
    );
  }

  /// Returns a copy with [isRead] updated.
  AppNotification copyWith({bool? isRead}) {
    return AppNotification(
      id: id,
      outerSerial: outerSerial,
      severity: severity,
      titleEn: titleEn,
      messageEn: messageEn,
      titleBn: titleBn,
      messageBn: messageBn,
      isRead: isRead ?? this.isRead,
      createdAt: createdAt,
    );
  }
}
