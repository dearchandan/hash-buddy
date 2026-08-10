import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../models/match_candidate.dart';
import '../models/ride_group.dart';
import '../models/ride_request.dart';
import '../models/zone.dart';

class RidesController extends ChangeNotifier {
  RidesController(this._api);

  final ApiClient _api;

  List<Zone> _zones = <Zone>[];
  List<RideRequest> _myRequests = <RideRequest>[];
  List<RideGroup> _myGroups = <RideGroup>[];
  bool _loading = false;

  List<Zone> get zones => _zones;
  List<RideRequest> get myRequests => _myRequests;
  List<RideGroup> get myGroups => _myGroups;
  bool get loading => _loading;

  List<RideRequest> get openRequests => _myRequests.where((RideRequest r) => r.isOpen).toList();

  Future<void> loadZones() async {
    if (_zones.isNotEmpty) {
      return;
    }
    _zones = ApiClient.unwrapList(await _api.get('/zones')).map(Zone.fromJson).toList();
    notifyListeners();
  }

  /// Everything the home screen shows, in one pass.
  Future<void> refreshHome() async {
    _loading = true;
    notifyListeners();

    try {
      await loadZones();
      final List<dynamic> responses = await Future.wait(<Future<dynamic>>[
        _api.get('/ride-requests'),
        _api.get('/groups'),
      ]);

      _myRequests = ApiClient.unwrapList(responses[0]).map(RideRequest.fromJson).toList();
      _myGroups = ApiClient.unwrapList(responses[1]).map(RideGroup.fromJson).toList();
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  Future<RideRequest> createRequest({
    required String terminal,
    required int zoneId,
    required DateTime windowStart,
    required DateTime windowEnd,
    required int luggageCount,
    required String genderPreference,
    String? flightNumber,
    String? dropLandmark,
    String? note,
  }) async {
    final RideRequest created = RideRequest.fromJson(ApiClient.unwrap(
      await _api.post('/ride-requests', body: <String, dynamic>{
        'terminal': terminal,
        'zone_id': zoneId,
        'window_start': windowStart.toUtc().toIso8601String(),
        'window_end': windowEnd.toUtc().toIso8601String(),
        'luggage_count': luggageCount,
        'gender_preference': genderPreference,
        if (flightNumber != null && flightNumber.isNotEmpty) 'flight_number': flightNumber,
        if (dropLandmark != null && dropLandmark.isNotEmpty) 'drop_landmark': dropLandmark,
        if (note != null && note.isNotEmpty) 'note': note,
      }),
    ));

    _myRequests = <RideRequest>[created, ..._myRequests];
    notifyListeners();

    return created;
  }

  /// Find mates for a request.
  Future<List<MatchCandidate>> findMatches(int rideRequestId) async {
    final dynamic response = await _api.get('/ride-requests/$rideRequestId/matches');

    return ApiClient.unwrapList(response).map(MatchCandidate.fromJson).toList();
  }

  Future<RideGroup> joinGroup({required int groupId, required int rideRequestId}) async {
    final dynamic response = await _api.post(
      '/groups/$groupId/join',
      body: <String, dynamic>{'ride_request_id': rideRequestId},
    );

    final RideGroup group = RideGroup.fromJson((response as Map<String, dynamic>)['group'] as Map<String, dynamic>);
    await refreshHome();

    return group;
  }

  /// Open a ride of your own so matching travellers can join it.
  Future<RideGroup> createGroup({
    required int rideRequestId,
    int? maxSeats,
    String? meetingPoint,
  }) async {
    final RideGroup group = RideGroup.fromJson(ApiClient.unwrap(
      await _api.post('/groups', body: <String, dynamic>{
        'ride_request_id': rideRequestId,
        if (maxSeats != null) 'max_seats': maxSeats,
        if (meetingPoint != null && meetingPoint.isNotEmpty) 'meeting_point': meetingPoint,
      }),
    ));

    await refreshHome();

    return group;
  }

  /// Take the best open seat, or open a ride when nothing matches yet.
  Future<({RideGroup group, bool joined})> autoMatch(int rideRequestId) async {
    final Map<String, dynamic> response =
        await _api.post('/ride-requests/$rideRequestId/auto-match') as Map<String, dynamic>;

    final RideGroup group = RideGroup.fromJson(response['group'] as Map<String, dynamic>);
    await refreshHome();

    return (group: group, joined: response['action'] == 'joined');
  }

  Future<RideGroup> leaveGroup(int groupId) async {
    final Map<String, dynamic> response =
        await _api.post('/groups/$groupId/leave') as Map<String, dynamic>;

    final RideGroup group = RideGroup.fromJson(response['group'] as Map<String, dynamic>);
    await refreshHome();

    return group;
  }

  Future<RideGroup> loadGroup(int groupId) async {
    return RideGroup.fromJson(ApiClient.unwrap(await _api.get('/groups/$groupId')));
  }

  Future<void> cancelRequest(int rideRequestId) async {
    await _api.delete('/ride-requests/$rideRequestId');
    await refreshHome();
  }

  void reset() {
    _zones = <Zone>[];
    _myRequests = <RideRequest>[];
    _myGroups = <RideGroup>[];
    notifyListeners();
  }
}
