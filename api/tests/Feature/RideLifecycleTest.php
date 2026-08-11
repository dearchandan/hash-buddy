<?php

namespace Tests\Feature;

use App\Enums\RideGroupStatus;
use App\Enums\RideRequestStatus;
use App\Models\RideGroup;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\Zone;
use App\Services\RideGroupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RideLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-01 10:00');
        $this->zone = Zone::factory()->create(['sedan_fare' => 1250, 'suv_fare' => 2000]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------ duplicates

    public function test_leaving_a_ride_you_browsed_into_does_not_leave_a_request_behind(): void
    {
        $group = $this->ride(maxSeats: 3);
        $me = User::factory()->create();
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/quick-join")->assertOk();
        $this->postJson("/api/v1/groups/{$group->id}/leave")->assertOk();

        // The request was derived to take that seat; the traveller never
        // described a trip, so leaving must not leave them advertising one.
        $this->assertSame(0, RideRequest::where('user_id', $me->id)
            ->where('status', RideRequestStatus::Open)->count());
    }

    public function test_quick_joining_and_leaving_twice_does_not_accumulate_requests(): void
    {
        $first = $this->ride(maxSeats: 3);
        $second = $this->ride(maxSeats: 3);
        $me = User::factory()->create();
        Sanctum::actingAs($me);

        foreach ([$first, $second] as $group) {
            $this->postJson("/api/v1/groups/{$group->id}/quick-join")->assertOk();
            $this->postJson("/api/v1/groups/{$group->id}/leave")->assertOk();
        }

        // This is exactly how the duplicate entries appeared on the home screen.
        $this->assertSame(0, RideRequest::where('user_id', $me->id)
            ->where('status', RideRequestStatus::Open)->count());
    }

    public function test_a_request_you_typed_still_reopens_when_you_leave(): void
    {
        $group = $this->ride(maxSeats: 3);
        $me = User::factory()->create();
        $mine = $this->request($me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])->assertOk();
        $this->postJson("/api/v1/groups/{$group->id}/leave")->assertOk();

        // Unchanged behaviour: you asked to find a ride, so keep looking.
        $this->assertSame(RideRequestStatus::Open, $mine->refresh()->status);
    }

    public function test_the_same_trip_cannot_be_requested_twice(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $payload = [
            'terminal' => 'T1',
            'zone_id' => $this->zone->id,
            'window_start' => '2026-09-01T12:00:00Z',
            'window_end' => '2026-09-01T12:40:00Z',
        ];

        $this->postJson('/api/v1/ride-requests', $payload)->assertCreated();
        $this->postJson('/api/v1/ride-requests', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error', 'duplicate_request');
    }

    public function test_a_different_window_is_not_a_duplicate(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ride-requests', [
            'terminal' => 'T1',
            'zone_id' => $this->zone->id,
            'window_start' => '2026-09-01T12:00:00Z',
            'window_end' => '2026-09-01T12:40:00Z',
        ])->assertCreated();

        // Overlapping windows are legitimate — people keep their options open.
        $this->postJson('/api/v1/ride-requests', [
            'terminal' => 'T1',
            'zone_id' => $this->zone->id,
            'window_start' => '2026-09-01T12:20:00Z',
            'window_end' => '2026-09-01T13:00:00Z',
        ])->assertCreated();
    }

    // ---------------------------------------------------------- closing a ride

    public function test_the_host_can_close_a_ride_they_opened(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3);
        Sanctum::actingAs($host);

        $this->postJson("/api/v1/groups/{$group->id}/cancel")
            ->assertOk()
            ->assertJsonPath('group.status', 'cancelled');

        $this->assertSame(RideGroupStatus::Cancelled, $group->refresh()->status);
    }

    public function test_a_closed_ride_disappears_from_the_home_screen(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3);
        Sanctum::actingAs($host);

        $this->getJson('/api/v1/groups')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/groups/{$group->id}/cancel")->assertOk();

        $this->getJson('/api/v1/groups')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_closed_ride_disappears_from_browse(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3);
        Sanctum::actingAs($host);

        $this->postJson("/api/v1/groups/{$group->id}/cancel")->assertOk();

        $this->getJson('/api/v1/areas')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/zones/{$this->zone->id}/open-rides")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_closing_puts_everyone_elses_request_back_on_the_market(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3);

        $other = User::factory()->create();
        $theirs = $this->request($other);
        app(RideGroupService::class)->join($group, $theirs);

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/cancel")->assertOk();

        // Stranding someone silently is the failure mode worth avoiding here.
        $this->assertSame(RideRequestStatus::Open, $theirs->refresh()->status);
        $this->assertNull($theirs->ride_group_id);
    }

    public function test_a_passenger_cannot_close_someone_elses_ride(): void
    {
        $group = $this->ride(maxSeats: 3);
        $other = User::factory()->create();
        app(RideGroupService::class)->join($group, $this->request($other));

        Sanctum::actingAs($other);

        $this->postJson("/api/v1/groups/{$group->id}/cancel")
            ->assertStatus(403)
            ->assertJsonPath('error', 'host_only');
    }

    public function test_a_ride_cannot_be_closed_twice(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3);
        Sanctum::actingAs($host);

        $this->postJson("/api/v1/groups/{$group->id}/cancel")->assertOk();
        $this->postJson("/api/v1/groups/{$group->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error', 'ride_already_closed');
    }

    // -------------------------------------------------------- completing a ride

    public function test_the_host_can_complete_a_ride_that_never_filled_up(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3, fare: 1200);

        $other = User::factory()->create();
        app(RideGroupService::class)->join($group, $this->request($other));

        Sanctum::actingAs($host);

        // Two of the three seats went. The third traveller never showed, and
        // waiting for them is not a reason to be unable to leave.
        $response = $this->postJson("/api/v1/groups/{$group->id}/complete")
            ->assertOk()
            ->assertJsonPath('group.status', 'completed');

        // Split between the two who actually came, not the three seats booked.
        $this->assertSame(600, $response->json('group.fare_share'));
        $this->assertSame(2, $group->refresh()->max_seats);
    }

    public function test_completing_a_full_ride_splits_by_everyone_aboard(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3, fare: 1200);

        foreach (range(1, 2) as $i) {
            app(RideGroupService::class)->join($group, $this->request(User::factory()->create()));
        }

        Sanctum::actingAs($host);

        $this->postJson("/api/v1/groups/{$group->id}/complete")
            ->assertOk()
            ->assertJsonPath('group.fare_share', 400);
    }

    public function test_a_completed_ride_leaves_the_home_screen_and_browse(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3);
        Sanctum::actingAs($host);

        $this->postJson("/api/v1/groups/{$group->id}/complete")->assertOk();

        $this->getJson('/api/v1/groups')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/zones/{$this->zone->id}/open-rides")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_nobody_can_join_a_completed_ride(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3);
        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/complete")->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/groups/{$group->id}/quick-join")
            ->assertStatus(409)
            ->assertJsonPath('error', 'group_closed');
    }

    public function test_a_passenger_cannot_complete_the_ride(): void
    {
        $group = $this->ride(maxSeats: 3);
        $other = User::factory()->create();
        app(RideGroupService::class)->join($group, $this->request($other));

        Sanctum::actingAs($other);

        $this->postJson("/api/v1/groups/{$group->id}/complete")
            ->assertStatus(403)
            ->assertJsonPath('error', 'host_only');
    }

    public function test_the_ride_screen_says_whether_you_are_the_host(): void
    {
        $host = User::factory()->create();
        $group = $this->ride(user: $host, maxSeats: 3);
        $other = User::factory()->create();
        app(RideGroupService::class)->join($group, $this->request($other));

        Sanctum::actingAs($host);
        $this->getJson("/api/v1/groups/{$group->id}")->assertOk()->assertJsonPath('data.is_host', true);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/groups/{$group->id}")->assertOk()->assertJsonPath('data.is_host', false);
    }

    private function ride(?User $user = null, int $maxSeats = 2, ?int $fare = null): RideGroup
    {
        return app(RideGroupService::class)->createFromRequest(
            $this->request($user, $fare),
            $maxSeats,
        );
    }

    private function request(?User $user = null, ?int $fare = null): RideRequest
    {
        return RideRequest::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'zone_id' => $this->zone->id,
            'terminal' => 'T1',
            'flight_number' => null,
            'seats' => 1,
            'luggage_count' => 1,
            'quoted_fare' => $fare,
            'window_start' => Carbon::parse('2026-09-01 12:00'),
            'window_end' => Carbon::parse('2026-09-01 12:40'),
        ]);
    }
}
