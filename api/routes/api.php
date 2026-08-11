<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CallController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\RideGroupController;
use App\Http\Controllers\Api\RideRequestController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Public
    Route::get('zones', [ZoneController::class, 'index']);
    // Browse: areas people are already heading to, and the rides going there.
    Route::get('areas', [ZoneController::class, 'areas']);
    Route::get('zones/{zone}/open-rides', [ZoneController::class, 'openRides']);

    Route::middleware('throttle:6,1')->group(function () {
        Route::post('auth/otp', [AuthController::class, 'requestOtp']);
        Route::post('auth/verify', [AuthController::class, 'verifyOtp']);
    });

    // Authenticated
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::patch('me', [AuthController::class, 'updateMe']);

        Route::get('ride-requests', [RideRequestController::class, 'index']);
        Route::post('ride-requests', [RideRequestController::class, 'store']);
        Route::get('ride-requests/{rideRequest}', [RideRequestController::class, 'show']);
        Route::delete('ride-requests/{rideRequest}', [RideRequestController::class, 'destroy']);

        // Find mates
        Route::get('ride-requests/{rideRequest}/matches', [RideRequestController::class, 'matches']);
        Route::post('ride-requests/{rideRequest}/auto-match', [RideRequestController::class, 'autoMatch']);

        Route::get('groups', [RideGroupController::class, 'index']);
        Route::post('groups', [RideGroupController::class, 'store']);
        Route::get('groups/{rideGroup}', [RideGroupController::class, 'show']);

        // Join / leave a ride
        Route::post('groups/{rideGroup}/join', [RideGroupController::class, 'join']);
        // Join straight from the browse list, deriving the request from the ride.
        Route::post('groups/{rideGroup}/quick-join', [RideGroupController::class, 'quickJoin']);
        Route::post('groups/{rideGroup}/leave', [RideGroupController::class, 'leave']);

        // Push registration
        Route::post('me/devices', [DeviceController::class, 'store']);
        Route::delete('me/devices', [DeviceController::class, 'destroy']);

        // Chat, for travellers who already share a ride
        Route::get('messages/unread', [MessageController::class, 'unread']);
        Route::get('groups/{rideGroup}/messages', [MessageController::class, 'index']);
        Route::post('groups/{rideGroup}/messages/read', [MessageController::class, 'markRead']);
        // Throttled separately from the read path: polling every few seconds is
        // normal and must never be what exhausts the send budget.
        Route::post('groups/{rideGroup}/messages', [MessageController::class, 'store'])
            ->middleware('throttle:chat');

        // Voice calls
        Route::get('calls/ice-servers', [CallController::class, 'iceServers']);
        Route::get('groups/{rideGroup}/calls/current', [CallController::class, 'current']);
        Route::post('groups/{rideGroup}/calls', [CallController::class, 'start'])
            ->middleware('throttle:calls');
        Route::get('calls/{call}', [CallController::class, 'show']);
        Route::post('calls/{call}/accept', [CallController::class, 'accept']);
        Route::post('calls/{call}/decline', [CallController::class, 'decline']);
        Route::post('calls/{call}/hang-up', [CallController::class, 'hangUp']);
    });
});
