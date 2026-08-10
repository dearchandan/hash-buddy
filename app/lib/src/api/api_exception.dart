/// An error the API told us about, in a form the UI can show a traveller.
class ApiException implements Exception {
  ApiException(
    this.message, {
    this.statusCode = 0,
    this.errorCode,
    this.fieldErrors = const <String, List<String>>{},
  });

  factory ApiException.fromResponse(int statusCode, dynamic body) {
    if (body is Map<String, dynamic>) {
      final Map<String, List<String>> fields = <String, List<String>>{};
      final dynamic errors = body['errors'];
      if (errors is Map<String, dynamic>) {
        errors.forEach((String key, dynamic value) {
          if (value is List) {
            fields[key] = value.map((dynamic e) => e.toString()).toList();
          }
        });
      }

      return ApiException(
        (body['message'] as String?) ?? 'Something went wrong.',
        statusCode: statusCode,
        errorCode: body['error'] as String?,
        fieldErrors: fields,
      );
    }

    return ApiException('Something went wrong.', statusCode: statusCode);
  }

  final String message;
  final int statusCode;
  final String? errorCode;
  final Map<String, List<String>> fieldErrors;

  bool get isUnauthenticated => statusCode == 401;

  /// The first validation message, if the failure was a form problem.
  String? get firstFieldError =>
      fieldErrors.values.where((List<String> v) => v.isNotEmpty).map((List<String> v) => v.first).firstOrNull;

  @override
  String toString() => message;
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
