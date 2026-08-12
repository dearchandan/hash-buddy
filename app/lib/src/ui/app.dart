import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../push/push_service.dart';
import '../state/auth_controller.dart';
import '../state/rides_controller.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'screens/profile_setup_screen.dart';
import 'screens/splash_screen.dart';
import 'theme.dart';
import 'widgets/incoming_call_watcher.dart';

class HashBuddyApp extends StatelessWidget {
  const HashBuddyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        Provider<ApiClient>(create: (_) => ApiClient()),
        ChangeNotifierProvider<AuthController>(
          create: (BuildContext context) => AuthController(context.read<ApiClient>())..bootstrap(),
        ),
        ChangeNotifierProvider<RidesController>(
          create: (BuildContext context) => RidesController(context.read<ApiClient>()),
        ),
        // Started eagerly so a call invite can arrive before any ride screen is
        // open. Safe when Firebase is unconfigured: it disables itself.
        Provider<PushService>(
          create: (BuildContext context) => PushService(context.read<ApiClient>())..start(),
          dispose: (_, PushService service) => service.dispose(),
          lazy: false,
        ),
      ],
      child: MaterialApp(
        title: 'Hash Buddy',
        debugShowCheckedModeBanner: false,
        theme: buildHashBuddyTheme(),
        // Wraps the whole app rather than a ride screen: a call can arrive
        // while the traveller is anywhere, including on the home screen.
        home: const IncomingCallWatcher(child: _Root()),
      ),
    );
  }
}

class _Root extends StatefulWidget {
  const _Root();

  @override
  State<_Root> createState() => _RootState();
}

class _RootState extends State<_Root> {
  AuthStatus? _previous;

  /// Everything that has to happen the moment signing in or out completes.
  void _onSignInChanged(AuthStatus status) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) {
        return;
      }

      switch (status) {
        // Dropping a signed-out traveller's rides keeps the next person who
        // signs in on this device from seeing them.
        case AuthStatus.signedOut:
          context.read<RidesController>().reset();

        // The FCM token is fetched at launch, when nobody is signed in yet and
        // registering it can only be refused. This is the first moment the call
        // can succeed, and without it the device never registers at all — a
        // server with nowhere to deliver to looks exactly like push being
        // broken: no notifications, and an incoming call that never rings.
        case AuthStatus.awaitingProfile:
        case AuthStatus.signedIn:
          unawaited(context.read<PushService>().syncRegistration());

        case AuthStatus.unknown:
          break;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final AuthStatus status = context.watch<AuthController>().status;

    if (_previous != status) {
      _onSignInChanged(status);
    }
    _previous = status;

    return switch (status) {
      AuthStatus.unknown => const SplashScreen(),
      AuthStatus.signedOut => const LoginScreen(),
      AuthStatus.awaitingProfile => const ProfileSetupScreen(),
      AuthStatus.signedIn => const HomeScreen(),
    };
  }
}
