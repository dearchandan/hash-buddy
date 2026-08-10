import 'app_user.dart';
import 'fare_estimate.dart';
import 'ride_group.dart';

/// A lone traveller you could pair up with.
class TravellerMatch {
  const TravellerMatch({
    required this.rideRequestId,
    required this.windowStart,
    required this.windowEnd,
    required this.luggageCount,
    required this.user,
    this.dropLandmark,
    this.flightNumber,
  });

  factory TravellerMatch.fromJson(Map<String, dynamic> json) => TravellerMatch(
        rideRequestId: json['ride_request_id'] as int,
        windowStart: DateTime.parse(json['window_start'] as String).toLocal(),
        windowEnd: DateTime.parse(json['window_end'] as String).toLocal(),
        luggageCount: json['luggage_count'] as int? ?? 1,
        dropLandmark: json['drop_landmark'] as String?,
        flightNumber: json['flight_number'] as String?,
        user: AppUser.fromJson(json['user'] as Map<String, dynamic>),
      );

  final int rideRequestId;
  final DateTime windowStart;
  final DateTime windowEnd;
  final int luggageCount;
  final String? dropLandmark;
  final String? flightNumber;
  final AppUser user;
}

class MatchCandidate {
  const MatchCandidate({
    required this.type,
    required this.score,
    required this.overlapMinutes,
    required this.sameFlight,
    required this.action,
    required this.fareEstimate,
    this.group,
    this.traveller,
  });

  factory MatchCandidate.fromJson(Map<String, dynamic> json) => MatchCandidate(
        type: json['type'] as String,
        score: json['score'] as int? ?? 0,
        overlapMinutes: json['overlap_minutes'] as int? ?? 0,
        sameFlight: json['same_flight'] as bool? ?? false,
        action: json['action'] as String? ?? 'create_group',
        fareEstimate: FareEstimate.fromJson(json['fare_estimate'] as Map<String, dynamic>),
        group: json['group'] is Map<String, dynamic> ? RideGroup.fromJson(json['group'] as Map<String, dynamic>) : null,
        traveller: json['traveller'] is Map<String, dynamic>
            ? TravellerMatch.fromJson(json['traveller'] as Map<String, dynamic>)
            : null,
      );

  final String type;
  final int score;
  final int overlapMinutes;
  final bool sameFlight;

  /// `join_group` when there is a seat to take, `create_group` when the match
  /// is a lone traveller and you need to open a ride for them to find.
  final String action;
  final FareEstimate fareEstimate;
  final RideGroup? group;
  final TravellerMatch? traveller;

  bool get isGroup => type == 'group';

  bool get canJoinDirectly => action == 'join_group' && group != null;
}
