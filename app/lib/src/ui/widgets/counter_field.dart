import 'package:flutter/material.dart';

/// A labelled −/value/+ stepper, used for party size and suitcase count.
class CounterField extends StatelessWidget {
  const CounterField({
    required this.label,
    required this.value,
    required this.onChanged,
    this.min = 0,
    this.max = 9,
    super.key,
  });

  final String label;
  final int value;
  final ValueChanged<int> onChanged;
  final int min;
  final int max;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 10),
        Row(
          children: <Widget>[
            IconButton.filledTonal(
              onPressed: value > min ? () => onChanged(value - 1) : null,
              icon: const Icon(Icons.remove_rounded),
              tooltip: 'Fewer',
            ),
            SizedBox(
              width: 64,
              child: Text(
                '$value',
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
              ),
            ),
            IconButton.filledTonal(
              onPressed: value < max ? () => onChanged(value + 1) : null,
              icon: const Icon(Icons.add_rounded),
              tooltip: 'More',
            ),
          ],
        ),
      ],
    );
  }
}
