import 'package:flutter_test/flutter_test.dart';
import 'package:hash_buddy/src/models/app_user.dart';
import 'package:hash_buddy/src/models/ride_group.dart';
import 'package:hash_buddy/src/push/push_service.dart';
import 'package:hash_buddy/src/ui/widgets/incoming_call_watcher.dart';

void main() {
  group('PushEvent', () {
    test('reads the call and ride out of a call invite', () {
      const PushEvent event = PushEvent(
        type: 'call.incoming',
        data: <String, String>{
          'call_id': '42',
          'ride_group_id': '7',
          'caller_id': '3',
          'caller_name': 'Priya',
        },
      );

      expect(event.callId, 42);
      expect(event.groupId, 7);
      expect(event.callerName, 'Priya');
    });

    test('survives a payload with the ids missing', () {
      // A malformed invite must not raise a call screen that cannot work.
      const PushEvent event = PushEvent(type: 'call.incoming', data: <String, String>{});

      expect(event.callId, isNull);
      expect(event.groupId, isNull);
      expect(event.callerName, 'Your ride mate');
    });
  });

  group('IncomingCallWatcher.callerFor', () {
    test('prefers the traveller on the ride', () {
      final RideGroup group = _group(<Map<String, dynamic>>[
        <String, dynamic>{
          'id': 1,
          'role': 'host',
          'seats': 1,
          'user': <String, dynamic>{'id': 3, 'name': 'Priya', 'rating_count': 4},
        },
      ]);

      const PushEvent event = PushEvent(
        type: 'call.incoming',
        data: <String, String>{'caller_id': '3', 'caller_name': 'stale name'},
      );

      final AppUser caller = IncomingCallWatcher.callerFor(group, event);

      // The ride carries the rating and photo; the push carries neither.
      expect(caller.name, 'Priya');
      expect(caller.hasRating, isTrue);
    });

    test('falls back to the push when the caller has left the ride', () {
      final RideGroup group = _group(<Map<String, dynamic>>[]);

      const PushEvent event = PushEvent(
        type: 'call.incoming',
        data: <String, String>{'caller_id': '3', 'caller_name': 'Priya'},
      );

      // Showing their name beats showing nobody.
      expect(IncomingCallWatcher.callerFor(group, event).name, 'Priya');
      expect(IncomingCallWatcher.callerFor(group, event).id, 3);
    });
  });
}

RideGroup _group(List<Map<String, dynamic>> members) => RideGroup.fromJson(<String, dynamic>{
      'id': 7,
      'code': 'HB-7',
      'status': 'forming',
      'terminal': 'T1',
      'window_start': '2026-09-01T12:00:00Z',
      'window_end': '2026-09-01T12:40:00Z',
      'members': members,
    });
