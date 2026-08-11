import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/ride_group.dart';
import '../../state/auth_controller.dart';
import '../../state/rides_controller.dart';
import '../formatters.dart';
import '../widgets/app_card.dart';
import '../widgets/fare_summary.dart';
import '../widgets/info_chip.dart';
import '../widgets/traveller_avatar.dart';
import 'chat_screen.dart';

class GroupScreen extends StatefulWidget {
  const GroupScreen({required this.groupId, super.key});

  final int groupId;

  @override
  State<GroupScreen> createState() => _GroupScreenState();
}

class _GroupScreenState extends State<GroupScreen> {
  RideGroup? _group;
  Object? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      final RideGroup group = await context.read<RidesController>().loadGroup(widget.groupId);
      if (mounted) {
        setState(() {
          _group = group;
          _error = null;
        });
      }
    } catch (error) {
      if (mounted) {
        setState(() => _error = error);
      }
    }
  }

  Future<void> _leave() async {
    final bool confirmed = await showDialog<bool>(
          context: context,
          builder: (BuildContext context) => AlertDialog(
            title: const Text('Leave this ride?'),
            content: const Text(
              'Your seat goes back to the pool and your request starts looking for '
              'mates again.',
            ),
            actions: <Widget>[
              TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('Stay')),
              FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('Leave')),
            ],
          ),
        ) ??
        false;

    if (!confirmed || !mounted) {
      return;
    }

    setState(() => _busy = true);
    try {
      await context.read<RidesController>().leaveGroup(widget.groupId);
      if (mounted) {
        Navigator.of(context).pop();
      }
    } catch (error) {
      if (mounted) {
        showError(context, error);
        setState(() => _busy = false);
      }
    }
  }

  /// Close the ride for everyone. Confirmed hard, because the people already
  /// aboard are relying on it and will be sent looking again.
  Future<void> _cancel() async {
    final RideGroup? group = _group;
    final int others = (group?.members.length ?? 1) - 1;

    final bool confirmed = await _confirm(
      title: 'Close this ride?',
      body: others > 0
          ? 'You opened this ride, so closing it ends it for everyone. '
              '$others other traveller${others == 1 ? '' : 's'} will be told and '
              'sent looking for another ride.'
          : 'The ride disappears from the app and nobody can join it.',
      action: 'Close ride',
      destructive: true,
    );

    if (!confirmed || !mounted) {
      return;
    }

    setState(() => _busy = true);
    try {
      await context.read<RidesController>().cancelGroup(widget.groupId);
      if (mounted) {
        Navigator.of(context).pop();
      }
    } catch (error) {
      if (mounted) {
        showError(context, error);
        setState(() => _busy = false);
      }
    }
  }

  Future<void> _complete() async {
    final RideGroup? group = _group;
    final int aboard = group?.seatsTaken ?? 1;
    final bool unfilled = (group?.seatsAvailable ?? 0) > 0;

    final bool confirmed = await _confirm(
      title: 'Mark this ride completed?',
      body: unfilled
          ? 'The ride closes with the $aboard of you travelling, and the fare '
              'splits $aboard ways instead of ${group?.maxSeats ?? aboard}. '
              'Nobody else can join after this.'
          : 'The ride closes and the fare splits between all $aboard of you.',
      action: 'Mark completed',
      destructive: false,
    );

    if (!confirmed || !mounted) {
      return;
    }

    setState(() => _busy = true);
    try {
      await context.read<RidesController>().completeGroup(widget.groupId);
      if (mounted) {
        showMessage(context, 'Ride completed. Safe travels.');
        Navigator.of(context).pop();
      }
    } catch (error) {
      if (mounted) {
        showError(context, error);
        setState(() => _busy = false);
      }
    }
  }

  Future<bool> _confirm({
    required String title,
    required String body,
    required String action,
    required bool destructive,
  }) async {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return await showDialog<bool>(
          context: context,
          builder: (BuildContext context) => AlertDialog(
            title: Text(title),
            content: Text(body),
            actions: <Widget>[
              TextButton(
                onPressed: () => Navigator.of(context).pop(false),
                child: const Text('Not yet'),
              ),
              FilledButton(
                onPressed: () => Navigator.of(context).pop(true),
                style: destructive
                    ? FilledButton.styleFrom(backgroundColor: scheme.error)
                    : null,
                child: Text(action),
              ),
            ],
          ),
        ) ??
        false;
  }

  @override
  Widget build(BuildContext context) {
    final RideGroup? group = _group;

    return Scaffold(
      appBar: AppBar(
        title: Text(group == null ? 'Ride' : 'Ride ${group.code}'),
      ),
      // Only once there is someone to talk to. A chat button on a ride you are
      // sitting in alone leads to an empty room and looks broken.
      floatingActionButton: group != null && group.isMember && !group.isCancelled
          ? FloatingActionButton.extended(
              onPressed: () => Navigator.of(context).push(
                MaterialPageRoute<void>(builder: (_) => ChatScreen(group: group)),
              ),
              icon: const Icon(Icons.forum_rounded),
              label: const Text('Chat & call'),
            )
          : null,
      body: _error != null
          ? Center(child: Text(errorMessage(_error!)))
          : group == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _load,
                  child: _buildDetails(group),
                ),
    );
  }

  Widget _buildDetails(RideGroup group) {
    final ColorScheme scheme = Theme.of(context).colorScheme;
    final int? myId = context.watch<AuthController>().user?.id;

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
      children: <Widget>[
        AppCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Expanded(
                    child: Text(
                      group.zone?.name ?? 'Your ride',
                      style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
                    ),
                  ),
                  InfoChip(
                    label: switch (group.status) {
                      'locked' => 'Full',
                      'cancelled' => 'Cancelled',
                      'completed' => 'Done',
                      _ => '${group.seatsAvailable} seat${group.seatsAvailable == 1 ? '' : 's'} free',
                    },
                    tone: group.isLocked ? ChipTone.positive : ChipTone.caution,
                  ),
                ],
              ),
              const SizedBox(height: 10),
              _row(Icons.flight_land_rounded, 'Terminal ${group.terminal}, ${dayLabel(group.windowStart)}'),
              _row(Icons.schedule_rounded, 'Leaving between ${windowLabel(group.windowStart, group.windowEnd)}'),
              if (group.meetingPoint != null && group.meetingPoint!.isNotEmpty)
                _row(Icons.place_rounded, group.meetingPoint!),
              if (group.isWomenOnly) _row(Icons.shield_outlined, 'Women only'),
            ],
          ),
        ),
        const SizedBox(height: 16),

        // A fare the host actually saw beats our seeded estimate every time, so
        // it takes the slot and the estimate steps aside rather than both
        // competing for the traveller's attention with different numbers.
        if (group.hasQuotedFare) ...<Widget>[
          AppCard(
            child: Row(
              children: <Widget>[
                Icon(Icons.payments_rounded, color: scheme.primary),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        '${rupees(group.fareShare ?? group.quotedFare!)} each',
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                      ),
                      Text(
                        '${rupees(group.quotedFare!)} total'
                        '${group.cabServiceLabel == null ? '' : ' on ${group.cabServiceLabel}'}'
                        ' · quoted by the host',
                        style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Hash Buddy does not book the cab — one of you books it and the rest '
            'settle up directly.',
            style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
          ),
          const SizedBox(height: 20),
        ] else if (group.fareEstimate != null) ...<Widget>[
          FareSummary(fare: group.fareEstimate!),
          const SizedBox(height: 8),
          Text(
            'An estimate for planning, not a quote. Hash Buddy does not book the '
            'cab — one of you books it and the rest settle up directly.',
            style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
          ),
          const SizedBox(height: 20),
        ],

        const Text('Travelling together', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
        const SizedBox(height: 12),
        for (final RideGroupMember member in group.members) ...<Widget>[
          AppCard(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            child: Row(
              children: <Widget>[
                TravellerAvatar(user: member.user),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Row(
                        children: <Widget>[
                          Text(
                            member.user.id == myId ? 'You' : member.user.name,
                            style: const TextStyle(fontWeight: FontWeight.w600),
                          ),
                          if (member.isHost) ...<Widget>[
                            const SizedBox(width: 8),
                            const InfoChip(label: 'Host'),
                          ],
                        ],
                      ),
                      if (member.user.hasRating)
                        Text(
                          '${member.user.ratingAvg.toStringAsFixed(1)}★ · ${member.user.ridesCompleted} rides',
                          style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
                        )
                      else
                        Text(
                          'New to Hash Buddy',
                          style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
                        ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 10),
        ],

        const SizedBox(height: 16),

        // The host opened the ride and books the cab, so ending it is theirs.
        // Everyone else can only take their own seat back.
        if (group.isHost && !group.isCancelled) ...<Widget>[
          FilledButton.icon(
            onPressed: _busy ? null : _complete,
            icon: const Icon(Icons.check_circle_outline_rounded),
            label: const Text('Mark ride completed'),
          ),
          const SizedBox(height: 8),
          Text(
            group.seatsAvailable > 0
                ? 'You do not have to wait for the last seat. Completing now '
                    'splits the fare between the ${group.seatsTaken} of you travelling.'
                : 'Closes the ride and settles the split between everyone aboard.',
            style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
          ),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: _busy ? null : _cancel,
            style: OutlinedButton.styleFrom(foregroundColor: scheme.error),
            icon: const Icon(Icons.cancel_outlined),
            label: const Text('Close this ride'),
          ),
        ] else if (group.isMember && !group.isCancelled)
          OutlinedButton(
            onPressed: _busy ? null : _leave,
            style: OutlinedButton.styleFrom(foregroundColor: scheme.error),
            child: const Text('Leave this ride'),
          ),
      ],
    );
  }

  Widget _row(IconData icon, String text) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        children: <Widget>[
          Icon(icon, size: 16, color: scheme.onSurfaceVariant),
          const SizedBox(width: 8),
          Expanded(
            child: Text(text, style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant)),
          ),
        ],
      ),
    );
  }
}
