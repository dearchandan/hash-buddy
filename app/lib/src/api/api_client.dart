import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config.dart';
import 'api_exception.dart';

/// Thin JSON wrapper over the Hash Buddy API.
class ApiClient {
  ApiClient({http.Client? httpClient}) : _http = httpClient ?? http.Client();

  final http.Client _http;
  String? _token;

  /// Called when the server rejects our token, so the app can sign out.
  void Function()? onUnauthenticated;

  void setToken(String? token) => _token = token;

  Future<dynamic> get(String path, {Map<String, String>? query}) => _send('GET', path, query: query);

  Future<dynamic> post(String path, {Map<String, dynamic>? body}) => _send('POST', path, body: body);

  Future<dynamic> patch(String path, {Map<String, dynamic>? body}) => _send('PATCH', path, body: body);

  /// DELETE carries a body for endpoints that identify the thing to remove by
  /// value rather than by id — unregistering a push token, for one, where the
  /// token has no business sitting in a URL and being written to access logs.
  Future<dynamic> delete(String path, {Map<String, dynamic>? body}) =>
      _send('DELETE', path, body: body);

  Future<dynamic> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
  }) async {
    final Uri uri = Uri.parse('${AppConfig.apiBaseUrl}$path').replace(
      queryParameters: query?.isEmpty ?? true ? null : query,
    );

    final Map<String, String> headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      if (_token != null) 'Authorization': 'Bearer $_token',
    };

    final String? payload = body == null ? null : jsonEncode(body);

    http.Response response;
    try {
      response = switch (method) {
        'GET' => await _http.get(uri, headers: headers),
        'POST' => await _http.post(uri, headers: headers, body: payload),
        'PATCH' => await _http.patch(uri, headers: headers, body: payload),
        'DELETE' => await _http.delete(uri, headers: headers, body: payload),
        _ => throw ArgumentError('Unsupported method $method'),
      };
    } on http.ClientException {
      throw ApiException("Can't reach Hash Buddy. Check your connection and the API address.");
    }

    final dynamic decoded = response.body.isEmpty ? null : jsonDecode(response.body);

    if (response.statusCode >= 200 && response.statusCode < 300) {
      return decoded;
    }

    if (response.statusCode == 401) {
      onUnauthenticated?.call();
    }

    throw ApiException.fromResponse(response.statusCode, decoded);
  }

  /// Most endpoints wrap a single resource in `data`; a few return the object
  /// at the top level. This accepts either.
  static Map<String, dynamic> unwrap(dynamic response) {
    if (response is Map<String, dynamic>) {
      final dynamic data = response['data'];
      if (data is Map<String, dynamic>) {
        return data;
      }
      return response;
    }
    throw ApiException('Unexpected response from the server.');
  }

  static List<Map<String, dynamic>> unwrapList(dynamic response) {
    final dynamic list = response is Map<String, dynamic> ? response['data'] : response;
    if (list is List) {
      return list.whereType<Map<String, dynamic>>().toList();
    }
    return <Map<String, dynamic>>[];
  }
}
