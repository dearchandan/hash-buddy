import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/match_candidate.dart';
import '../../models/ride_group.dart';
import '../../models/ride_request.dart';
import '../../state/rides_controller.dart';
import '../formatters.dart';
import '../widgets/app_card.dart';
import '../widgets/fare_summary.dart';
import '../widgets/info_chip.dart';
import '../widgets/traveller_avatar.dart';
import 'group_screen.dart';

/// Find mates: everyone heading your way in your departure window.
class MatchesScreen extends StatefulWidget {
  const MatchesScreen({required this.rideRequest, super.key});

  final RideRequest rideRequest;

  @override
  State<MatchesScreen> createState() => _MatchesScreenState();
}

class _MatchesScreenState extends State<MatchesScreen> {
  List<MatchCandidate>? _matches;
  Object? _error;
  bool _acting = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    setState(() {
      _error = null;
    });

    try {
      final List<MatchCandidate> matches =
          await context.read<RidesController>().findMatches(widget.rideRequest.id);
      if (mounted) {
        setState(() => _matches = matches);
      }
    } catch (error) {
      if (mounted) {
        setState(() => _error = error);
      }
    }
  }

  Future<void> _run(Future<RideGroup> Function() action, String successMessage) async {
    setState(() => _acting = true);
    try {
      final RideGroup group = await action();
      if (!mounted) {
        return;
      }
      showMessage(context, successMessage);
      await Navigator.of(context).pushReplacement(
        MaterialPageRoute<void>(builder: (_) => GroupScreen(groupId: group.id)),
      );
    } catch (error) {
      if (mounted) {
        showError(context, error);
        setState(() => _acting = false);
        // The board moved under us — someone took the seat, or a window shifted.
        await _load();
      }
    }
  }

  Future<void> _join(RideGroup group) => _run(
        () => context.read<RidesController>().joinGroup(
              groupId: group.id,
              rideRequestId: widget.rideRequest.id,
            ),
        'You are on this ride.',
      );

  /// A lone traveller has no group to join, so we open one and they can take
  /// the seat from their own matches list.
  Future<void> _openRide() => _run(
        () => context.read<RidesController>().createGroup(rideRequestId: widget.rideRequest.id),
        'Ride opened. Travellers matching you can now join.',
      );

  Future<void> _autoMatch() async {
    setState(() => _acting = true);
    try {
      final ({RideGroup group, bool joined}) result =
          await context.read<RidesController>().autoMatch(widget.rideRequest.id);
      if (!mounted) {
        return;
      }
      showMessage(
        context,
        result.joined ? 'You are on this ride.' : 'Ride opened. We will seat the next match with you.',
      );
      await Navigator.of(context).pushReplacement(
        MaterialPageRoute<void>(builder: (_) => GroupScreen(groupId: result.group.id)),
      );
    } catch (error) {
      if (mounted) {
        showError(context, error);
        setState(() => _acting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final RideRequest request = widget.rideRequest;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Ride mates'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(38),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                '${request.zone?.name ?? 'Your zone'} · ${request.terminal} · '
                '${dayLabel(request.windowStart)} ${windowLabel(request.windowStart, request.windowEnd)}',
                style: TextStyle(fontSize: 13, color: Theme.of(context).colorScheme.onSurfaceVariant),
              ),
            ),
          ),
        ),
      ),
      bottomNavigationBar: SafeArea(
        minimum: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: FilledButton.tonal(
          onPressed: _acting ? null : _autoMatch,
          child: const Text('Just find me a ride'),
        ),
      ),
      body: _buildBody(),
    );
  }

  Widget _buildBody() {
    if (_error != null) {
      return _Centred(
        icon: Icons.wifi_off_rounded,
        title: 'Could not load matches',
        body: errorMessage(_error!),
        action: FilledButton.tonal(onPressed: _load, child: const Text('Try again')),
      );
    }

    final List<MatchCandidate>? matches = _matches;
    if (matches == null) {
      return const Center(child: CircularProgressIndicator());
    }

    if (matches.isEmpty) {
      return _Centred(
        icon: Icons.person_search_rounded,
        title: 'Nobody yet',
        body: 'No one is heading your way in this window. Open a ride so the next '
            'traveller who matches can join you, or widen your window.',
        action: FilledButton(onPressed: _acting ? null : _openRide, child: const Text('Open a ride')),
      );
    }

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        itemCount: matches.length,
        separatorBuilder: (_, __) => const SizedBox(height: 12),
        itemBuilder: (BuildContext context, int index) => _MatchCard(
          match: matches[index],
          busy: _acting,
          onJoin: _join,
          onOpenRide: _openRide,
        ),
      ),
    );
  }
}

class _MatchCard extends StatelessWidget {
  const _MatchCard({
    required this.match,
    required this.busy,
    required this.onJoin,
    required this.onOpenRide,
  });

  final MatchCandidate match;
  final bool busy;
  final Future<void> Function(RideGroup group) onJoin;
  final Future<void> Function() onOpenRide;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;
    final RideGroup? group = match.group;
    final TravellerMatch? traveller = match.traveller;

    final String title = match.isGroup
        ? (group!.members.isEmpty
            ? 'Open ride'
            : '${group.members.first.user.name}’s ride')
        : traveller!.user.name;

    final String subtitle = match.isGroup
        ? '${group!.seatsTaken} aboard · ${group.seatsAvailable} seat${group.seatsAvailable == 1 ? '' : 's'} free'
        : 'Travelling alone · ${traveller!.luggageCount} bag${traveller.luggageCount == 1 ? '' : 's'}';

    final DateTime windowStart = match.isGroup ? group!.windowStart : traveller!.windowStart;
    final DateTime windowEnd = match.isGroup ? group!.windowEnd : traveller!.windowEnd;

    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              if (traveller != null)
                TravellerAvatar(user: traveller.user)
              else
                CircleAvatar(
                  backgroundColor: scheme.secondaryContainer,
                  child: Icon(Icons.group_rounded, color: scheme.onSecondaryContainer, size: 20),
                ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                    const SizedBox(height: 2),
                    Text(subtitle, style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant)),
                  ],
                ),
              ),
              if (traveller?.user.hasRating ?? false)
                Row(
                  children: <Widget>[
                    Icon(Icons.star_rounded, size: 16, color: scheme.tertiary),
                    const SizedBox(width: 2),
                    Text(
                      traveller!.user.ratingAvg.toStringAsFixed(1),
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                    ),
                  ],
                ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: <Widget>[
              InfoChip(
                label: overlapLabel(match.overlapMinutes),
                icon: Icons.schedule_rounded,
                tone: match.overlapMinutes >= 30 ? ChipTone.positive : ChipTone.neutral,
              ),
              if (match.sameFlight)
                const InfoChip(
                  label: 'Same flight',
                  icon: Icons.flight_land_rounded,
                  tone: ChipTone.positive,
                ),
              if (group?.isWomenOnly ?? false)
                const InfoChip(label: 'Women only', icon: Icons.shield_outlined),
              InfoChip(label: windowLabel(windowStart, windowEnd)),
            ],
          ),
          const SizedBox(height: 12),
          FareSummary(fare: match.fareEstimate),
          const SizedBox(height: 12),
          if (match.canJoinDirectly)
            FilledButton(
              onPressed: busy ? null : () => onJoin(group!),
              child: const Text('Join this ride'),
            )
          else ...<Widget>[
            OutlinedButton(
              onPressed: busy ? null : onOpenRide,
              child: const Text('Open a ride they can join'),
            ),
            const SizedBox(height: 6),
            Text(
              '${traveller!.user.name} has not started a ride yet. Open one and they '
              'will see it in their matches.',
              style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
            ),
          ],
        ],
      ),
    );
  }
}

class _Centred extends StatelessWidget {
  const _Centred({
    required this.icon,
    required this.title,
    required this.body,
    required this.action,
  });

  final IconData icon;
  final String title;
  final String body;
  final Widget action;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 32),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Icon(icon, size: 56, color: scheme.outline),
            const SizedBox(height: 16),
            Text(title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            Text(body, textAlign: TextAlign.center, style: TextStyle(color: scheme.onSurfaceVariant)),
            const SizedBox(height: 24),
            action,
          ],
        ),
      ),
    );
  }
}
