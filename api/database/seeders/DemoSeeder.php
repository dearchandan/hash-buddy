<?php

namespace Database\Seeders;

use App\Enums\Gender;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\Zone;
use App\Services\RideGroupService;
use Illuminate\Database\Seeder;

/**
 * Enough traffic on the Koramangala and HSR corridors to see matching work.
 *
 * Sign in as +919800000001 — with HASHBUDDY_OTP_DEBUG on, the code comes back
 * in the response to POST /api/v1/auth/otp.
 */
class DemoSeeder extends Seeder
{
    public function run(RideGroupService $groups): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder skipped in production.');

            return;
        }

        $koramangala = Zone::where('slug', 'koramangala')->firstOrFail();
        $hsr = Zone::where('slug', 'hsr-layout')->firstOrFail();

        $you = User::updateOrCreate(
            ['phone' => '+919800000001'],
            ['name' => 'Chandan', 'gender' => Gender::Male, 'phone_verified_at' => now()],
        );

        $cast = [
            ['+919800000002', 'Priya', Gender::Female, 4.8, 12],
            ['+919800000003', 'Rahul', Gender::Male, 4.6, 8],
            ['+919800000004', 'Aisha', Gender::Female, 5.0, 3],
            ['+919800000005', 'Vikram', Gender::Male, 4.2, 21],
        ];

        $people = collect($cast)->map(fn ($p) => User::updateOrCreate(
            ['phone' => $p[0]],
            [
                'name' => $p[1],
                'gender' => $p[2],
                'rating_avg' => $p[3],
                'rating_count' => $p[4],
                // Ratings come from completed rides, so the two move together —
                // otherwise the profile reads "4.6 stars, 0 rides".
                'rides_completed' => $p[4],
                'phone_verified_at' => now(),
            ],
        ));

        // Everyone lands in the same arrival bank, timed so the window the app's
        // form defaults to always overlaps by more than the ten-minute floor.
        //
        // That form offers to leave at the next half hour and waits 30 minutes,
        // so its window sits somewhere in [now, now + 60]. Starting the seeded
        // rides 15 minutes out and running them a full hour covers every
        // position that window can land in. Seed them any later and a demo that
        // follows the natural flow finds nobody, which reads as a broken matcher
        // rather than data placed out of reach.
        $base = now()->addMinutes(15)->startOfMinute();
        $window = 60;

        // The busiest corridor sits on T1, the terminal the app's form selects by
        // default, so the shortest path through the app finds both a joinable
        // group and a lone traveller.
        $hostRequest = RideRequest::create([
            'user_id' => $people[1]->id,
            'terminal' => 'T1',
            'zone_id' => $koramangala->id,
            'flight_number' => '6E2134',
            'window_start' => $base,
            'window_end' => $base->copy()->addMinutes($window),
            'seats' => 1,
            'luggage_count' => 1,
            'drop_landmark' => 'Forum Mall',
        ]);
        $groups->createFromRequest($hostRequest, maxSeats: 3, meetingPoint: 'T1 Arrivals, Pillar 4');

        // Lone travellers still looking.
        RideRequest::create([
            'user_id' => $people[0]->id,
            'terminal' => 'T1',
            'zone_id' => $koramangala->id,
            'flight_number' => 'AI2846',
            'window_start' => $base->copy()->addMinutes(10),
            'window_end' => $base->copy()->addMinutes($window),
            'seats' => 1,
            'luggage_count' => 2,
            'drop_landmark' => 'Sony World Signal',
        ]);

        RideRequest::create([
            'user_id' => $people[2]->id,
            'terminal' => 'T2',
            'zone_id' => $hsr->id,
            'window_start' => $base->copy()->addMinutes(15),
            'window_end' => $base->copy()->addMinutes($window),
            'seats' => 1,
            'luggage_count' => 1,
            'gender_preference' => 'women_only',
        ]);

        RideRequest::create([
            'user_id' => $people[3]->id,
            'terminal' => 'T1',
            'zone_id' => $hsr->id,
            'window_start' => $base->copy()->addMinutes(5),
            'window_end' => $base->copy()->addMinutes($window),
            'seats' => 1,
            'luggage_count' => 1,
        ]);

        $this->command?->info("Demo data ready. Sign in as {$you->phone} (OTP is returned in the API response).");
    }
}
