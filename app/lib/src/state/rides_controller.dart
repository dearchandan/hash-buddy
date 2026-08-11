import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../models/match_candidate.dart';
import '../models/open_ride.dart';
import '../models/ride_group.dart';
import '../models/ride_request.dart';
import '../models/zone.dart';

class RidesController extends ChangeNotifier {
  RidesController(this._api);

  final ApiClient _api;

  List<Zone> _zones = <Zone>[];
  List<Area> _areas = <Area>[];
  List<RideRequest> _myRequests = <RideRequest>[];
  List<RideGroup> _myGroups = <RideGroup>[];
  bool _loading = false;

  List<Zone> get zones => _zones;
  List<Area> get areas => _areas;
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
    int seats = 1,
    String? flightNumber,
    String? dropLandmark,
    String? note,
    int? quotedFare,
    String? cabService,
    String? meetingPoint,
  }) async {
    final RideRequest created = RideRequest.fromJson(ApiClient.unwrap(
      await _api.post('/ride-requests', body: <String, dynamic>{
        'terminal': terminal,
        'zone_id': zoneId,
        'window_start': windowStart.toUtc().toIso8601String(),
        'window_end': windowEnd.toUtc().toIso8601String(),
        'seats': seats,
        'luggage_count': luggageCount,
        'gender_preference': genderPreference,
        if (flightNumber != null && flightNumber.isNotEmpty) 'flight_number': flightNumber,
        if (dropLandmark != null && dropLandmark.isNotEmpty) 'drop_landmark': dropLandmark,
        if (note != null && note.isNotEmpty) 'note': note,
        // Omitted rather than sent null when the traveller skipped them, so a
        // blank field never overwrites something with nothing.
        if (quotedFare != null) 'quoted_fare': quotedFare,
        if (cabService != null) 'cab_service': cabService,
        if (meetingPoint != null && meetingPoint.isNotEmpty) 'meeting_point': meetingPoint,
      }),
    ));

    _myRequests = <RideRequest>[created, ..._myRequests];
    notifyListeners();

    return created;
  }

  /// Areas people are already heading to, for the home screen.
  ///
  /// Deliberately not part of refreshHome's Future.wait: it is the one call
  /// that must not fail the whole screen. A traveller with their own rides on
  /// display should still see them if this errors.
  Future<void> loadAreas() async {
    try {
      _areas = ApiClient.unwrapList(await _api.get('/areas')).map(Area.fromJson).toList();
    } catch (_) {
      _areas = <Area>[];
    }
    notifyListeners();
  }

  /// Every ride heading to one area that still has a seat.
  Future<List<OpenRide>> openRidesIn(int zoneId, {String? terminal}) async {
    final dynamic response = await _api.get(
      '/zones/$zoneId/open-rides',
      query: <String, String>{if (terminal != null) 'terminal': terminal},
    );

    return ApiClient.unwrapList(response).map(OpenRide.fromJson).toList();
  }

  /// Take a seat in a ride found by browsing.
  ///
  /// No terminal, zone or window: all of that is already on the ride, and
  /// asking again would be asking someone to describe the trip on their screen.
  Future<RideGroup> quickJoin({
    required int groupId,
    int seats = 1,
    int luggageCount = 1,
  }) async {
    final dynamic response = await _api.post(
      '/groups/$groupId/quick-join',
      body: <String, dynamic>{'seats': seats, 'luggage_count': luggageCount},
    );

    final RideGroup group = RideGroup.fromJson(response['group'] as Map<String, dynamic>);
    await refreshHome();

    return group;
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

  /// Close a ride you opened, for everyone on it.
  Future<RideGroup> cancelGroup(int groupId) async {
    final dynamic response = await _api.post('/groups/$groupId/cancel');
    final RideGroup group = RideGroup.fromJson(response['group'] as Map<String, dynamic>);
    await refreshHome();

    return group;
  }

  /// Mark the ride done. Capacity closes at whoever actually came, so the fare
  /// splits between the people in the cab rather than the seats booked.
  Future<RideGroup> completeGroup(int groupId) async {
    final dynamic response = await _api.post('/groups/$groupId/complete');
    final RideGroup group = RideGroup.fromJson(response['group'] as Map<String, dynamic>);
    await refreshHome();

    return group;
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
