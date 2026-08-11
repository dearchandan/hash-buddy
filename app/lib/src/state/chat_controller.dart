import 'dart:async';

import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../models/message.dart';

/// Chat for one ride, kept fresh by polling.
///
/// Polling rather than a socket: with push carrying the "you have a message"
/// signal, the only job left is filling in the thread while someone is looking
/// at it, and a few seconds of latency is invisible for "I'm at gate 4".
class ChatController extends ChangeNotifier {
  ChatController(this._api, this.groupId);

  final ApiClient _api;
  final int groupId;

  final List<Message> _messages = <Message>[];
  Timer? _poll;
  bool _loading = true;
  bool _sending = false;
  Object? _error;

  List<Message> get messages => List<Message>.unmodifiable(_messages);
  bool get loading => _loading;
  bool get sending => _sending;
  Object? get error => _error;

  int? get _lastId => _messages.isEmpty ? null : _messages.last.id;

  Future<void> start() async {
    await _loadInitial();

    // Cancel first so a hot restart or a second start() cannot leave two timers
    // racing to append the same messages.
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 4), (_) => _fetchNew());
  }

  @override
  void dispose() {
    _poll?.cancel();
    _poll = null;
    super.dispose();
  }

  Future<void> _loadInitial() async {
    try {
      final dynamic response = await _api.get('/groups/$groupId/messages');
      _messages
        ..clear()
        ..addAll(ApiClient.unwrapList(response).map(Message.fromJson));
      _error = null;
    } catch (error) {
      _error = error;
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  Future<void> _fetchNew() async {
    final int? after = _lastId;
    if (after == null) {
      await _loadInitial();
      return;
    }

    try {
      final dynamic response = await _api.get(
        '/groups/$groupId/messages',
        query: <String, String>{'after': '$after'},
      );

      final List<Message> fresh = ApiClient.unwrapList(response).map(Message.fromJson).toList();
      if (fresh.isEmpty) {
        return;
      }

      _append(fresh);
      notifyListeners();
    } catch (_) {
      // A failed poll is not worth surfacing — the next one is four seconds
      // away, and an error banner that flickers on every tunnel and lift is
      // worse than briefly stale messages.
    }
  }

  Future<void> send(String body) async {
    final String trimmed = body.trim();
    if (trimmed.isEmpty || _sending) {
      return;
    }

    _sending = true;
    notifyListeners();

    try {
      final dynamic response = await _api.post(
        '/groups/$groupId/messages',
        body: <String, dynamic>{'body': trimmed},
      );
      _append(<Message>[Message.fromJson(ApiClient.unwrap(response))]);
      _error = null;
    } catch (error) {
      _error = error;
      rethrow;
    } finally {
      _sending = false;
      notifyListeners();
    }
  }

  /// Append, ignoring anything already held.
  ///
  /// A send and a poll can return the same message, and rendering it twice is
  /// the kind of bug people screenshot.
  void _append(List<Message> incoming) {
    final Set<int> known = _messages.map((Message m) => m.id).toSet();

    for (final Message message in incoming) {
      if (known.add(message.id)) {
        _messages.add(message);
      }
    }

    _messages.sort((Message a, Message b) => a.id.compareTo(b.id));
  }
}
