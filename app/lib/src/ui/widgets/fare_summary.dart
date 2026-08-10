import 'package:flutter/material.dart';

import '../../models/fare_estimate.dart';
import '../formatters.dart';

/// Shows what sharing actually costs, including the vehicle class the group
/// needs. Three people with airport luggage do not fit a sedan, so the
/// per-head figure is not simply a solo fare divided by three.
class FareSummary extends StatelessWidget {
  const FareSummary({required this.fare, super.key});

  final FareEstimate fare;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;
    final bool saves = fare.savingsPerHead > 0;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        children: <Widget>[
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Text(
                '${rupees(fare.perHeadFare)} each',
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 2),
              Text(
                '${fare.vehicleLabel} · ${rupees(fare.totalFare)} total',
                style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
              ),
            ],
          ),
          const Spacer(),
          if (saves)
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: <Widget>[
                Text(
                  'Save ${fare.savingsPercent}%',
                  style: TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w700,
                    color: scheme.primary,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  'alone: ${rupees(fare.soloFare)}',
                  style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
