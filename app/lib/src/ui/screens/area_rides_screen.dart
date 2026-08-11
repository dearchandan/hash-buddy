import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../models/open_ride.dart';
import '../../models/ride_group.dart';
import '../../state/rides_controller.dart';
import '../formatters.dart';
import '../widgets/app_card.dart';
import '../widgets/counter_field.dart';
import '../widgets/info_chip.dart';
import '../widgets/traveller_avatar.dart';
import 'group_screen.dart';

/// Every ride heading to one area that still has a seat.
///
/// The alternative to this screen is describing your trip and hoping the
/// matcher finds something. Here you can see what already exists.
class AreaRidesScreen extends StatefulWidget {
  const AreaRidesScreen({required this.area, super.key});

  final Area area;

  @override
  State<AreaRidesScreen> createState() => _AreaRidesScreenState();
}

class _AreaRidesScreenState extends State<AreaRidesScreen> {
  List<OpenRide>? _rides;
  Object? _error;
  String? _terminal;
  bool _joining = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    setState(() => _error = null);

    try {
      final List<OpenRide> rides = await context
          .read<RidesController>()
          .openRidesIn(widget.area.zone.id, terminal: _terminal);

      if (mounted) {
        setState(() => _rides = rides);
      }
    } catch (error) {
      if (mounted) {
        setState(() => _error = error);
      }
    }
  }

  Future<void> _join(OpenRide ride) async {
    final _JoinChoice? choice = await showModalBottomSheet<_JoinChoice>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _QuickJoinSheet(ride: ride),
    );

    if (choice == null || !mounted) {
      return;
    }

    setState(() => _joining = true);

    try {
      final RideGroup group = await context.read<RidesController>().quickJoin(
            groupId: ride.id,
            seats: choice.seats,
            luggageCount: choice.luggage,
          );

      if (!mounted) {
        return;
      }

      await Navigator.of(context).pushReplacement(
        MaterialPageRoute<void>(builder: (_) => GroupScreen(groupId: group.id)),
      );
    } catch (error) {
      if (mounted) {
        showError(context, error);
        setState(() => _joining = false);
        // Someone may have taken the last seat while the sheet was open.
        await _load();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.area.zone.name),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(52),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
            child: Row(
              children: <Widget>[
                for (final String? option in <String?>[null, 'T1', 'T2'])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(option ?? 'All terminals'),
                      selected: _terminal == option,
                      onSelected: (_) {
                        setState(() {
                          _terminal = option;
                          _rides = null;
                        });
                        _load();
                      },
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: _buildBody(scheme),
      ),
    );
  }

  Widget _buildBody(ColorScheme scheme) {
    if (_error != null) {
      return _Centred(
        icon: Icons.wifi_off_rounded,
        title: 'Could not load rides',
        body: errorMessage(_error!),
        action: FilledButton.tonal(onPressed: _load, child: const Text('Try again')),
      );
    }

    if (_rides == null) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_rides!.isEmpty) {
      return _Centred(
        icon: Icons.directions_car_outlined,
        title: 'No rides here right now',
        body: _terminal == null
            ? 'Nobody has opened a ride to ${widget.area.zone.name} yet. Open one '
                'and travellers heading this way can join you.'
            : 'Nothing from $_terminal. Try all terminals.',
        action: const SizedBox.shrink(),
      );
    }

    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
      itemCount: _rides!.length,
      separatorBuilder: (_, __) => const SizedBox(height: 12),
      itemBuilder: (BuildContext context, int index) => _RideCard(
        ride: _rides![index],
        busy: _joining,
        onJoin: () => _join(_rides![index]),
      ),
    );
  }
}

class _RideCard extends StatelessWidget {
  const _RideCard({required this.ride, required this.busy, required this.onJoin});

  final OpenRide ride;
  final bool busy;
  final VoidCallback onJoin;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              if (ride.members.isNotEmpty)
                TravellerAvatar(user: ride.members.first.user)
              else
                CircleAvatar(
                  backgroundColor: scheme.secondaryContainer,
                  child: Icon(Icons.person_rounded, size: 20, color: scheme.onSecondaryContainer),
                ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      ride.members.map((RideGroupMember m) => m.user.name).join(', '),
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 2),
                    Text(
                      '${dayLabel(ride.windowStart)} · '
                      '${windowLabel(ride.windowStart, ride.windowEnd)}',
                      style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant),
                    ),
                  ],
                ),
              ),
              InfoChip(
                label: '${ride.seatsAvailable} seat${ride.seatsAvailable == 1 ? '' : 's'} free',
                tone: ChipTone.positive,
              ),
            ],
          ),
          const SizedBox(height: 12),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: <Widget>[
              InfoChip(label: ride.terminal, icon: Icons.flight_land_rounded),
              InfoChip(
                label: '${ride.seatsTaken} of ${ride.maxSeats} aboard',
                icon: Icons.event_seat_rounded,
              ),
              if (ride.cabServiceLabel != null)
                InfoChip(label: ride.cabServiceLabel!, icon: Icons.local_taxi_rounded),
              if (ride.isWomenOnly)
                const InfoChip(label: 'Women only', icon: Icons.shield_outlined),
            ],
          ),
          if (ride.meetingPoint != null && ride.meetingPoint!.isNotEmpty) ...<Widget>[
            const SizedBox(height: 10),
            Row(
              children: <Widget>[
                Icon(Icons.place_rounded, size: 16, color: scheme.onSurfaceVariant),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    ride.meetingPoint!,
                    style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant),
                  ),
                ),
              ],
            ),
          ],
          const SizedBox(height: 12),
          _FareLine(ride: ride),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: busy ? null : onJoin,
            child: const Text('Join this ride'),
          ),
        ],
      ),
    );
  }
}

/// The fare, or an honest admission that there isn't one.
///
/// The seeded zone estimate is deliberately not shown here. Sitting in the slot
/// where a real Ola quote goes, a guess would be read as one.
class _FareLine extends StatelessWidget {
  const _FareLine({required this.ride});

  final OpenRide ride;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    if (!ride.hasFare) {
      return Row(
        children: <Widget>[
          Icon(Icons.help_outline_rounded, size: 16, color: scheme.onSurfaceVariant),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              'Fare not shared yet — agree it between you.',
              style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant),
            ),
          ),
        ],
      );
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: scheme.secondaryContainer,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        children: <Widget>[
          Icon(Icons.payments_rounded, size: 18, color: scheme.onSecondaryContainer),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  '${rupees(ride.fareShare!)} each',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    color: scheme.onSecondaryContainer,
                  ),
                ),
                Text(
                  '${rupees(ride.quotedFare!)} total'
                  '${ride.cabServiceLabel == null ? '' : ' on ${ride.cabServiceLabel}'}'
                  '${ride.seatsAvailable > 1 ? ' · less if more join' : ''}',
                  style: TextStyle(fontSize: 12, color: scheme.onSecondaryContainer),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _JoinChoice {
  const _JoinChoice(this.seats, this.luggage);

  final int seats;
  final int luggage;
}

/// The only thing a browsing traveller still has to say: how many of you, and
/// how much are you carrying. Everything else is already on the ride.
class _QuickJoinSheet extends StatefulWidget {
  const _QuickJoinSheet({required this.ride});

  final OpenRide ride;

  @override
  State<_QuickJoinSheet> createState() => _QuickJoinSheetState();
}

class _QuickJoinSheetState extends State<_QuickJoinSheet> {
  int _seats = 1;
  int _luggage = 1;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;
    final int? share = widget.ride.quotedFare == null
        ? null
        : (widget.ride.quotedFare! / (widget.ride.seatsTaken + _seats)).ceil();

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          20,
          20,
          20 + MediaQuery.viewInsetsOf(context).bottom,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Text(
              'Join ${widget.ride.hostName}’s ride',
              style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 4),
            Text(
              '${widget.ride.zone?.name ?? 'This area'} · ${widget.ride.terminal} · '
              '${windowLabel(widget.ride.windowStart, widget.ride.windowEnd)}',
              style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant),
            ),
            const SizedBox(height: 20),
            CounterField(
              label: 'People travelling',
              value: _seats,
              min: 1,
              max: widget.ride.seatsAvailable,
              onChanged: (int value) => setState(() => _seats = value),
            ),
            const SizedBox(height: 12),
            CounterField(
              label: 'Suitcases',
              value: _luggage,
              min: 0,
              max: 6,
              onChanged: (int value) => setState(() => _luggage = value),
            ),
            if (share != null) ...<Widget>[
              const SizedBox(height: 16),
              Text(
                'About ${rupees(share)} each at ${widget.ride.seatsTaken + _seats} sharing.',
                style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant),
              ),
            ],
            const SizedBox(height: 20),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () => Navigator.of(context).pop(_JoinChoice(_seats, _luggage)),
                child: const Text('Confirm and join'),
              ),
            ),
          ],
        ),
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

    // Inside a scroll view so pull-to-refresh still works on an empty list.
    return ListView(
      children: <Widget>[
        Padding(
          padding: const EdgeInsets.fromLTRB(32, 96, 32, 32),
          child: Column(
            children: <Widget>[
              Icon(icon, size: 56, color: scheme.outline),
              const SizedBox(height: 16),
              Text(title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              Text(
                body,
                textAlign: TextAlign.center,
                style: TextStyle(color: scheme.onSurfaceVariant),
              ),
              const SizedBox(height: 24),
              action,
            ],
          ),
        ),
      ],
    );
  }
}
