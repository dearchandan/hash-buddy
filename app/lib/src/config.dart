/// Where the Laravel API lives.
///
/// Override per device at build time, e.g.
///   flutter run --dart-define=HASH_BUDDY_API=http://192.168.1.5:8000/api/v1
///
/// The default targets the Android emulator, which reaches the host machine's
/// localhost through 10.0.2.2. An iOS simulator should use 127.0.0.1.
class AppConfig {
  const AppConfig._();

  static const String apiBaseUrl = String.fromEnvironment(
    'HASH_BUDDY_API',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  /// The only airport the matcher knows about today.
  static const String airportCode = 'BLR';
  static const List<String> terminals = <String>['T1', 'T2'];
}
