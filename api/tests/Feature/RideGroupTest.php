<?php

namespace Tests\Feature;

use App\Enums\MemberRole;
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

class RideGroupTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create(['sedan_fare' => 1250, 'suv_fare' => 2000]);
    }

    public function test_a_traveller_can_join_a_ride_and_take_a_seat(): void
    {
        $group = $this->group(maxSeats: 3);

        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])
            ->assertOk()
            ->assertJsonPath('group.seats_taken', 2)
            ->assertJsonPath('group.status', 'forming');

        $this->assertSame(RideRequestStatus::Matched, $mine->refresh()->status);
        $this->assertSame($group->id, $mine->ride_group_id);
    }

    public function test_the_group_window_narrows_to_the_overlap(): void
    {
        $group = $this->group(maxSeats: 3);

        $me = User::factory()->create();
        $mine = $this->request(user: $me, attributes: [
            'window_start' => $this->at('12:10'),
            'window_end' => $this->at('13:00'),
        ]);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])->assertOk();

        $group->refresh();
        $this->assertTrue($group->window_start->equalTo($this->at('12:10')));
        $this->assertTrue($group->window_end->equalTo($this->at('12:40')));
    }

    public function test_a_group_locks_once_the_last_seat_goes(): void
    {
        $group = $this->group(maxSeats: 2);

        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])
            ->assertOk()
            ->assertJsonPath('group.status', 'locked')
            ->assertJsonPath('group.seats_available', 0);
    }

    public function test_joining_a_full_group_is_refused(): void
    {
        $group = $this->group(maxSeats: 1);

        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])
            ->assertStatus(409)
            ->assertJsonPath('error', 'group_closed');

        $this->assertSame(RideRequestStatus::Open, $mine->refresh()->status);
    }

    public function test_joining_the_same_ride_twice_is_refused(): void
    {
        $group = $this->group(maxSeats: 3);

        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])->assertOk();

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])
            ->assertStatus(409)
            ->assertJsonPath('error', 'already_member');
    }

    public function test_you_cannot_join_using_someone_elses_request(): void
    {
        $group = $this->group(maxSeats: 3);
        $theirs = $this->request();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $theirs->id])
            ->assertForbidden();
    }

    public function test_a_traveller_whose_window_drifted_apart_is_refused(): void
    {
        $group = $this->group(maxSeats: 3);

        $me = User::factory()->create();
        $mine = $this->request(user: $me, attributes: [
            'window_start' => $this->at('12:35'),
            'window_end' => $this->at('13:15'),
        ]);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])
            ->assertStatus(422)
            ->assertJsonPath('error', 'window_mismatch');
    }

    public function test_leaving_frees_the_seat_and_puts_the_request_back_on_the_market(): void
    {
        $group = $this->group(maxSeats: 3);

        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])->assertOk();
        $this->postJson("/api/v1/groups/{$group->id}/leave")
            ->assertOk()
            ->assertJsonPath('group.seats_taken', 1);

        $mine->refresh();
        $this->assertSame(RideRequestStatus::Open, $mine->status);
        $this->assertNull($mine->ride_group_id);
    }

    public function test_a_locked_group_reopens_when_someone_leaves(): void
    {
        $group = $this->group(maxSeats: 2);

        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/groups/{$group->id}/join", ['ride_request_id' => $mine->id])->assertOk();
        $this->assertSame(RideGroupStatus::Locked, $group->refresh()->status);

        $this->postJson("/api/v1/groups/{$group->id}/leave")->assertOk();
        $this->assertSame(RideGroupStatus::Forming, $group->refresh()->status);
    }

    public function test_when_the_host_leaves_the_next_traveller_takes_over(): void
    {
        $host = User::factory()->create();
        $hostRequest = $this->request(user: $host);
        $group = app(RideGroupService::class)->createFromRequest($hostRequest, maxSeats: 3);

        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        app(RideGroupService::class)->join($group, $mine);

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/leave")->assertOk();

        $group->refresh();
        $this->assertSame($me->id, $group->created_by);
        $this->assertSame(MemberRole::Host, $group->activeMembers()->first()->role);
    }

    public function test_the_group_is_cancelled_when_the_last_traveller_leaves(): void
    {
        $host = User::factory()->create();
        $hostRequest = $this->request(user: $host);
        $group = app(RideGroupService::class)->createFromRequest($hostRequest, maxSeats: 3);

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/leave")->assertOk();

        $this->assertSame(RideGroupStatus::Cancelled, $group->refresh()->status);
        $this->assertSame(RideRequestStatus::Open, $hostRequest->refresh()->status);
    }

    public function test_auto_match_takes_an_open_seat_when_one_exists(): void
    {
        $group = $this->group(maxSeats: 3);

        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/ride-requests/{$mine->id}/auto-match")
            ->assertOk()
            ->assertJsonPath('action', 'joined')
            ->assertJsonPath('group.id', $group->id);
    }

    public function test_auto_match_opens_a_new_ride_when_nothing_matches(): void
    {
        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        Sanctum::actingAs($me);

        $this->postJson("/api/v1/ride-requests/{$mine->id}/auto-match")
            ->assertCreated()
            ->assertJsonPath('action', 'created')
            ->assertJsonPath('group.seats_taken', 1);
    }

    /**
     * A group hosted by someone else, ready to be joined.
     */
    private function group(int $maxSeats): RideGroup
    {
        return app(RideGroupService::class)->createFromRequest($this->request(), $maxSeats);
    }

    private function request(?User $user = null, array $attributes = []): RideRequest
    {
        return RideRequest::factory()->create(array_merge([
            'user_id' => ($user ?? User::factory()->create())->id,
            'zone_id' => $this->zone->id,
            'terminal' => 'T2',
            'flight_number' => null,
            'luggage_count' => 1,
            'window_start' => $this->at('12:00'),
            'window_end' => $this->at('12:40'),
        ], $attributes));
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse('2026-09-01 '.$time);
    }
}
