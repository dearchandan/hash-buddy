class AppUser {
  const AppUser({
    required this.id,
    required this.name,
    this.phone,
    this.gender,
    this.avatarUrl,
    this.bio,
    this.ratingAvg = 0,
    this.ratingCount = 0,
    this.ridesCompleted = 0,
  });

  factory AppUser.fromJson(Map<String, dynamic> json) => AppUser(
        id: json['id'] as int,
        name: json['name'] as String? ?? 'Traveller',
        phone: json['phone'] as String?,
        gender: json['gender'] as String?,
        avatarUrl: json['avatar_url'] as String?,
        bio: json['bio'] as String?,
        ratingAvg: (json['rating_avg'] as num?)?.toDouble() ?? 0,
        ratingCount: json['rating_count'] as int? ?? 0,
        ridesCompleted: json['rides_completed'] as int? ?? 0,
      );

  final int id;
  final String name;
  final String? phone;
  final String? gender;
  final String? avatarUrl;
  final String? bio;
  final double ratingAvg;
  final int ratingCount;
  final int ridesCompleted;

  bool get hasRating => ratingCount > 0;

  String get initials => name.trim().isEmpty ? '?' : name.trim()[0].toUpperCase();
}
