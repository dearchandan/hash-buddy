import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../api/api_client.dart';
import '../../models/message.dart';
import '../../models/ride_group.dart';
import '../../state/auth_controller.dart';
import '../../state/chat_controller.dart';
import '../formatters.dart';
import 'call_screen.dart';

/// Chat for one ride. Exists to solve exactly one problem: two strangers
/// finding each other at a kerb.
class ChatScreen extends StatelessWidget {
  const ChatScreen({required this.group, super.key});

  final RideGroup group;

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider<ChatController>(
      create: (BuildContext context) => ChatController(context.read<ApiClient>(), group.id)..start(),
      child: _ChatView(group: group),
    );
  }
}

class _ChatView extends StatefulWidget {
  const _ChatView({required this.group});

  final RideGroup group;

  @override
  State<_ChatView> createState() => _ChatViewState();
}

class _ChatViewState extends State<_ChatView> {
  final TextEditingController _input = TextEditingController();
  final ScrollController _scroll = ScrollController();
  int _lastSeenCount = 0;

  @override
  void dispose() {
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final String body = _input.text;
    if (body.trim().isEmpty) {
      return;
    }

    // Cleared optimistically: leaving the text in place while the request is in
    // flight invites a double-send on a slow connection.
    _input.clear();

    try {
      await context.read<ChatController>().send(body);
    } catch (error) {
      if (mounted) {
        showError(context, error);
        // Give it back so the message is not simply lost.
        _input.text = body;
      }
    }
  }

  void _scrollToEnd() {
    if (!_scroll.hasClients) {
      return;
    }
    _scroll.animateTo(
      _scroll.position.maxScrollExtent,
      duration: const Duration(milliseconds: 220),
      curve: Curves.easeOut,
    );
  }

  @override
  Widget build(BuildContext context) {
    final ChatController chat = context.watch<ChatController>();
    final ColorScheme scheme = Theme.of(context).colorScheme;

    // Only follow the conversation when something new arrives, so reading back
    // through history is not yanked to the bottom every four seconds.
    if (chat.messages.length != _lastSeenCount) {
      _lastSeenCount = chat.messages.length;
      WidgetsBinding.instance.addPostFrameCallback((_) => _scrollToEnd());
    }

    final int? myId = context.watch<AuthController>().user?.id;
    final List<RideGroupMember> others =
        widget.group.members.where((RideGroupMember m) => m.user.id != myId).toList();

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Text(
              others.isEmpty
                  ? 'Ride ${widget.group.code}'
                  : others.map((RideGroupMember m) => m.user.name).join(', '),
              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700),
            ),
            Text(
              'Ride ${widget.group.code}',
              style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
            ),
          ],
        ),
        actions: <Widget>[
          IconButton(
            tooltip: 'Call',
            icon: const Icon(Icons.call_rounded),
            onPressed: others.isEmpty ? null : () => _startCall(context, others.first),
          ),
        ],
      ),
      body: Column(
        children: <Widget>[
          Expanded(child: _buildBody(chat)),
          _Composer(controller: _input, sending: chat.sending, onSend: _send),
        ],
      ),
    );
  }

  Future<void> _startCall(BuildContext context, RideGroupMember other) async {
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => CallScreen.outgoing(group: widget.group, other: other.user),
      ),
    );
  }

  Widget _buildBody(ChatController chat) {
    if (chat.loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (chat.error != null && chat.messages.isEmpty) {
      return Center(child: Text(errorMessage(chat.error!)));
    }

    if (chat.messages.isEmpty) {
      return const _Empty();
    }

    return ListView.builder(
      controller: _scroll,
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 8),
      itemCount: chat.messages.length,
      itemBuilder: (BuildContext context, int index) => _Bubble(message: chat.messages[index]),
    );
  }
}

class _Empty extends StatelessWidget {
  const _Empty();

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 40),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            Icon(Icons.chat_bubble_outline_rounded, size: 48, color: scheme.outline),
            const SizedBox(height: 16),
            const Text(
              'Say hello',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 8),
            Text(
              'Agree where to meet and what you are wearing. Most people find each '
              'other faster that way than by describing the kerb.',
              textAlign: TextAlign.center,
              style: TextStyle(color: scheme.onSurfaceVariant),
            ),
          ],
        ),
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.message});

  final Message message;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    if (message.isSystem) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Center(
          child: Text(
            message.body,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
          ),
        ),
      );
    }

    final bool mine = message.isMine;

    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.78),
        margin: const EdgeInsets.symmetric(vertical: 4),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          color: mine ? scheme.primary : scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.only(
            topLeft: const Radius.circular(16),
            topRight: const Radius.circular(16),
            bottomLeft: Radius.circular(mine ? 16 : 4),
            bottomRight: Radius.circular(mine ? 4 : 16),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            if (!mine && message.sender != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 2),
                child: Text(
                  message.sender!.name,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: scheme.primary,
                  ),
                ),
              ),
            Text(
              message.body,
              style: TextStyle(color: mine ? scheme.onPrimary : scheme.onSurface),
            ),
            const SizedBox(height: 2),
            Text(
              clockTime(message.createdAt),
              style: TextStyle(
                fontSize: 10,
                color: mine ? scheme.onPrimary.withValues(alpha: 0.7) : scheme.onSurfaceVariant,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Composer extends StatelessWidget {
  const _Composer({required this.controller, required this.sending, required this.onSend});

  final TextEditingController controller;
  final bool sending;
  final VoidCallback onSend;

  @override
  Widget build(BuildContext context) {
    final ColorScheme scheme = Theme.of(context).colorScheme;

    return SafeArea(
      top: false,
      child: Container(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
        decoration: BoxDecoration(
          color: scheme.surface,
          border: Border(top: BorderSide(color: scheme.outlineVariant)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.end,
          children: <Widget>[
            Expanded(
              child: TextField(
                controller: controller,
                minLines: 1,
                maxLines: 4,
                textCapitalization: TextCapitalization.sentences,
                textInputAction: TextInputAction.send,
                onSubmitted: (_) => onSend(),
                decoration: const InputDecoration(
                  hintText: 'Message',
                  border: OutlineInputBorder(),
                  isDense: true,
                  contentPadding: EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                ),
              ),
            ),
            const SizedBox(width: 8),
            IconButton.filled(
              onPressed: sending ? null : onSend,
              icon: sending
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.send_rounded),
            ),
          ],
        ),
      ),
    );
  }
}
