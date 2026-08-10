import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../api/api_exception.dart';

String rupees(int amount) => '₹${NumberFormat.decimalPattern('en_IN').format(amount)}';

String clockTime(DateTime value) => DateFormat.jm().format(value);

String dayLabel(DateTime value) {
  final DateTime today = DateTime.now();
  final DateTime tomorrow = today.add(const Duration(days: 1));

  if (_sameDay(value, today)) {
    return 'Today';
  }
  if (_sameDay(value, tomorrow)) {
    return 'Tomorrow';
  }

  return DateFormat('EEE d MMM').format(value);
}

String windowLabel(DateTime start, DateTime end) => '${clockTime(start)} – ${clockTime(end)}';

String overlapLabel(int minutes) => '$minutes min together';

bool _sameDay(DateTime a, DateTime b) => a.year == b.year && a.month == b.month && a.day == b.day;

/// Turn any thrown object into something worth showing a traveller.
String errorMessage(Object error) {
  if (error is ApiException) {
    return error.firstFieldError ?? error.message;
  }

  return 'Something went wrong. Please try again.';
}

void showError(BuildContext context, Object error) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(errorMessage(error))));
}

void showMessage(BuildContext context, String message) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message)));
}
