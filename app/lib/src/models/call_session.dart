/// A voice call between two travellers on the same ride.
///
/// The server brokers one offer and one answer and then steps out; everything
/// after that is peer-to-peer.
class CallSession {
  const CallSession({
    required this.id,
    required this.rideGroupId,
    required this.status,
    required this.callerId,
    required this.calleeId,
    required this.isCaller,
    this.offerSdp,
    this.answerSdp,
    this.endReason,
  });

  factory CallSession.fromJson(Map<String, dynamic> json) => CallSession(
        id: json['id'] as int,
        rideGroupId: json['ride_group_id'] as int,
        status: json['status'] as String? ?? 'ringing',
        callerId: json['caller_id'] as int,
        calleeId: json['callee_id'] as int,
        isCaller: json['is_caller'] as bool? ?? false,
        // Each side is only ever sent the description it needs, so exactly one
        // of these is populated.
        offerSdp: json['offer_sdp'] as String?,
        answerSdp: json['answer_sdp'] as String?,
        endReason: json['end_reason'] as String?,
      );

  final int id;
  final int rideGroupId;
  final String status;
  final int callerId;
  final int calleeId;
  final bool isCaller;
  final String? offerSdp;
  final String? answerSdp;
  final String? endReason;

  bool get isRinging => status == 'ringing';
  bool get isAccepted => status == 'accepted';
  bool get isOver => status == 'declined' || status == 'ended' || status == 'missed';

  String get endedLabel => switch (status) {
        'declined' => 'Call declined',
        'missed' => 'No answer',
        _ => 'Call ended',
      };
}

/// ICE configuration, fetched immediately before a call because the TURN
/// credentials in it are short-lived.
class IceConfig {
  const IceConfig({required this.iceServers, required this.pollSeconds, required this.ringSeconds});

  factory IceConfig.fromJson(Map<String, dynamic> json) => IceConfig(
        iceServers: (json['ice_servers'] as List<dynamic>? ?? <dynamic>[])
            .whereType<Map<String, dynamic>>()
            .toList(),
        pollSeconds: json['poll_seconds'] as int? ?? 2,
        ringSeconds: json['ring_seconds'] as int? ?? 45,
      );

  final List<Map<String, dynamic>> iceServers;
  final int pollSeconds;
  final int ringSeconds;

  /// Without a relay, calls between two Indian mobile networks fail a large
  /// share of the time — both ends sit behind symmetric NAT with no direct
  /// path. Worth surfacing rather than letting people guess why a call died.
  bool get hasTurn => iceServers.any((Map<String, dynamic> s) => s['username'] != null);
}
