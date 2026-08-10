import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/ride_group.dart';
import '../../models/ride_request.dart';
import '../../state/auth_controller.dart';
import '../../state/rides_controller.dart';
import '../formatters.dart';
import '../widgets/app_card.dart';
import '../widgets/info_chip.dart';
import 'create_request_screen.dart';
import 'group_screen.dart';
import 'matches_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _refresh());
  }

  Future<void> _refresh() async {
    try {
      await context.read<RidesController>().refreshHome();
    } catch (error) {
      if (mounted) {
        showError(context, error);
      }
    }
  }

  Future<void> _newRequest() async {
    final RideRequest? created = await Navigator.of(context).push<RideRequest>(
      MaterialPageRoute<RideRequest>(builder: (_) => const CreateRequestScreen()),
    );

    if (created != null && mounted) {
      await Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => MatchesScreen(rideRequest: created)),
      );
      await _refresh();
    }
  }

  @override
  Widget build(BuildContext context) {
    final RidesController rides = context.watch<RidesController>();
    final AuthController auth = context.watch<AuthController>();
    final List<RideRequest> open = rides.openRequests;
    final List<RideGroup> groups = rides.myGroups;
    final bool empty = open.isEmpty && groups.isEmpty;

    return Scaffold(
      appBar: AppBar(
        title: Text('Hi, ${auth.user?.name ?? 'there'}'),
        actions: <Widget>[
          IconButton(
            tooltip: 'Sign out',
            icon: const Icon(Icons.logout_rounded),
            onPressed: () => context.read<AuthController>().signOut(),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _newRequest,
        icon: const Icon(Icons.add_rounded),
        label: const Text('New ride'),
      ),
      body: RefreshIndicator(
        onRefresh: _refresh,
        child: rides.loading && empty
            ? const Center(child: CircularProgressIndicator())
            : ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
                children: <Widget>[
                  if (empty) const _EmptyState(),
                  if (groups.isNotEmpty) ...<Widget>[
                    const _SectionHeader('Your rides'),
                    for (final RideGroup group in groups) ...<Widget>[
                      _GroupTile(group: group, onChanged: _refresh),
                      const SizedBox(height: 12),
                    ],
                    const SizedBox(height: 12),
                  ],
                  if (open.isNotEmpty) ...<Widget>[
                    const _SectionHeader('Looking for mates'),
                    for (final RideRequest request in open) ...<Widget>[
                      _RequestTile(request: request, onChanged: _refresh),
                      const SizedBox(height: 12),
                    ],
                  ],
                ],
              ),
      ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader(this.title);

  final String title;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 8, bottom: 12),
      child: Text(
        title,
        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
      ),
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState();

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: const EdgeInsets.only(top: 80),
      child: Column(
        children: <Widget>[
          Icon(Icons.luggage_rounded, size: 56, color: scheme.outline),
          const SizedBox(height: 16),
          const Text(
            'No rides yet',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 32),
            child: Text(
              'Tell us when you land and where you are headed, and we will look for someone going the same way.',
              textAlign: TextAlign.center,
              style: TextStyle(color: scheme.onSurfaceVariant),
            ),
          ),
        ],
      ),
    );
  }
}

class _GroupTile extends StatelessWidget {
  const _GroupTile({required this.group, required this.onChanged});

  final RideGroup group;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return AppCard(
      borderColor: scheme.primary.withValues(alpha: 0.4),
      onTap: () async {
        await Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => GroupScreen(groupId: group.id)),
        );
        await onChanged();
      },
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  group.zone?.name ?? 'Your ride',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                ),
              ),
              InfoChip(
                label: group.isLocked ? 'Full' : '${group.seatsAvailable} seat left',
                tone: group.isLocked ? ChipTone.positive : ChipTone.caution,
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            '${dayLabel(group.windowStart)} · ${windowLabel(group.windowStart, group.windowEnd)} · ${group.terminal}',
            style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 13),
          ),
          const SizedBox(height: 10),
          Row(
            children: <Widget>[
              Icon(Icons.group_rounded, size: 16, color: scheme.onSurfaceVariant),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  group.members.map((RideGroupMember m) => m.user.name).join(', '),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 13),
                ),
              ),
              if (group.fareEstimate != null)
                Text(
                  '${rupees(group.fareEstimate!.perHeadFare)} each',
                  style: const TextStyle(fontWeight: FontWeight.w700),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _RequestTile extends StatelessWidget {
  const _RequestTile({required this.request, required this.onChanged});

  final RideRequest request;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return AppCard(
      onTap: () async {
        await Navigator.of(context).push(
          MaterialPageRoute<void>(builder: (_) => MatchesScreen(rideRequest: request)),
        );
        await onChanged();
      },
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Expanded(
                child: Text(
                  request.zone?.name ?? 'Ride request',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                ),
              ),
              if (request.isWomenOnly) const InfoChip(label: 'Women only', icon: Icons.shield_outlined),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            '${dayLabel(request.windowStart)} · ${windowLabel(request.windowStart, request.windowEnd)} · ${request.terminal}',
            style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 13),
          ),
          const SizedBox(height: 12),
          Row(
            children: <Widget>[
              Icon(Icons.search_rounded, size: 16, color: scheme.primary),
              const SizedBox(width: 6),
              Text(
                'Find mates',
                style: TextStyle(color: scheme.primary, fontWeight: FontWeight.w600, fontSize: 13),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
