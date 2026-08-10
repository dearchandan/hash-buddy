import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../config.dart';
import '../../models/ride_request.dart';
import '../../models/zone.dart';
import '../../state/auth_controller.dart';
import '../../state/rides_controller.dart';
import '../formatters.dart';

class CreateRequestScreen extends StatefulWidget {
  const CreateRequestScreen({super.key});

  @override
  State<CreateRequestScreen> createState() => _CreateRequestScreenState();
}

class _CreateRequestScreenState extends State<CreateRequestScreen> {
  final TextEditingController _flight = TextEditingController();
  final TextEditingController _landmark = TextEditingController();

  String _terminal = 'T2';
  Zone? _zone;
  DateTime _departFrom = _nextHalfHour();
  int _windowMinutes = 45;
  int _luggage = 1;
  bool _womenOnly = false;
  bool _saving = false;

  /// How wide a departure window the traveller is willing to wait out. Wider
  /// windows match far more often, which is why 45 minutes is the default.
  static const List<int> _windowOptions = <int>[30, 45, 60, 90];

  static DateTime _nextHalfHour() {
    final DateTime now = DateTime.now().add(const Duration(minutes: 30));
    return DateTime(now.year, now.month, now.day, now.hour, now.minute >= 30 ? 30 : 0);
  }

  DateTime get _departUntil => _departFrom.add(Duration(minutes: _windowMinutes));

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadZones());
  }

  @override
  void dispose() {
    _flight.dispose();
    _landmark.dispose();
    super.dispose();
  }

  Future<void> _loadZones() async {
    try {
      await context.read<RidesController>().loadZones();
      if (mounted) {
        setState(() {});
      }
    } catch (error) {
      if (mounted) {
        showError(context, error);
      }
    }
  }

  Future<void> _pickDeparture() async {
    final DateTime now = DateTime.now();
    final DateTime? date = await showDatePicker(
      context: context,
      initialDate: _departFrom,
      firstDate: DateTime(now.year, now.month, now.day),
      lastDate: now.add(const Duration(days: 30)),
    );
    if (date == null || !mounted) {
      return;
    }

    final TimeOfDay? time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(_departFrom),
    );
    if (time == null || !mounted) {
      return;
    }

    setState(() {
      _departFrom = DateTime(date.year, date.month, date.day, time.hour, time.minute);
    });
  }

  Future<void> _submit() async {
    if (_zone == null) {
      showMessage(context, 'Where are you heading?');
      return;
    }
    if (_departUntil.isBefore(DateTime.now())) {
      showMessage(context, 'That departure window has already passed.');
      return;
    }

    setState(() => _saving = true);
    try {
      final RideRequest created = await context.read<RidesController>().createRequest(
            terminal: _terminal,
            zoneId: _zone!.id,
            windowStart: _departFrom,
            windowEnd: _departUntil,
            luggageCount: _luggage,
            genderPreference: _womenOnly ? 'women_only' : 'any',
            flightNumber: _flight.text.trim(),
            dropLandmark: _landmark.text.trim(),
          );

      if (mounted) {
        Navigator.of(context).pop(created);
      }
    } catch (error) {
      if (mounted) {
        showError(context, error);
        setState(() => _saving = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;
    final List<Zone> zones = context.watch<RidesController>().zones;
    final bool isWoman = context.watch<AuthController>().user?.gender == 'female';

    return Scaffold(
      appBar: AppBar(title: const Text('New ride request')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: <Widget>[
          _label('Terminal'),
          SegmentedButton<String>(
            segments: AppConfig.terminals
                .map((String t) => ButtonSegment<String>(value: t, label: Text(t)))
                .toList(),
            selected: <String>{_terminal},
            onSelectionChanged: (Set<String> value) => setState(() => _terminal = value.first),
          ),
          const SizedBox(height: 20),

          _label('Heading to'),
          InputDecorator(
            decoration: const InputDecoration(contentPadding: EdgeInsets.symmetric(horizontal: 12)),
            child: DropdownButtonHideUnderline(
              child: DropdownButton<Zone>(
                value: _zone,
                isExpanded: true,
                hint: const Text('Pick your drop zone'),
                items: zones
                    .map((Zone zone) => DropdownMenuItem<Zone>(value: zone, child: Text(zone.name)))
                    .toList(),
                onChanged: (Zone? value) => setState(() => _zone = value),
              ),
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _landmark,
            textCapitalization: TextCapitalization.words,
            decoration: const InputDecoration(
              labelText: 'Landmark (optional)',
              hintText: 'Forum Mall',
            ),
          ),
          const SizedBox(height: 20),

          _label('Leaving the airport'),
          InkWell(
            onTap: _pickDeparture,
            borderRadius: BorderRadius.circular(12),
            child: InputDecorator(
              decoration: const InputDecoration(),
              child: Row(
                children: <Widget>[
                  Icon(Icons.schedule_rounded, size: 18, color: scheme.onSurfaceVariant),
                  const SizedBox(width: 10),
                  Text(
                    '${dayLabel(_departFrom)}, ${clockTime(_departFrom)}',
                    style: const TextStyle(fontSize: 16),
                  ),
                  const Spacer(),
                  Icon(Icons.edit_rounded, size: 16, color: scheme.onSurfaceVariant),
                ],
              ),
            ),
          ),
          const SizedBox(height: 12),
          Text(
            'How long can you wait?',
            style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant),
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            children: _windowOptions.map((int minutes) {
              return ChoiceChip(
                label: Text('$minutes min'),
                selected: _windowMinutes == minutes,
                onSelected: (_) => setState(() => _windowMinutes = minutes),
              );
            }).toList(),
          ),
          const SizedBox(height: 8),
          Text(
            'Matching until ${clockTime(_departUntil)}. A wider window finds mates far more often.',
            style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
          ),
          const SizedBox(height: 20),

          _label('Flight number (optional)'),
          TextField(
            controller: _flight,
            textCapitalization: TextCapitalization.characters,
            inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(10)],
            decoration: const InputDecoration(hintText: 'AI2846'),
          ),
          const SizedBox(height: 6),
          Text(
            'Travellers on your flight land exactly when you do, so we rank them higher.',
            style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
          ),
          const SizedBox(height: 20),

          _label('Luggage'),
          Row(
            children: <Widget>[
              IconButton.filledTonal(
                onPressed: _luggage > 0 ? () => setState(() => _luggage--) : null,
                icon: const Icon(Icons.remove_rounded),
              ),
              SizedBox(
                width: 56,
                child: Text(
                  '$_luggage',
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                ),
              ),
              IconButton.filledTonal(
                onPressed: _luggage < 6 ? () => setState(() => _luggage++) : null,
                icon: const Icon(Icons.add_rounded),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text(
                  'Bags decide whether your group fits a sedan or needs an SUV.',
                  style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
                ),
              ),
            ],
          ),

          if (isWoman) ...<Widget>[
            const SizedBox(height: 12),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              value: _womenOnly,
              onChanged: (bool value) => setState(() => _womenOnly = value),
              title: const Text('Travel with women only'),
              subtitle: Text(
                'Filters on what other travellers entered themselves. We do not verify it.',
                style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
              ),
            ),
          ],

          const SizedBox(height: 28),
          FilledButton(
            onPressed: _saving ? null : _submit,
            child: _saving
                ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('Find mates'),
          ),
        ],
      ),
    );
  }

  Widget _label(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(text, style: const TextStyle(fontWeight: FontWeight.w600)),
      );
}
