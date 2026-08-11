<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * Register this install for push.
     *
     * Called on every launch, not just the first: FCM rotates tokens on its own
     * schedule, and a client that only registers once eventually goes silent
     * with no error anywhere to explain it.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', 'string', 'in:android,ios'],
        ]);

        $hash = DeviceToken::hashFor($validated['token']);

        // Keyed on the token, not the user: handing a phone to someone else who
        // then signs in must move the token to them, or the previous owner
        // keeps receiving their messages.
        $device = DeviceToken::updateOrCreate(
            ['token_hash' => $hash],
            [
                'user_id' => $request->user()->id,
                'token' => $validated['token'],
                'platform' => $validated['platform'] ?? 'android',
                'last_used_at' => now(),
            ],
        );

        return response()->json([
            'message' => 'Device registered.',
            'id' => $device->id,
        ], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Called on sign-out, so a shared handset stops buzzing for someone who is
     * no longer signed in.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:4096']]);

        DeviceToken::where('token_hash', DeviceToken::hashFor($validated['token']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'Device removed.']);
    }
}
