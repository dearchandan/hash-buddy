import 'app_user.dart';

/// One line in a ride's chat.
class Message {
  const Message({
    required this.id,
    required this.type,
    required this.body,
    required this.isMine,
    required this.createdAt,
    this.sender,
  });

  factory Message.fromJson(Map<String, dynamic> json) {
    final dynamic sender = json['sender'];

    return Message(
      id: json['id'] as int,
      type: json['type'] as String? ?? 'text',
      body: json['body'] as String? ?? '',
      isMine: json['is_mine'] as bool? ?? false,
      sender: sender is Map<String, dynamic> ? AppUser.fromJson(sender) : null,
      createdAt: DateTime.tryParse(json['created_at'] as String? ?? '')?.toLocal() ?? DateTime.now(),
    );
  }

  final int id;
  final String type;
  final String body;
  final bool isMine;

  /// Null for system lines, which are the app talking rather than a traveller.
  final AppUser? sender;
  final DateTime createdAt;

  bool get isSystem => type == 'system';
}
