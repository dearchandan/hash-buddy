import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../models/app_user.dart';

enum AuthStatus { unknown, signedOut, awaitingProfile, signedIn }

class AuthController extends ChangeNotifier {
  AuthController(this._api) {
    // A rejected token means the server has already forgotten us, so there is
    // nothing to tell it — calling the server here would 401 in turn and drive
    // this callback round again.
    _api.onUnauthenticated = () => signOut(notifyServer: false);
  }

  static const String _tokenKey = 'hash_buddy_token';

  final ApiClient _api;

  AuthStatus _status = AuthStatus.unknown;
  AppUser? _user;
  String? _pendingPhone;
  bool _signingOut = false;

  AuthStatus get status => _status;
  AppUser? get user => _user;
  String? get pendingPhone => _pendingPhone;

  /// Restore a saved session on launch.
  Future<void> bootstrap() async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    final String? token = prefs.getString(_tokenKey);

    if (token == null || token.isEmpty) {
      _set(AuthStatus.signedOut);
      return;
    }

    _api.setToken(token);
    try {
      _user = AppUser.fromJson(ApiClient.unwrap(await _api.get('/me')));
      _set(_user!.name == 'Traveller' ? AuthStatus.awaitingProfile : AuthStatus.signedIn);
    } catch (_) {
      // Token was rejected or the API is unreachable — start clean.
      await _clearToken();
      _set(AuthStatus.signedOut);
    }
  }

  /// Ask for a code. Returns the debug code when the API is in debug mode, so
  /// the OTP screen can prefill it locally.
  Future<String?> requestOtp(String phone) async {
    final dynamic response = await _api.post('/auth/otp', body: <String, dynamic>{'phone': phone});
    _pendingPhone = phone;
    notifyListeners();

    return response is Map<String, dynamic> ? response['debug_code'] as String? : null;
  }

  Future<void> verifyOtp(String code) async {
    final String? phone = _pendingPhone;
    if (phone == null) {
      throw StateError('Ask for a code before verifying one.');
    }

    final dynamic response = await _api.post('/auth/verify', body: <String, dynamic>{
      'phone': phone,
      'code': code,
      'device_name': 'mobile',
    });

    final Map<String, dynamic> body = response as Map<String, dynamic>;
    final String token = body['token'] as String;

    final SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.setString(_tokenKey, token);
    _api.setToken(token);

    _user = AppUser.fromJson(body['user'] as Map<String, dynamic>);
    _pendingPhone = null;

    _set((body['needs_profile'] as bool? ?? false) ? AuthStatus.awaitingProfile : AuthStatus.signedIn);
  }

  Future<void> saveProfile({required String name, required String gender}) async {
    _user = AppUser.fromJson(ApiClient.unwrap(
      await _api.patch('/me', body: <String, dynamic>{'name': name, 'gender': gender}),
    ));
    _set(AuthStatus.signedIn);
  }

  Future<void> signOut({bool notifyServer = true}) async {
    if (_signingOut) {
      return;
    }
    _signingOut = true;

    try {
      if (notifyServer) {
        try {
          await _api.post('/auth/logout');
        } catch (_) {
          // Signing out locally matters more than telling the server about it.
        }
      }

      await _clearToken();
      _user = null;
      _pendingPhone = null;
      _set(AuthStatus.signedOut);
    } finally {
      _signingOut = false;
    }
  }

  Future<void> _clearToken() async {
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
    _api.setToken(null);
  }

  void _set(AuthStatus status) {
    _status = status;
    notifyListeners();
  }
}
