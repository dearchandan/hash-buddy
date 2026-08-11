import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart';
import 'package:permission_handler/permission_handler.dart';

import '../api/api_client.dart';
import '../models/call_session.dart';

enum CallPhase { preparing, permissionDenied, ringing, connecting, connected, ended, failed }

/// Drives one voice call from dial to hang-up.
///
/// Signalling is a single offer and a single answer over the REST API — ICE
/// candidates are gathered fully before the offer is sent rather than trickled,
/// which costs a second or two of setup and buys a path that survives push
/// delivery jitter without a websocket anywhere.
class CallController extends ChangeNotifier {
  CallController(this._api, {required this.groupId});

  final ApiClient _api;
  final int groupId;

  RTCPeerConnection? _peer;
  MediaStream? _localStream;
  Timer? _poll;

  CallPhase _phase = CallPhase.preparing;
  CallSession? _session;
  String? _failure;
  bool _muted = false;
  bool _speaker = true;
  bool _relayAvailable = true;
  DateTime? _connectedAt;

  CallPhase get phase => _phase;
  CallSession? get session => _session;
  String? get failure => _failure;
  bool get muted => _muted;
  bool get speakerOn => _speaker;

  /// False when the server has no TURN configured. Calls still connect on the
  /// same wifi; between two mobile networks they usually will not.
  bool get relayAvailable => _relayAvailable;

  Duration get elapsed =>
      _connectedAt == null ? Duration.zero : DateTime.now().difference(_connectedAt!);

  // ---------------------------------------------------------------- outgoing

  Future<void> dial(int calleeId) async {
    if (!await _prepareMedia()) {
      return;
    }

    try {
      _set(CallPhase.ringing);

      final IceConfig config = await _iceConfig();
      await _createPeer(config);

      // Gather everything, then offer once. A trickle design would need a
      // second channel for candidates and a lot more moving parts.
      final RTCSessionDescription offer = await _peer!.createOffer();
      await _peer!.setLocalDescription(offer);
      final String sdp = await _gatheredSdp();

      final dynamic response = await _api.post(
        '/groups/$groupId/calls',
        body: <String, dynamic>{'callee_id': calleeId, 'offer_sdp': sdp},
      );

      _session = CallSession.fromJson(ApiClient.unwrap(response));
      notifyListeners();

      _startPolling(config.pollSeconds);
    } catch (error) {
      await _fail(error);
    }
  }

  // ---------------------------------------------------------------- incoming

  Future<void> answer(int callId) async {
    if (!await _prepareMedia()) {
      return;
    }

    try {
      _set(CallPhase.connecting);

      final IceConfig config = await _iceConfig();
      final dynamic detail = await _api.get('/calls/$callId');
      final CallSession incoming = CallSession.fromJson(ApiClient.unwrap(detail));
      _session = incoming;

      if (incoming.offerSdp == null) {
        throw StateError('That call is no longer available.');
      }

      await _createPeer(config);
      await _peer!.setRemoteDescription(RTCSessionDescription(incoming.offerSdp, 'offer'));

      final RTCSessionDescription answer = await _peer!.createAnswer();
      await _peer!.setLocalDescription(answer);
      final String sdp = await _gatheredSdp();

      final dynamic response = await _api.post(
        '/calls/$callId/accept',
        body: <String, dynamic>{'answer_sdp': sdp},
      );

      _session = CallSession.fromJson(ApiClient.unwrap(response));
      notifyListeners();
    } catch (error) {
      await _fail(error);
    }
  }

  Future<void> decline(int callId) async {
    try {
      await _api.post('/calls/$callId/decline');
    } catch (_) {
      // Hanging up must always succeed locally; the server reconciles a call
      // that was never declined when it rings out.
    }
    await hangUp();
  }

  Future<void> hangUp() async {
    final int? id = _session?.id;

    if (id != null && !(_session?.isOver ?? false)) {
      try {
        await _api.post('/calls/$id/hang-up');
      } catch (_) {
        // Same reasoning as decline.
      }
    }

    await _teardown();
    _set(CallPhase.ended);
  }

  // ----------------------------------------------------------------- controls

  Future<void> toggleMute() async {
    _muted = !_muted;
    for (final MediaStreamTrack track in _localStream?.getAudioTracks() ?? <MediaStreamTrack>[]) {
      track.enabled = !_muted;
    }
    notifyListeners();
  }

  Future<void> toggleSpeaker() async {
    _speaker = !_speaker;
    // Airport concourses are loud, so a call starts on speaker and this is the
    // way back to the earpiece.
    await Helper.setSpeakerphoneOn(_speaker);
    notifyListeners();
  }

  // ------------------------------------------------------------------ plumbing

  Future<bool> _prepareMedia() async {
    final PermissionStatus status = await Permission.microphone.request();

    if (!status.isGranted) {
      _failure = 'Hash Buddy needs the microphone to place a call.';
      _set(CallPhase.permissionDenied);
      return false;
    }

    try {
      _localStream = await navigator.mediaDevices.getUserMedia(<String, dynamic>{
        'audio': true,
        'video': false,
      });
      await Helper.setSpeakerphoneOn(_speaker);
      return true;
    } catch (error) {
      await _fail(error);
      return false;
    }
  }

  Future<IceConfig> _iceConfig() async {
    final dynamic response = await _api.get('/calls/ice-servers');
    final IceConfig config = IceConfig.fromJson(ApiClient.unwrap(response));

    _relayAvailable = config.hasTurn;
    notifyListeners();

    return config;
  }

  Future<void> _createPeer(IceConfig config) async {
    _peer = await createPeerConnection(<String, dynamic>{
      'iceServers': config.iceServers,
      'sdpSemantics': 'unified-plan',
    });

    for (final MediaStreamTrack track in _localStream!.getAudioTracks()) {
      await _peer!.addTrack(track, _localStream!);
    }

    _peer!.onConnectionState = (RTCPeerConnectionState state) {
      switch (state) {
        case RTCPeerConnectionState.RTCPeerConnectionStateConnected:
          _connectedAt ??= DateTime.now();
          _set(CallPhase.connected);
        case RTCPeerConnectionState.RTCPeerConnectionStateFailed:
          _failure = _relayAvailable
              ? 'The call could not connect.'
              : 'The call could not connect. This server has no TURN relay, so '
                  'calls between two mobile networks usually fail.';
          _set(CallPhase.failed);
        case RTCPeerConnectionState.RTCPeerConnectionStateDisconnected:
        case RTCPeerConnectionState.RTCPeerConnectionStateClosed:
          if (_phase == CallPhase.connected) {
            _set(CallPhase.ended);
          }
        default:
          break;
      }
    };
  }

  /// Wait for ICE gathering to finish, then take the local description.
  ///
  /// Capped rather than open-ended: a candidate that has not arrived in five
  /// seconds is one that would not have helped, and callers should not stare at
  /// a dialling screen because one STUN server is unreachable.
  Future<String> _gatheredSdp() async {
    final Completer<void> done = Completer<void>();

    _peer!.onIceGatheringState = (RTCIceGatheringState state) {
      if (state == RTCIceGatheringState.RTCIceGatheringStateComplete && !done.isCompleted) {
        done.complete();
      }
    };

    await Future.any<void>(<Future<void>>[
      done.future,
      Future<void>.delayed(const Duration(seconds: 5)),
    ]);

    final RTCSessionDescription? local = await _peer!.getLocalDescription();
    final String? sdp = local?.sdp;

    if (sdp == null) {
      throw StateError('Could not prepare the call.');
    }

    return sdp;
  }

  /// The caller polls for the answer. Only until it arrives — once the peer
  /// connection is up the server has nothing left to tell us.
  void _startPolling(int seconds) {
    _poll?.cancel();
    _poll = Timer.periodic(Duration(seconds: seconds), (Timer timer) async {
      try {
        final dynamic response = await _api.get('/groups/$groupId/calls/current');
        final dynamic data = response is Map<String, dynamic> ? response['data'] : null;

        if (data is! Map<String, dynamic>) {
          // Gone from the live set: declined, or rang out unanswered.
          timer.cancel();
          _failure = 'No answer.';
          await _teardown();
          _set(CallPhase.ended);
          return;
        }

        final CallSession current = CallSession.fromJson(data);
        _session = current;

        if (current.isAccepted && current.answerSdp != null) {
          timer.cancel();
          _set(CallPhase.connecting);
          await _peer!.setRemoteDescription(
            RTCSessionDescription(current.answerSdp, 'answer'),
          );
        } else if (current.isOver) {
          timer.cancel();
          _failure = current.endedLabel;
          await _teardown();
          _set(CallPhase.ended);
        }
      } catch (_) {
        // Transient: the next tick retries.
      }
    });
  }

  Future<void> _fail(Object error) async {
    _failure = error is StateError ? error.message : 'The call could not be placed.';
    await _teardown();
    _set(CallPhase.failed);
  }

  Future<void> _teardown() async {
    _poll?.cancel();
    _poll = null;

    for (final MediaStreamTrack track in _localStream?.getTracks() ?? <MediaStreamTrack>[]) {
      await track.stop();
    }
    await _localStream?.dispose();
    _localStream = null;

    await _peer?.close();
    _peer = null;
  }

  void _set(CallPhase phase) {
    _phase = phase;
    notifyListeners();
  }

  @override
  void dispose() {
    // Not awaited: dispose cannot be async, and leaving the microphone hot
    // after the screen is gone is the one outcome to avoid at all costs.
    unawaited(_teardown());
    super.dispose();
  }
}
