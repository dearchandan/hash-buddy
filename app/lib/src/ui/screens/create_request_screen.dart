import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../../config.dart';
import '../../models/ride_request.dart';
import '../../models/zone.dart';
import '../../state/auth_controller.dart';
import '../../state/rides_controller.dart';
import '../formatters.dart';
import '../widgets/counter_field.dart';

class CreateRequestScreen extends StatefulWidget {
  const CreateRequestScreen({super.key});

  @override
  State<CreateRequestScreen> createState() => _CreateRequestScreenState();
}

class _CreateRequestScreenState extends State<CreateRequestScreen> {
  final TextEditingController _flight = TextEditingController();
  final TextEditingController _landmark = TextEditingController();
  final TextEditingController _fare = TextEditingController();
  final TextEditingController _spot = TextEditingController();

  /// Null means "not decided", which is the honest state for someone who
  /// opened a ride the moment they landed.
  String? _cabService;

  String _terminal = 'T1';
  Zone? _zone;
  DateTime _departFrom = _nextHalfHour();
  int _windowMinutes = 30;
  int _travellers = 1;
  int _luggage = 1;
  bool _womenOnly = false;
  bool _saving = false;

  /// How long a traveller is willing to wait. Wider windows match far more
  /// often, which is why the copy under the chips says so.
  static const List<int> _windowOptions = <int>[30, 45, 60, 90];

  /// Matches `hashbuddy.groups.absolute_max_seats` on the API.
  static const int _maxTravellers = 4;

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
    _fare.dispose();
    _spot.dispose();
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
      showMessage(context, 'Where are you going?');

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
            seats: _travellers,
            luggageCount: _luggage,
            genderPreference: _womenOnly ? 'women_only' : 'any',
            flightNumber: _flight.text.trim(),
            dropLandmark: _landmark.text.trim(),
            quotedFare: int.tryParse(_fare.text.trim()),
            cabService: _cabService,
            meetingPoint: _spot.text.trim(),
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
      appBar: AppBar(title: const Text('Find mates')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
        children: <Widget>[
          _section('PICKUP'),
          _label('Terminal'),
          SegmentedButton<String>(
            segments: AppConfig.terminals
                .map((String t) => ButtonSegment<String>(value: t, label: Text(t)))
                .toList(),
            selected: <String>{_terminal},
            onSelectionChanged: (Set<String> value) => setState(() => _terminal = value.first),
          ),

          _section('DESTINATION'),
          _label('Where are you going?'),
          InputDecorator(
            decoration: const InputDecoration(
              contentPadding: EdgeInsets.symmetric(horizontal: 12),
            ),
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
          const SizedBox(height: 16),
          _label('Landmark (optional)'),
          TextField(
            controller: _landmark,
            textCapitalization: TextCapitalization.words,
            decoration: const InputDecoration(hintText: 'e.g. Sony Signal'),
          ),

          _section('WHEN'),
          _label('When will you leave?'),
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
          const SizedBox(height: 16),
          _label('How long can you wait?'),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: _windowOptions.map((int minutes) {
              return ChoiceChip(
                label: Text('$minutes min'),
                selected: _windowMinutes == minutes,
                onSelected: (_) => setState(() => _windowMinutes = minutes),
              );
            }).toList(),
          ),
          const SizedBox(height: 12),
          Text(
            'Matching until ${clockTime(_departUntil)}.\nA wider window finds more people.',
            style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant, height: 1.4),
          ),

          _section('TRAVELLERS'),
          CounterField(
            label: 'People travelling',
            value: _travellers,
            min: 1,
            max: _maxTravellers,
            onChanged: (int value) => setState(() => _travellers = value),
          ),

          if (isWoman) ...<Widget>[
            _section('PREFERENCES'),
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

          _section('LUGGAGE'),
          CounterField(
            label: 'Suitcases',
            value: _luggage,
            max: 6,
            onChanged: (int value) => setState(() => _luggage = value),
          ),

          _section('FLIGHT'),
          _label('Flight number (optional)'),
          TextField(
            controller: _flight,
            textCapitalization: TextCapitalization.characters,
            inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(10)],
            decoration: const InputDecoration(hintText: 'AI2846'),
          ),
          const SizedBox(height: 12),
          Text(
            "We'll prioritize passengers on the same flight.",
            style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant, height: 1.4),
          ),

          // Everything below is optional, and the copy says so plainly. Someone
          // who checked Ola before opening the app has a real number worth
          // sharing; someone who just walked out of arrivals does not, and a
          // required field would only collect an invented one.
          _section('FARE & PICKUP  ·  OPTIONAL'),
          _label('Fare you were quoted'),
          TextField(
            controller: _fare,
            keyboardType: TextInputType.number,
            inputFormatters: <TextInputFormatter>[
              FilteringTextInputFormatter.digitsOnly,
              LengthLimitingTextInputFormatter(6),
            ],
            decoration: const InputDecoration(prefixText: '₹ ', hintText: '1200'),
          ),
          const SizedBox(height: 8),
          Text(
            'If you already checked Ola or Uber, share it and whoever joins can '
            'see their share up front. Skip it and you can agree later.',
            style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant, height: 1.4),
          ),

          const SizedBox(height: 20),
          _label('Cab service'),
          Wrap(
            spacing: 8,
            children: <Widget>[
              for (final MapEntry<String?, String> option in const <String?, String>{
                null: 'Not decided',
                'ola': 'Ola',
                'uber': 'Uber',
                'rapido': 'Rapido',
              }.entries)
                ChoiceChip(
                  label: Text(option.value),
                  selected: _cabService == option.key,
                  onSelected: (_) => setState(() => _cabService = option.key),
                ),
            ],
          ),

          const SizedBox(height: 20),
          _label('Where will you wait?'),
          TextField(
            controller: _spot,
            textCapitalization: TextCapitalization.sentences,
            inputFormatters: <TextInputFormatter>[LengthLimitingTextInputFormatter(120)],
            decoration: const InputDecoration(hintText: 'Gate 4, taxi bay 2'),
          ),
          const SizedBox(height: 8),
          Text(
            'Helps whoever joins find you at the kerb.',
            style: TextStyle(fontSize: 13, color: scheme.onSurfaceVariant, height: 1.4),
          ),

          const SizedBox(height: 32),
          FilledButton(
            onPressed: _saving ? null : _submit,
            child: _saving
                ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                : const Text('Find co-passengers'),
          ),
        ],
      ),
    );
  }

  /// A small-caps group heading, e.g. PICKUP.
  Widget _section(String title) => Padding(
        padding: const EdgeInsets.only(top: 28, bottom: 14),
        child: Text(
          title,
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            letterSpacing: 1.3,
            color: Theme.of(context).colorScheme.primary,
          ),
        ),
      );

  Widget _label(String text) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(text, style: const TextStyle(fontWeight: FontWeight.w600)),
      );
}
