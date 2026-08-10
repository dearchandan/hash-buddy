class Zone {
  const Zone({
    required this.id,
    required this.name,
    required this.slug,
    required this.city,
    required this.distanceKm,
    required this.sedanFare,
    required this.suvFare,
  });

  factory Zone.fromJson(Map<String, dynamic> json) => Zone(
        id: json['id'] as int,
        name: json['name'] as String,
        slug: json['slug'] as String? ?? '',
        city: json['city'] as String? ?? 'Bengaluru',
        distanceKm: json['distance_km'] as int? ?? 0,
        sedanFare: json['sedan_fare'] as int? ?? 0,
        suvFare: json['suv_fare'] as int? ?? 0,
      );

  final int id;
  final String name;
  final String slug;
  final String city;
  final int distanceKm;
  final int sedanFare;
  final int suvFare;
}
