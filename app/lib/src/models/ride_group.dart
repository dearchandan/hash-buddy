import 'app_user.dart';
import 'fare_estimate.dart';
import 'zone.dart';

class RideGroupMember {
  const RideGroupMember({
    required this.id,
    required this.role,
    required this.seats,
    required this.user,
  });

  factory RideGroupMember.fromJson(Map<String, dynamic> json) => RideGroupMember(
        id: json['id'] as int,
        role: json['role'] as String? ?? 'member',
        seats: json['seats'] as int? ?? 1,
        user: AppUser.fromJson(json['user'] as Map<String, dynamic>),
      );

  final int id;
  final String role;
  final int seats;
  final AppUser user;

  bool get isHost => role == 'host';
}

class RideGroup {
  const RideGroup({
    required this.id,
    required this.code,
    required this.status,
    required this.terminal,
    required this.windowStart,
    required this.windowEnd,
    required this.maxSeats,
    required this.seatsTaken,
    required this.seatsAvailable,
    required this.genderPolicy,
    required this.isMember,
    required this.isHost,
    required this.members,
    this.zone,
    this.meetingPoint,
    this.fareEstimate,
    this.quotedFare,
    this.fareShare,
    this.cabServiceLabel,
  });

  factory RideGroup.fromJson(Map<String, dynamic> json) => RideGroup(
        id: json['id'] as int,
        code: json['code'] as String? ?? '',
        status: json['status'] as String? ?? 'forming',
        terminal: json['terminal'] as String? ?? 'T2',
        windowStart: DateTime.parse(json['window_start'] as String).toLocal(),
        windowEnd: DateTime.parse(json['window_end'] as String).toLocal(),
        maxSeats: json['max_seats'] as int? ?? 2,
        seatsTaken: json['seats_taken'] as int? ?? 0,
        seatsAvailable: json['seats_available'] as int? ?? 0,
        genderPolicy: json['gender_policy'] as String? ?? 'any',
        isMember: json['is_member'] as bool? ?? false,
        isHost: json['is_host'] as bool? ?? false,
        meetingPoint: json['meeting_point'] as String?,
        quotedFare: json['quoted_fare'] as int?,
        fareShare: json['fare_share'] as int?,
        cabServiceLabel: json['cab_service_label'] as String?,
        zone: json['zone'] is Map<String, dynamic> ? Zone.fromJson(json['zone'] as Map<String, dynamic>) : null,
        fareEstimate: json['fare_estimate'] is Map<String, dynamic>
            ? FareEstimate.fromJson(json['fare_estimate'] as Map<String, dynamic>)
            : null,
        members: (json['members'] as List<dynamic>? ?? <dynamic>[])
            .whereType<Map<String, dynamic>>()
            .map(RideGroupMember.fromJson)
            .toList(),
      );

  final int id;
  final String code;
  final String status;
  final String terminal;
  final DateTime windowStart;
  final DateTime windowEnd;
  final int maxSeats;
  final int seatsTaken;
  final int seatsAvailable;
  final String genderPolicy;
  final bool isMember;

  /// Closing and completing belong to whoever opened the ride, so the app only
  /// offers those buttons to them rather than letting the server 403.
  final bool isHost;
  final List<RideGroupMember> members;
  final Zone? zone;
  final String? meetingPoint;
  final FareEstimate? fareEstimate;

  /// What the host actually saw in Ola or Uber, and each traveller's share of
  /// it at the ride's current occupancy. Null when nobody checked a fare —
  /// deliberately separate from [fareEstimate], which is a seeded guess.
  final int? quotedFare;
  final int? fareShare;
  final String? cabServiceLabel;

  bool get hasQuotedFare => quotedFare != null;

  bool get isWomenOnly => genderPolicy == 'women_only';

  bool get isLocked => status == 'locked';

  bool get isCancelled => status == 'cancelled';

  bool get acceptsJoins => status == 'forming' && seatsAvailable > 0;
}
