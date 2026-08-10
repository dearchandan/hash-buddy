import 'ride_group.dart';
import 'zone.dart';

class RideRequest {
  const RideRequest({
    required this.id,
    required this.status,
    required this.terminal,
    required this.zoneId,
    required this.windowStart,
    required this.windowEnd,
    required this.seats,
    required this.luggageCount,
    required this.genderPreference,
    this.zone,
    this.dropLandmark,
    this.flightNumber,
    this.note,
    this.rideGroupId,
    this.group,
  });

  factory RideRequest.fromJson(Map<String, dynamic> json) => RideRequest(
        id: json['id'] as int,
        status: json['status'] as String? ?? 'open',
        terminal: json['terminal'] as String? ?? 'T2',
        zoneId: json['zone_id'] as int? ?? 0,
        windowStart: DateTime.parse(json['window_start'] as String).toLocal(),
        windowEnd: DateTime.parse(json['window_end'] as String).toLocal(),
        seats: json['seats'] as int? ?? 1,
        luggageCount: json['luggage_count'] as int? ?? 1,
        genderPreference: json['gender_preference'] as String? ?? 'any',
        dropLandmark: json['drop_landmark'] as String?,
        flightNumber: json['flight_number'] as String?,
        note: json['note'] as String?,
        rideGroupId: json['ride_group_id'] as int?,
        zone: json['zone'] is Map<String, dynamic> ? Zone.fromJson(json['zone'] as Map<String, dynamic>) : null,
        group: json['group'] is Map<String, dynamic> ? RideGroup.fromJson(json['group'] as Map<String, dynamic>) : null,
      );

  final int id;
  final String status;
  final String terminal;
  final int zoneId;
  final DateTime windowStart;
  final DateTime windowEnd;
  final int seats;
  final int luggageCount;
  final String genderPreference;
  final Zone? zone;
  final String? dropLandmark;
  final String? flightNumber;
  final String? note;
  final int? rideGroupId;
  final RideGroup? group;

  bool get isOpen => status == 'open';

  bool get isMatched => status == 'matched';

  bool get isWomenOnly => genderPreference == 'women_only';
}
