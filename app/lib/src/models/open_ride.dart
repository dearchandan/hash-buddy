import 'ride_group.dart';
import 'zone.dart';

/// A ride as it appears to someone browsing an area, before they join.
class OpenRide {
  const OpenRide({
    required this.id,
    required this.code,
    required this.terminal,
    required this.windowStart,
    required this.windowEnd,
    required this.seatsTaken,
    required this.maxSeats,
    required this.seatsAvailable,
    required this.isWomenOnly,
    required this.isMember,
    required this.members,
    this.zone,
    this.quotedFare,
    this.fareShare,
    this.cabServiceLabel,
    this.meetingPoint,
  });

  factory OpenRide.fromJson(Map<String, dynamic> json) => OpenRide(
        id: json['id'] as int,
        code: json['code'] as String? ?? '',
        terminal: json['terminal'] as String? ?? '',
        windowStart: DateTime.parse(json['window_start'] as String).toLocal(),
        windowEnd: DateTime.parse(json['window_end'] as String).toLocal(),
        seatsTaken: json['seats_taken'] as int? ?? 0,
        maxSeats: json['max_seats'] as int? ?? 0,
        seatsAvailable: json['seats_available'] as int? ?? 0,
        isWomenOnly: json['is_women_only'] as bool? ?? false,
        isMember: json['is_member'] as bool? ?? false,
        zone: json['zone'] is Map<String, dynamic>
            ? Zone.fromJson(json['zone'] as Map<String, dynamic>)
            : null,
        quotedFare: json['quoted_fare'] as int?,
        fareShare: json['fare_share'] as int?,
        cabServiceLabel: json['cab_service_label'] as String?,
        meetingPoint: json['meeting_point'] as String?,
        members: (json['members'] as List<dynamic>? ?? <dynamic>[])
            .whereType<Map<String, dynamic>>()
            .map(RideGroupMember.fromJson)
            .toList(),
      );

  final int id;
  final String code;
  final String terminal;
  final DateTime windowStart;
  final DateTime windowEnd;
  final int seatsTaken;
  final int maxSeats;
  final int seatsAvailable;
  final bool isWomenOnly;

  /// Your own rides appear here alongside everyone else's; the list offers to
  /// open them rather than to join them again.
  final bool isMember;
  final Zone? zone;
  final List<RideGroupMember> members;

  /// What the host actually saw in Ola or Uber. Null when they opened the ride
  /// without checking, which is a normal thing to have done.
  final int? quotedFare;

  /// What you would pay taking one seat, if nobody else joins.
  final int? fareShare;

  final String? cabServiceLabel;
  final String? meetingPoint;

  bool get hasFare => quotedFare != null;

  String get hostName => members.isEmpty ? 'A traveller' : members.first.user.name;
}

/// An area on the home screen: where people are already heading.
class Area {
  const Area({
    required this.zone,
    required this.openRidesCount,
    required this.seatsAvailable,
    this.nextDeparture,
  });

  factory Area.fromJson(Map<String, dynamic> json) => Area(
        zone: Zone.fromJson(json),
        openRidesCount: json['open_rides_count'] as int? ?? 0,
        seatsAvailable: json['seats_available'] as int? ?? 0,
        nextDeparture: json['next_departure'] == null
            ? null
            : DateTime.parse(json['next_departure'] as String).toLocal(),
      );

  final Zone zone;
  final int openRidesCount;
  final int seatsAvailable;
  final DateTime? nextDeparture;
}
