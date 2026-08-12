import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/app_user.dart';
import '../../models/ride_group.dart';
import '../../push/push_service.dart';
import '../../state/rides_controller.dart';
import '../screens/call_screen.dart';

/// Raises the call screen when someone rings this traveller.
///
/// Call invites are data-only pushes on purpose, so that a call which has been
/// answered or missed does not also leave a stale banner in the tray. The cost
/// of that choice is that Android shows the callee nothing at all: something in
/// the app has to listen and put a screen up. Without this widget the invite is
/// delivered and silently dropped, which looks like a call that rings forever on
/// one phone and never arrives on the other.
class IncomingCallWatcher extends StatefulWidget {
  const IncomingCallWatcher({super.key, required this.child});

  final Widget child;

  /// Who is calling, for the call screen's header.
  ///
  /// The ride is the authority — it carries the traveller's photo and rating.
  /// The push payload is the fallback for a caller who has since left the ride:
  /// showing their name beats showing nobody.
  static AppUser callerFor(RideGroup group, PushEvent event) {
    final int callerId = int.tryParse(event.data['caller_id'] ?? '') ?? 0;

    for (final RideGroupMember member in group.members) {
      if (member.user.id == callerId) {
        return member.user;
      }
    }

    return AppUser(id: callerId, name: event.callerName);
  }

  @override
  State<IncomingCallWatcher> createState() => _IncomingCallWatcherState();
}

class _IncomingCallWatcherState extends State<IncomingCallWatcher> {
  StreamSubscription<PushEvent>? _subscription;

  /// The call already on screen. FCM redelivers freely, and two stacked call
  /// screens would leave one of them holding the microphone after the other was
  /// dismissed.
  int? _showing;
  Route<void>? _route;

  @override
  void initState() {
    super.initState();
    _subscription = context.read<PushService>().events.listen(_onPush);
  }

  @override
  void dispose() {
    _subscription?.cancel();
    super.dispose();
  }

  void _onPush(PushEvent event) {
    switch (event.type) {
      case 'call.incoming':
        unawaited(_ring(event));
      case 'call.ended':
        _dismiss(event.callId);
    }
  }

  Future<void> _ring(PushEvent event) async {
    final int? callId = event.callId;
    final int? groupId = event.groupId;

    if (callId == null || groupId == null || _showing != null) {
      return;
    }

    _showing = callId;

    // Both read before the await, because the context must not be touched
    // across it.
    final NavigatorState navigator = Navigator.of(context);
    final RidesController rides = context.read<RidesController>();

    final RideGroup group;
    try {
      group = await rides.loadGroup(groupId);
    } catch (_) {
      // Without the ride there is nothing to put on screen. The caller watches
      // it go unanswered, which is the honest outcome.
      _showing = null;

      return;
    }

    final Route<void> route = MaterialPageRoute<void>(
      builder: (_) => CallScreen.incoming(
        group: group,
        other: IncomingCallWatcher.callerFor(group, event),
        callId: callId,
      ),
    );
    _route = route;

    await navigator.push(route);

    _showing = null;
    _route = null;
  }

  /// The caller hung up before it was answered, so take the screen away rather
  /// than leave it ringing at someone who can no longer reach anybody.
  void _dismiss(int? callId) {
    final Route<void>? route = _route;

    if (route == null || callId == null || callId != _showing || !route.isActive) {
      return;
    }

    Navigator.of(context).removeRoute(route);
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
