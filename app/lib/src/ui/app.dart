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
        home: const _Root(),
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

  @override
  Widget build(BuildContext context) {
    final AuthStatus status = context.watch<AuthController>().status;

    // Dropping a signed-out traveller's rides keeps the next person who signs
    // in on this device from seeing them.
    if (_previous != status && status == AuthStatus.signedOut) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) {
          context.read<RidesController>().reset();
        }
      });
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
