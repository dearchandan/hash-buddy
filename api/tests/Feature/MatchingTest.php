<?php

namespace Tests\Feature;

use App\Enums\GenderPolicy;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\Zone;
use App\Services\MatchingService;
use App\Services\RideGroupService;
use App\Support\MatchCandidate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchingTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    private MatchingService $matching;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create(['sedan_fare' => 1250, 'suv_fare' => 2000]);
        $this->matching = app(MatchingService::class);
    }

    public function test_it_matches_a_lone_traveller_going_the_same_way(): void
    {
        $mine = $this->request();
        $theirs = $this->request();

        $matches = $this->matching->findMatches($mine);

        $this->assertCount(1, $matches);
        $this->assertSame(MatchCandidate::TYPE_TRAVELLER, $matches[0]->type);
        $this->assertSame($theirs->id, $matches[0]->rideRequest->id);
        $this->assertSame(40, $matches[0]->overlapMinutes);
    }

    public function test_it_ignores_a_traveller_heading_to_another_zone(): void
    {
        $mine = $this->request();
        $this->request(['zone_id' => Zone::factory()->create()->id]);

        $this->assertCount(0, $this->matching->findMatches($mine));
    }

    public function test_it_ignores_a_traveller_leaving_another_terminal(): void
    {
        $mine = $this->request(['terminal' => 'T2']);
        $this->request(['terminal' => 'T1']);

        $this->assertCount(0, $this->matching->findMatches($mine));
    }

    public function test_it_ignores_windows_that_barely_touch(): void
    {
        // Five minutes of overlap, against a ten minute floor.
        $mine = $this->request();
        $this->request([
            'window_start' => $this->at('12:35'),
            'window_end' => $this->at('13:15'),
        ]);

        $this->assertCount(0, $this->matching->findMatches($mine));
    }

    public function test_it_matches_a_group_that_still_has_a_seat(): void
    {
        $host = $this->request();
        $group = app(RideGroupService::class)->createFromRequest($host, maxSeats: 3);

        $mine = $this->request();
        $matches = $this->matching->findMatches($mine);

        $this->assertCount(1, $matches);
        $this->assertSame(MatchCandidate::TYPE_GROUP, $matches[0]->type);
        $this->assertSame($group->id, $matches[0]->group->id);
    }

    public function test_it_ignores_a_full_group(): void
    {
        $host = $this->request();
        app(RideGroupService::class)->createFromRequest($host, maxSeats: 1);

        $this->assertCount(0, $this->matching->findMatches($this->request()));
    }

    public function test_it_never_matches_you_with_yourself(): void
    {
        $me = User::factory()->create();
        $mine = $this->request(user: $me);
        $this->request(user: $me);

        $this->assertCount(0, $this->matching->findMatches($mine));
    }

    public function test_a_women_only_request_does_not_match_a_group_open_to_everyone(): void
    {
        $host = $this->request(user: User::factory()->man()->create());
        app(RideGroupService::class)->createFromRequest($host, maxSeats: 3);

        $mine = $this->request(
            ['gender_preference' => GenderPolicy::WomenOnly],
            User::factory()->woman()->create(),
        );

        $this->assertCount(0, $this->matching->findMatches($mine));
    }

    public function test_a_man_does_not_match_a_women_only_group(): void
    {
        $host = $this->request(
            ['gender_preference' => GenderPolicy::WomenOnly],
            User::factory()->woman()->create(),
        );
        app(RideGroupService::class)->createFromRequest($host, maxSeats: 3);

        $mine = $this->request(user: User::factory()->man()->create());

        $this->assertCount(0, $this->matching->findMatches($mine));
    }

    public function test_it_ranks_a_fuller_overlap_above_a_marginal_one(): void
    {
        $mine = $this->request();

        $marginal = $this->request([
            'window_start' => $this->at('12:25'),
            'window_end' => $this->at('13:05'),
        ]);
        $perfect = $this->request();

        $matches = $this->matching->findMatches($mine);

        $this->assertCount(2, $matches);
        $this->assertSame($perfect->id, $matches[0]->rideRequest->id);
        $this->assertSame($marginal->id, $matches[1]->rideRequest->id);
        $this->assertGreaterThan($matches[1]->score, $matches[0]->score);
    }

    public function test_a_shared_flight_number_lifts_the_score(): void
    {
        $mine = $this->request(['flight_number' => 'AI2846']);
        $this->request(['flight_number' => 'AI2846']);

        $sameFlight = $this->matching->findMatches($mine)[0];

        $this->assertTrue($sameFlight->sameFlight);

        RideRequest::query()->whereKeyNot($mine->id)->update(['flight_number' => '6E1111']);
        $otherFlight = $this->matching->findMatches($mine)[0];

        $this->assertFalse($otherFlight->sameFlight);
        $this->assertGreaterThan($otherFlight->score, $sameFlight->score);
    }

    private function request(array $attributes = [], ?User $user = null): RideRequest
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
