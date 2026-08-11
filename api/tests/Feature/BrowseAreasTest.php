<?php

namespace Tests\Feature;

use App\Enums\CabService;
use App\Enums\RideGroupStatus;
use App\Models\RideGroup;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\Zone;
use App\Services\RideGroupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrowseAreasTest extends TestCase
{
    use RefreshDatabase;

    private Zone $koramangala;

    private Zone $whitefield;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-01 10:00');

        $this->koramangala = Zone::factory()->create(['name' => 'Koramangala', 'sedan_fare' => 1250, 'suv_fare' => 2000]);
        $this->whitefield = Zone::factory()->create(['name' => 'Whitefield', 'sedan_fare' => 1600, 'suv_fare' => 2400]);

        Sanctum::actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_areas_lists_only_places_with_a_ride_you_could_join(): void
    {
        $this->ride($this->koramangala);
        $this->ride($this->koramangala);

        $response = $this->getJson('/api/v1/areas')->assertOk();

        // Whitefield has no rides at all, so it is not an option worth showing.
        $this->assertSame(['Koramangala'], array_column($response->json('data'), 'name'));
        $response->assertJsonPath('data.0.open_rides_count', 2);
    }

    public function test_busier_areas_come_first(): void
    {
        $this->ride($this->whitefield);
        $this->ride($this->koramangala);
        $this->ride($this->koramangala);

        $names = array_column($this->getJson('/api/v1/areas')->json('data'), 'name');

        $this->assertSame(['Koramangala', 'Whitefield'], $names);
    }

    public function test_a_full_ride_is_not_offered(): void
    {
        $group = $this->ride($this->koramangala, maxSeats: 2);
        app(RideGroupService::class)->quickJoin($group, User::factory()->create());

        $this->assertTrue($group->refresh()->isFull());
        $this->getJson('/api/v1/areas')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_cancelled_ride_is_not_offered(): void
    {
        $group = $this->ride($this->koramangala);
        $group->forceFill(['status' => RideGroupStatus::Cancelled])->save();

        $this->getJson('/api/v1/areas')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_ride_whose_window_has_passed_is_not_offered(): void
    {
        $this->ride($this->koramangala, start: '08:00', end: '09:00');

        // Otherwise the browse screen silently fills with rides that left hours
        // ago, which is how a discovery surface becomes useless.
        $this->getJson('/api/v1/areas')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_areas_report_seats_and_the_next_departure(): void
    {
        $this->ride($this->koramangala, maxSeats: 3, start: '12:00', end: '12:40');
        $this->ride($this->koramangala, maxSeats: 2, start: '11:00', end: '11:40');

        $this->getJson('/api/v1/areas')
            ->assertOk()
            // 2 free in the three-seater plus 1 in the two-seater.
            ->assertJsonPath('data.0.seats_available', 3)
            ->assertJsonPath('data.0.next_departure', '2026-09-01T11:00:00+00:00');
    }

    public function test_areas_can_be_narrowed_to_one_terminal(): void
    {
        $this->ride($this->koramangala, terminal: 'T1');
        $this->ride($this->whitefield, terminal: 'T2');

        $names = array_column($this->getJson('/api/v1/areas?terminal=T1')->json('data'), 'name');

        $this->assertSame(['Koramangala'], $names);
    }

    public function test_the_listing_shows_what_you_need_to_pick_a_ride(): void
    {
        $host = User::factory()->create(['name' => 'Asha']);
        $this->ride($this->koramangala, user: $host, maxSeats: 3, fare: 1200, cab: CabService::Uber, spot: 'Gate 4 taxi bay');

        $ride = $this->getJson("/api/v1/zones/{$this->koramangala->id}/open-rides")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0');

        $this->assertSame(3, $ride['max_seats']);
        $this->assertSame(1, $ride['seats_taken']);
        $this->assertSame(2, $ride['seats_available']);
        $this->assertSame(1200, $ride['quoted_fare']);
        // Two aboard if you take a seat, so half each.
        $this->assertSame(600, $ride['fare_share']);
        $this->assertSame('uber', $ride['cab_service']);
        $this->assertSame('Uber', $ride['cab_service_label']);
        $this->assertSame('Gate 4 taxi bay', $ride['meeting_point']);
        $this->assertSame('Asha', $ride['members'][0]['user']['name']);
    }

    public function test_a_ride_with_no_fare_says_so_rather_than_guessing(): void
    {
        $this->ride($this->koramangala);

        $ride = $this->getJson("/api/v1/zones/{$this->koramangala->id}/open-rides")->json('data.0');

        // Someone who opened a ride the moment they landed has no fare to
        // share. Substituting the seeded zone estimate here would dress a guess
        // up as a quote from Ola.
        $this->assertNull($ride['quoted_fare']);
        $this->assertNull($ride['fare_share']);
        $this->assertNull($ride['cab_service']);
    }

    public function test_the_listing_never_exposes_a_phone_number(): void
    {
        $this->ride($this->koramangala, user: User::factory()->create(['phone' => '+919812345678']));

        $body = $this->getJson("/api/v1/zones/{$this->koramangala->id}/open-rides")->getContent();

        $this->assertStringNotContainsString('9812345678', $body);
        $this->assertStringNotContainsString('phone', $body);
    }

    public function test_quick_join_takes_a_seat_without_a_request_of_your_own(): void
    {
        $group = $this->ride($this->koramangala, maxSeats: 3);

        $me = User::factory()->create();
        Sanctum::actingAs($me);

        // The entire point: no terminal, no zone, no window. All of that is
        // already on the ride being joined.
        $this->postJson("/api/v1/groups/{$group->id}/quick-join", ['seats' => 1, 'luggage_count' => 2])
            ->assertOk()
            ->assertJsonPath('group.seats_taken', 2);

        $mine = RideRequest::where('user_id', $me->id)->firstOrFail();
        $this->assertSame($group->terminal, $mine->terminal);
        $this->assertSame($group->zone_id, $mine->zone_id);
        $this->assertSame(2, $mine->luggage_count);
        // Derived from the ride, so the overlap check can never be what fails.
        $this->assertTrue($mine->window_start->equalTo($group->window_start));
    }

    public function test_quick_join_defaults_to_one_seat_and_one_bag(): void
    {
        $group = $this->ride($this->koramangala, maxSeats: 3);
        Sanctum::actingAs($me = User::factory()->create());

        $this->postJson("/api/v1/groups/{$group->id}/quick-join")->assertOk();

        $mine = RideRequest::where('user_id', $me->id)->firstOrFail();
        $this->assertSame(1, $mine->seats);
        $this->assertSame(1, $mine->luggage_count);
    }

    public function test_quick_join_cannot_oversubscribe_the_last_seat(): void
    {
        $group = $this->ride($this->koramangala, maxSeats: 2);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/groups/{$group->id}/quick-join", ['seats' => 2])
            ->assertStatus(409)
            ->assertJsonPath('error', 'group_full');
    }

    public function test_a_failed_quick_join_leaves_no_orphan_request_behind(): void
    {
        $group = $this->ride($this->koramangala, maxSeats: 2);
        Sanctum::actingAs($me = User::factory()->create());

        $this->postJson("/api/v1/groups/{$group->id}/quick-join", ['seats' => 2])->assertStatus(409);

        // Rolled back with the join, or the traveller is left advertising a
        // trip they never agreed to take.
        $this->assertSame(0, RideRequest::where('user_id', $me->id)->count());
    }

    public function test_quick_join_still_respects_a_women_only_ride(): void
    {
        $host = User::factory()->create(['gender' => 'female']);
        $group = $this->ride($this->koramangala, user: $host, maxSeats: 3);
        $group->forceFill(['gender_policy' => 'women_only'])->save();

        Sanctum::actingAs(User::factory()->create(['gender' => 'male']));

        $this->postJson("/api/v1/groups/{$group->id}/quick-join")
            ->assertStatus(403)
            ->assertJsonPath('error', 'gender_policy');
    }

    public function test_a_fare_entered_on_the_form_ends_up_on_the_ride(): void
    {
        Sanctum::actingAs($me = User::factory()->create());

        $created = $this->postJson('/api/v1/ride-requests', [
            'terminal' => 'T1',
            'zone_id' => $this->koramangala->id,
            'window_start' => '2026-09-01T12:00:00Z',
            'window_end' => '2026-09-01T12:40:00Z',
            'quoted_fare' => 900,
            'cab_service' => 'ola',
            'meeting_point' => 'Gate 6, pillar 3',
        ])->assertCreated()->json('data.id');

        $group = $this->postJson('/api/v1/groups', ['ride_request_id' => $created])
            ->assertCreated()
            ->json('data');

        $this->assertSame(900, $group['quoted_fare']);
        $this->assertSame('ola', $group['cab_service']);
        $this->assertSame('Gate 6, pillar 3', $group['meeting_point']);
        // One aboard so far, so the host currently carries the whole fare.
        $this->assertSame(900, $group['fare_share']);
    }

    public function test_an_absurd_fare_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/ride-requests', [
            'terminal' => 'T1',
            'zone_id' => $this->koramangala->id,
            'window_start' => '2026-09-01T12:00:00Z',
            'window_end' => '2026-09-01T12:40:00Z',
            'quoted_fare' => 5000000,
        ])->assertStatus(422);
    }

    private function ride(
        Zone $zone,
        ?User $user = null,
        int $maxSeats = 2,
        string $terminal = 'T1',
        string $start = '12:00',
        string $end = '12:40',
        ?int $fare = null,
        ?CabService $cab = null,
        ?string $spot = null,
    ): RideGroup {
        $request = RideRequest::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'zone_id' => $zone->id,
            'terminal' => $terminal,
            'flight_number' => null,
            'seats' => 1,
            'luggage_count' => 1,
            'quoted_fare' => $fare,
            'cab_service' => $cab,
            'meeting_point' => $spot,
            'window_start' => Carbon::parse("2026-09-01 {$start}"),
            'window_end' => Carbon::parse("2026-09-01 {$end}"),
        ]);

        return app(RideGroupService::class)->createFromRequest($request, $maxSeats);
    }
}
