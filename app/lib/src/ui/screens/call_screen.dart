import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../models/app_user.dart';
import '../../models/ride_group.dart';
import '../../state/call_controller.dart';
import '../widgets/traveller_avatar.dart';

/// A voice call with a ride mate. Audio is peer-to-peer; neither traveller ever
/// sees the other's phone number.
class CallScreen extends StatelessWidget {
  const CallScreen._({
    required this.group,
    required this.other,
    required this.incomingCallId,
  });

  factory CallScreen.outgoing({required RideGroup group, required AppUser other}) =>
      CallScreen._(group: group, other: other, incomingCallId: null);

  factory CallScreen.incoming({
    required RideGroup group,
    required AppUser other,
    required int callId,
  }) =>
      CallScreen._(group: group, other: other, incomingCallId: callId);

  final RideGroup group;
  final AppUser other;
  final int? incomingCallId;

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider<CallController>(
      create: (BuildContext context) {
        final CallController controller =
            CallController(context.read<ApiClient>(), groupId: group.id);

        // An outgoing call dials immediately; an incoming one waits for the
        // person to actually pick up.
        if (incomingCallId == null) {
          unawaited(controller.dial(other.id));
        }

        return controller;
      },
      child: _CallView(other: other, incomingCallId: incomingCallId),
    );
  }
}

class _CallView extends StatefulWidget {
  const _CallView({required this.other, required this.incomingCallId});

  final AppUser other;
  final int? incomingCallId;

  @override
  State<_CallView> createState() => _CallViewState();
}

class _CallViewState extends State<_CallView> {
  Timer? _tick;

  @override
  void initState() {
    super.initState();
    // Redraws the call duration once a second; nothing else needs it.
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) {
        setState(() {});
      }
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final CallController call = context.watch<CallController>();
    final ColorScheme scheme = Theme.of(context).colorScheme;
    final bool answering = widget.incomingCallId != null && call.phase == CallPhase.preparing;

    // Close automatically once there is nothing left to look at.
    if (call.phase == CallPhase.ended && ModalRoute.of(context)?.isCurrent == true) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) {
          Navigator.of(context).maybePop();
        }
      });
    }

    return PopScope(
      // Leaving the screen must end the call, not orphan it with the microphone
      // still live.
      canPop: false,
      onPopInvokedWithResult: (bool didPop, Object? result) async {
        if (!didPop) {
          await call.hangUp();
          if (context.mounted) {
            Navigator.of(context).pop();
          }
        }
      },
      child: Scaffold(
        backgroundColor: scheme.surfaceContainerHighest,
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              children: <Widget>[
                const Spacer(),
                TravellerAvatar(user: widget.other, radius: 44),
                const SizedBox(height: 20),
                Text(
                  widget.other.name,
                  style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 8),
                Text(
                  _statusLine(call, answering),
                  style: TextStyle(fontSize: 15, color: scheme.onSurfaceVariant),
                ),
                if (!call.relayAvailable && call.phase != CallPhase.ended) ...<Widget>[
                  const SizedBox(height: 16),
                  _RelayWarning(),
                ],
                if (call.failure != null && call.phase != CallPhase.connected) ...<Widget>[
                  const SizedBox(height: 16),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: Text(
                      call.failure!,
                      textAlign: TextAlign.center,
                      style: TextStyle(color: scheme.error),
                    ),
                  ),
                ],
                const Spacer(),
                if (answering)
                  _AnswerBar(
                    onAccept: () => call.answer(widget.incomingCallId!),
                    onDecline: () => call.decline(widget.incomingCallId!),
                  )
                else
                  _InCallBar(call: call),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ),
      ),
    );
  }

  String _statusLine(CallController call, bool answering) {
    if (answering) {
      return 'Incoming call';
    }

    return switch (call.phase) {
      CallPhase.preparing => 'Getting ready…',
      CallPhase.permissionDenied => 'Microphone blocked',
      CallPhase.ringing => 'Ringing…',
      CallPhase.connecting => 'Connecting…',
      CallPhase.connected => _duration(call.elapsed),
      CallPhase.failed => 'Call failed',
      CallPhase.ended => 'Call ended',
    };
  }

  String _duration(Duration value) {
    final String minutes = value.inMinutes.toString().padLeft(2, '0');
    final String seconds = (value.inSeconds % 60).toString().padLeft(2, '0');
    return '$minutes:$seconds';
  }
}

/// Shown when the server has no TURN relay configured.
///
/// Said plainly rather than hidden, because the failure it predicts is silent
/// and would otherwise look like the app being broken.
class _RelayWarning extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: scheme.tertiaryContainer,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(Icons.info_outline_rounded, size: 18, color: scheme.onTertiaryContainer),
          const SizedBox(width: 8),
          Flexible(
            child: Text(
              'No relay configured — this call may not connect on mobile data.',
              style: TextStyle(fontSize: 12, color: scheme.onTertiaryContainer),
            ),
          ),
        ],
      ),
    );
  }
}

class _AnswerBar extends StatelessWidget {
  const _AnswerBar({required this.onAccept, required this.onDecline});

  final VoidCallback onAccept;
  final VoidCallback onDecline;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
      children: <Widget>[
        _RoundButton(
          icon: Icons.call_end_rounded,
          colour: Theme.of(context).colorScheme.error,
          label: 'Decline',
          onPressed: onDecline,
        ),
        _RoundButton(
          icon: Icons.call_rounded,
          colour: const Color(0xFF1B873F),
          label: 'Answer',
          onPressed: onAccept,
        ),
      ],
    );
  }
}

class _InCallBar extends StatelessWidget {
  const _InCallBar({required this.call});

  final CallController call;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
      children: <Widget>[
        _RoundButton(
          icon: call.muted ? Icons.mic_off_rounded : Icons.mic_rounded,
          colour: Theme.of(context).colorScheme.secondaryContainer,
          foreground: Theme.of(context).colorScheme.onSecondaryContainer,
          label: call.muted ? 'Unmute' : 'Mute',
          onPressed: call.toggleMute,
        ),
        _RoundButton(
          icon: Icons.call_end_rounded,
          colour: Theme.of(context).colorScheme.error,
          label: 'End',
          onPressed: call.hangUp,
        ),
        _RoundButton(
          icon: call.speakerOn ? Icons.volume_up_rounded : Icons.hearing_rounded,
          colour: Theme.of(context).colorScheme.secondaryContainer,
          foreground: Theme.of(context).colorScheme.onSecondaryContainer,
          label: call.speakerOn ? 'Speaker' : 'Earpiece',
          onPressed: call.toggleSpeaker,
        ),
      ],
    );
  }
}

class _RoundButton extends StatelessWidget {
  const _RoundButton({
    required this.icon,
    required this.colour,
    required this.label,
    required this.onPressed,
    this.foreground,
  });

  final IconData icon;
  final Color colour;
  final Color? foreground;
  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Material(
          color: colour,
          shape: const CircleBorder(),
          child: InkWell(
            customBorder: const CircleBorder(),
            onTap: onPressed,
            child: Padding(
              padding: const EdgeInsets.all(18),
              child: Icon(icon, size: 28, color: foreground ?? Colors.white),
            ),
          ),
        ),
        const SizedBox(height: 8),
        Text(label, style: const TextStyle(fontSize: 12)),
      ],
    );
  }
}
