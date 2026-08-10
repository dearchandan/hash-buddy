class FareEstimate {
  const FareEstimate({
    required this.vehicleClass,
    required this.totalFare,
    required this.perHeadFare,
    required this.soloFare,
    required this.savingsPerHead,
    required this.savingsPercent,
    required this.passengers,
    required this.luggage,
  });

  factory FareEstimate.fromJson(Map<String, dynamic> json) => FareEstimate(
        vehicleClass: json['vehicle_class'] as String? ?? 'sedan',
        totalFare: json['total_fare'] as int? ?? 0,
        perHeadFare: json['per_head_fare'] as int? ?? 0,
        soloFare: json['solo_fare'] as int? ?? 0,
        savingsPerHead: json['savings_per_head'] as int? ?? 0,
        savingsPercent: json['savings_percent'] as int? ?? 0,
        passengers: json['passengers'] as int? ?? 1,
        luggage: json['luggage'] as int? ?? 0,
      );

  final String vehicleClass;
  final int totalFare;
  final int perHeadFare;
  final int soloFare;
  final int savingsPerHead;
  final int savingsPercent;
  final int passengers;
  final int luggage;

  bool get needsSuv => vehicleClass == 'suv';

  String get vehicleLabel => needsSuv ? 'SUV' : 'Sedan';
}
