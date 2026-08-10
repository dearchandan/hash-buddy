<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OtpRequestRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function requestOtp(OtpRequestRequest $request): JsonResponse
    {
        $result = $this->otp->issue($request->string('phone'));

        return response()->json([
            'message' => 'Verification code sent.',
            'expires_at' => $result['expires_at']->toIso8601String(),
            // Present only while HASHBUDDY_OTP_DEBUG is on.
            'debug_code' => $result['debug_code'],
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $user = $this->otp->verify($request->string('phone'), $request->string('code'));

        if (! $user) {
            return response()->json([
                'message' => 'That code is incorrect or has expired.',
                'error' => 'invalid_otp',
            ], 422);
        }

        $token = $user->createToken($request->string('device_name', 'mobile'))->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
            // A brand-new account has no name or gender yet.
            'needs_profile' => $user->name === 'Traveller',
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function updateMe(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return new UserResource($user);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
