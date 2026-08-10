<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RideGroupController;
use App\Http\Controllers\Api\RideRequestController;
use App\Http\Controllers\Api\ZoneController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Public
    Route::get('zones', [ZoneController::class, 'index']);

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
        Route::post('groups/{rideGroup}/leave', [RideGroupController::class, 'leave']);
    });
});
