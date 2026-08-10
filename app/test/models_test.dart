import 'package:flutter_test/flutter_test.dart';
import 'package:hash_buddy/src/api/api_client.dart';
import 'package:hash_buddy/src/api/api_exception.dart';
import 'package:hash_buddy/src/models/match_candidate.dart';
import 'package:hash_buddy/src/models/ride_group.dart';

void main() {
  group('ApiClient.unwrap', () {
    test('pulls the payload out of a data envelope', () {
      expect(
        ApiClient.unwrap(<String, dynamic>{
          'data': <String, dynamic>{'id': 7},
        }),
        containsPair('id', 7),
      );
    });

    test('accepts a bare object too', () {
      expect(ApiClient.unwrap(<String, dynamic>{'id': 7}), containsPair('id', 7));
    });

    test('reads a list out of a collection response', () {
      final List<Map<String, dynamic>> list = ApiClient.unwrapList(<String, dynamic>{
        'data': <dynamic>[
          <String, dynamic>{'id': 1},
          <String, dynamic>{'id': 2},
        ],
      });

      expect(list, hasLength(2));
    });
  });

  group('ApiException', () {
    test('surfaces the first validation message', () {
      final ApiException error = ApiException.fromResponse(422, <String, dynamic>{
        'message': 'The given data was invalid.',
        'errors': <String, dynamic>{
          'window_end': <dynamic>['Your departure window must be at least 15 minutes wide.'],
        },
      });

      expect(error.firstFieldError, contains('at least 15 minutes'));
      expect(error.statusCode, 422);
    });

    test('keeps the domain error code', () {
      final ApiException error = ApiException.fromResponse(409, <String, dynamic>{
        'message': 'This ride is already full.',
        'error': 'group_full',
      });

      expect(error.errorCode, 'group_full');
      expect(error.firstFieldError, isNull);
    });
  });

  group('MatchCandidate', () {
    test('a group match can be joined directly', () {
      final MatchCandidate match = MatchCandidate.fromJson(_groupMatchJson);

      expect(match.isGroup, isTrue);
      expect(match.canJoinDirectly, isTrue);
      expect(match.overlapMinutes, 30);
      expect(match.fareEstimate.perHeadFare, 625);
      expect(match.group!.seatsAvailable, 2);
    });

    test('a lone traveller needs a ride opened for them', () {
      final MatchCandidate match = MatchCandidate.fromJson(_travellerMatchJson);

      expect(match.isGroup, isFalse);
      expect(match.canJoinDirectly, isFalse);
      expect(match.sameFlight, isTrue);
      expect(match.traveller!.user.name, 'Priya');
    });
  });

  group('RideGroup', () {
    test('reads seats and status', () {
      final RideGroup group = RideGroup.fromJson(_groupMatchJson['group']! as Map<String, dynamic>);

      expect(group.code, 'WM0SJA');
      expect(group.acceptsJoins, isTrue);
      expect(group.isWomenOnly, isFalse);
      expect(group.members, hasLength(1));
    });
  });
}

const Map<String, dynamic> _fareJson = <String, dynamic>{
  'vehicle_class': 'sedan',
  'total_fare': 1250,
  'per_head_fare': 625,
  'solo_fare': 1250,
  'savings_per_head': 625,
  'savings_percent': 50,
  'passengers': 2,
  'luggage': 2,
  'currency': 'INR',
};

const Map<String, dynamic> _groupMatchJson = <String, dynamic>{
  'type': 'group',
  'score': 91,
  'overlap_minutes': 30,
  'same_flight': false,
  'action': 'join_group',
  'fare_estimate': _fareJson,
  'group': <String, dynamic>{
    'id': 3,
    'code': 'WM0SJA',
    'status': 'forming',
    'terminal': 'T2',
    'window_start': '2026-08-10T12:00:00+00:00',
    'window_end': '2026-08-10T12:40:00+00:00',
    'max_seats': 3,
    'seats_taken': 1,
    'seats_available': 2,
    'gender_policy': 'any',
    'is_member': false,
    'members': <dynamic>[
      <String, dynamic>{
        'id': 1,
        'role': 'host',
        'status': 'joined',
        'seats': 1,
        'user': <String, dynamic>{'id': 9, 'name': 'Rahul', 'rating_avg': 4.6, 'rating_count': 8},
      },
    ],
  },
  'traveller': null,
};

const Map<String, dynamic> _travellerMatchJson = <String, dynamic>{
  'type': 'traveller',
  'score': 99,
  'overlap_minutes': 40,
  'same_flight': true,
  'action': 'create_group',
  'fare_estimate': _fareJson,
  'group': null,
  'traveller': <String, dynamic>{
    'ride_request_id': 12,
    'window_start': '2026-08-10T12:10:00+00:00',
    'window_end': '2026-08-10T12:50:00+00:00',
    'drop_landmark': 'Sony World Signal',
    'flight_number': 'AI2846',
    'luggage_count': 2,
    'user': <String, dynamic>{'id': 4, 'name': 'Priya', 'rating_avg': 4.8, 'rating_count': 12},
  },
};
