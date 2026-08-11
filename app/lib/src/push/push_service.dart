import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';

import '../api/api_client.dart';

/// Incoming push, already parsed.
class PushEvent {
  const PushEvent({required this.type, required this.data});

  final String type;
  final Map<String, String> data;

  int? get groupId => int.tryParse(data['ride_group_id'] ?? '');
  int? get callId => int.tryParse(data['call_id'] ?? '');
  String get callerName => data['caller_name'] ?? 'Your ride mate';
}

/// Registers this install for push and surfaces what arrives.
///
/// Every entry point is defensive on purpose. Firebase is configured per build
/// by a `google-services.json` that is deliberately not in the repository, so a
/// checkout without one must still run — chat polls, calls can be placed, and
/// only the background notifications are missing. A hard failure here would
/// make the whole app un-runnable for anyone who has not set up Firebase.
class PushService {
  PushService(this._api);

  final ApiClient _api;

  final StreamController<PushEvent> _events = StreamController<PushEvent>.broadcast();
  String? _token;
  bool _available = false;

  Stream<PushEvent> get events => _events.stream;

  /// False when Firebase is absent or the user declined notifications. The app
  /// falls back to polling, which works while a screen is open.
  bool get available => _available;

  Future<void> start() async {
    try {
      await Firebase.initializeApp();
    } catch (error) {
      // No google-services.json, or a malformed one.
      debugPrint('Push disabled: Firebase is not configured ($error)');
      return;
    }

    try {
      final FirebaseMessaging messaging = FirebaseMessaging.instance;

      // Android 13+ needs POST_NOTIFICATIONS at runtime. Declining is a normal
      // choice, not an error.
      final NotificationSettings settings = await messaging.requestPermission();
      if (settings.authorizationStatus == AuthorizationStatus.denied) {
        debugPrint('Push disabled: notifications declined');
        return;
      }

      _available = true;

      FirebaseMessaging.onMessage.listen(_handle);
      FirebaseMessaging.onMessageOpenedApp.listen(_handle);

      // A token can rotate at any time; a client that registers only once
      // eventually goes silent with nothing anywhere to explain why.
      messaging.onTokenRefresh.listen(_register);

      final String? token = await messaging.getToken();
      if (token != null) {
        await _register(token);
      }
    } catch (error) {
      debugPrint('Push setup failed: $error');
    }
  }

  /// Called after sign-in, when there is finally a token to register against.
  Future<void> syncRegistration() async {
    if (!_available) {
      return;
    }

    try {
      final String? token = _token ?? await FirebaseMessaging.instance.getToken();
      if (token != null) {
        await _register(token);
      }
    } catch (error) {
      debugPrint('Push registration failed: $error');
    }
  }

  /// Called on sign-out so a shared handset stops buzzing for whoever just left.
  Future<void> unregister() async {
    final String? token = _token;
    if (token == null) {
      return;
    }

    try {
      await _api.delete('/me/devices', body: <String, dynamic>{'token': token});
    } catch (_) {
      // Signing out must not fail because the network did.
    }
    _token = null;
  }

  Future<void> _register(String token) async {
    _token = token;

    try {
      await _api.post('/me/devices', body: <String, dynamic>{
        'token': token,
        'platform': 'android',
      });
    } catch (error) {
      // Unauthenticated on first launch is expected — syncRegistration runs
      // again once the traveller signs in.
      debugPrint('Device registration deferred: $error');
    }
  }

  void _handle(RemoteMessage message) {
    final Map<String, String> data = message.data.map(
      (String key, dynamic value) => MapEntry<String, String>(key, '$value'),
    );

    final String? type = data['type'];
    if (type == null) {
      return;
    }

    _events.add(PushEvent(type: type, data: data));
  }

  void dispose() => _events.close();
}
