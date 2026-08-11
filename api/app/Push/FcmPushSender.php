<?php

namespace App\Push;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Firebase Cloud Messaging over the HTTP v1 API.
 *
 * v1 authenticates with a short-lived OAuth token derived from a service
 * account, not the old static server key. That needs a signed JWT, which is
 * ~30 lines with openssl — cheaper than taking on the whole Google API client
 * and its transitive dependencies for one endpoint.
 */
class FcmPushSender implements PushSender
{
    private const TOKEN_CACHE_KEY = 'fcm.access_token';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function send(User $user, PushMessage $message): int
    {
        $devices = $user->deviceTokens()->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        try {
            $accessToken = $this->accessToken();
        } catch (Throwable $e) {
            // Credentials missing or malformed. Log once and give up quietly:
            // the caller is delivering a chat message, and failing that because
            // Firebase is misconfigured would lose the message itself.
            Log::error('push.fcm.auth_failed', ['error' => $e->getMessage()]);

            return 0;
        }

        $projectId = (string) config('hashbuddy.push.fcm.project_id');
        $sent = 0;

        foreach ($devices as $device) {
            if ($this->sendToDevice($accessToken, $projectId, $device, $message)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function sendToDevice(
        string $accessToken,
        string $projectId,
        DeviceToken $device,
        PushMessage $message,
    ): bool {
        $payload = ['token' => $device->token, 'data' => $message->payload()];

        if (! $message->isSilent()) {
            $payload['notification'] = [
                'title' => $message->title,
                'body' => $message->body,
            ];
            $payload['android'] = ['priority' => 'high'];
        } else {
            // A ringing call must wake a dozing handset, and a data-only
            // message is throttled into oblivion at normal priority.
            $payload['android'] = ['priority' => 'high'];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => $payload,
                ]);
        } catch (Throwable $e) {
            Log::warning('push.fcm.transport_failed', ['error' => $e->getMessage()]);

            return false;
        }

        if ($response->successful()) {
            $device->forceFill(['last_used_at' => now()])->saveQuietly();

            return true;
        }

        // A token dies when the app is uninstalled or its data cleared. Left in
        // place it is retried on every message forever, so prune on the two
        // statuses that mean "this destination no longer exists".
        $status = (string) $response->json('error.details.0.errorCode', '');

        if ($response->status() === 404 || $status === 'UNREGISTERED' || $status === 'INVALID_ARGUMENT') {
            $device->delete();

            return false;
        }

        Log::warning('push.fcm.rejected', [
            'status' => $response->status(),
            'error' => $response->json('error.message'),
        ]);

        return false;
    }

    /**
     * Google's tokens last an hour; cache just under that so a burst of
     * messages costs one token exchange rather than one per message.
     */
    private function accessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(55), function (): string {
            $credentials = $this->credentials();
            $now = time();

            $claims = [
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $jwt = $this->signJwt($claims, $credentials['private_key']);

            $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('FCM token exchange failed: '.$response->body());
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    private function credentials(): array
    {
        $path = (string) config('hashbuddy.push.fcm.credentials');

        if ($path === '' || ! is_readable($path)) {
            throw new \RuntimeException("FCM credentials not readable at: {$path}");
        }

        $json = json_decode((string) file_get_contents($path), true);

        if (! is_array($json) || ! isset($json['client_email'], $json['private_key'])) {
            throw new \RuntimeException('FCM credentials file is not a service account JSON.');
        }

        return ['client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function signJwt(array $claims, string $privateKey): string
    {
        $encode = static fn (array $part): string => rtrim(
            strtr(base64_encode(json_encode($part, JSON_UNESCAPED_SLASHES)), '+/', '-_'),
            '=',
        );

        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims);

        $signature = '';
        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign the FCM assertion.');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
