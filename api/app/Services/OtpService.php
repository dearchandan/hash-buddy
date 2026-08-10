<?php

namespace App\Services;

use App\Exceptions\RideException;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Phone-number login.
 *
 * There is no SMS gateway wired up yet. Until there is, a code can only reach a
 * traveller by being returned in the API response, which is safe locally and
 * for an explicit list of test numbers, and unacceptable for anyone else.
 */
class OtpService
{
    public function issue(string $phone): array
    {
        $reveal = $this->shouldRevealCode($phone);

        if (! $reveal && ! $this->hasSmsProvider()) {
            // Better a loud failure than issuing a code the traveller can never
            // receive and a login screen that hangs forever.
            throw new RideException(
                'Login is not available yet: no SMS provider is configured.',
                'sms_unavailable',
                503,
            );
        }

        $code = str_pad((string) random_int(0, 999999), config('hashbuddy.otp.length'), '0', STR_PAD_LEFT);

        // One live code per phone — issuing a new one retires the old.
        OtpCode::where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $otp = OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('hashbuddy.otp.ttl_minutes')),
        ]);

        $this->deliver($phone, $code);

        return [
            'expires_at' => $otp->expires_at,
            'debug_code' => $reveal ? $code : null,
        ];
    }

    /**
     * Returns the verified user, or null when the code is wrong or stale.
     */
    public function verify(string $phone, string $code): ?User
    {
        $otp = OtpCode::where('phone', $phone)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            return null;
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return null;
        }

        $otp->forceFill(['consumed_at' => now()])->save();

        $user = User::firstOrNew(['phone' => $phone]);
        $user->name ??= 'Traveller';
        $user->phone_verified_at = now();
        $user->save();

        return $user;
    }

    /**
     * Whether this caller may read their own code out of the API response.
     */
    private function shouldRevealCode(string $phone): bool
    {
        if (in_array($phone, config('hashbuddy.otp.test_numbers'), true)) {
            return true;
        }

        // The blanket flag is deliberately ignored in production.
        return (bool) config('hashbuddy.otp.debug') && ! app()->isProduction();
    }

    private function hasSmsProvider(): bool
    {
        return config('hashbuddy.otp.sms_driver') !== 'log';
    }

    private function deliver(string $phone, string $code): void
    {
        if (! $this->hasSmsProvider()) {
            Log::info('Hash Buddy OTP issued (no SMS provider)', ['phone' => $phone, 'code' => $code]);

            return;
        }

        // TODO: send via the configured provider (MSG91 / Gupshup / Twilio).
        throw new RideException(
            'SMS driver ['.config('hashbuddy.otp.sms_driver').'] is not implemented yet.',
            'sms_unavailable',
            503,
        );
    }
}
