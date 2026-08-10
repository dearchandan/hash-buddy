<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Phone-number login.
 *
 * No SMS gateway is wired up yet. In debug mode the code comes back in the API
 * response so the whole flow is drivable locally; swap `deliver()` for a real
 * provider before this meets a user.
 */
class OtpService
{
    public function issue(string $phone): array
    {
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
            'debug_code' => config('hashbuddy.otp.debug') ? $code : null,
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

    private function deliver(string $phone, string $code): void
    {
        // TODO: replace with an SMS provider (MSG91 / Gupshup / Twilio).
        Log::info('Hash Buddy OTP issued', ['phone' => $phone, 'code' => $code]);
    }
}
