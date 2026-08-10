import 'package:flutter/material.dart';

class InfoChip extends StatelessWidget {
  const InfoChip({
    required this.label,
    this.icon,
    this.tone = ChipTone.neutral,
    super.key,
  });

  final String label;
  final IconData? icon;
  final ChipTone tone;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    final (Color background, Color foreground) = switch (tone) {
      ChipTone.neutral => (scheme.surfaceContainerHighest, scheme.onSurfaceVariant),
      ChipTone.positive => (scheme.primaryContainer, scheme.onPrimaryContainer),
      ChipTone.caution => (scheme.tertiaryContainer, scheme.onTertiaryContainer),
    };

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          if (icon != null) ...<Widget>[
            Icon(icon, size: 14, color: foreground),
            const SizedBox(width: 5),
          ],
          Text(
            label,
            style: TextStyle(color: foreground, fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}

enum ChipTone { neutral, positive, caution }
