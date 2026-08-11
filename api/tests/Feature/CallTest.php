<?php

namespace Tests\Feature;

use App\Enums\CallStatus;
use App\Enums\MessageType;
use App\Models\CallSession;
use App\Models\RideGroup;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\Zone;
use App\Services\RideGroupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CallTest extends TestCase
{
    use RefreshDatabase;

    private const OFFER = 'v=0
o=- 1 2 IN IP4 127.0.0.1
a=candidate:1 1 udp 2130706431 10.0.0.1 54321 typ host';

    private const ANSWER = 'v=0
o=- 3 4 IN IP4 127.0.0.1
a=candidate:2 1 udp 2130706431 10.0.0.2 54322 typ host';

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create(['sedan_fare' => 1250, 'suv_fare' => 2000]);
    }

    public function test_a_traveller_can_ring_their_ride_mate(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/calls", [
            'callee_id' => $joiner->id,
            'offer_sdp' => self::OFFER,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ringing')
            ->assertJsonPath('data.is_caller', true);

        $this->assertDatabaseHas('call_sessions', [
            'ride_group_id' => $group->id,
            'caller_id' => $host->id,
            'callee_id' => $joiner->id,
            'status' => 'ringing',
        ]);
    }

    public function test_each_side_sees_only_the_description_it_needs(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $call = $this->ring($group, $host, $joiner);

        // The callee needs the offer and must not be handed the answer.
        Sanctum::actingAs($joiner);
        $this->getJson("/api/v1/calls/{$call->id}")
            ->assertOk()
            ->assertJsonPath('data.offer_sdp', self::OFFER)
            ->assertJsonMissingPath('data.answer_sdp');

        // The caller is the mirror image.
        Sanctum::actingAs($host);
        $this->getJson("/api/v1/calls/{$call->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.offer_sdp');
    }

    public function test_accepting_hands_the_answer_back_to_the_caller(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $call = $this->ring($group, $host, $joiner);

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/calls/{$call->id}/accept", ['answer_sdp' => self::ANSWER])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        Sanctum::actingAs($host);
        $this->getJson("/api/v1/groups/{$group->id}/calls/current")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.answer_sdp', self::ANSWER);
    }

    public function test_you_cannot_call_someone_who_is_not_on_the_ride(): void
    {
        [$group, $host] = $this->sharedRide();
        $stranger = User::factory()->create();

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/calls", [
            'callee_id' => $stranger->id,
            'offer_sdp' => self::OFFER,
        ])
            ->assertStatus(404)
            ->assertJsonPath('error', 'call_target_not_member');
    }

    public function test_a_stranger_cannot_start_a_call_on_someone_elses_ride(): void
    {
        [$group, , $joiner] = $this->sharedRide();
        $stranger = User::factory()->create();

        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/groups/{$group->id}/calls", [
            'callee_id' => $joiner->id,
            'offer_sdp' => self::OFFER,
        ])->assertNotFound();
    }

    public function test_you_cannot_call_yourself(): void
    {
        [$group, $host] = $this->sharedRide();

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/calls", [
            'callee_id' => $host->id,
            'offer_sdp' => self::OFFER,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'cannot_call_yourself');
    }

    public function test_only_one_call_can_be_live_on_a_ride(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $this->ring($group, $host, $joiner);

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/groups/{$group->id}/calls", [
            'callee_id' => $host->id,
            'offer_sdp' => self::OFFER,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'call_already_live');
    }

    public function test_a_third_party_cannot_accept_or_end_a_call(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $call = $this->ring($group, $host, $joiner);

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/calls/{$call->id}/accept", ['answer_sdp' => self::ANSWER])->assertNotFound();
        $this->postJson("/api/v1/calls/{$call->id}/decline")->assertNotFound();
        $this->postJson("/api/v1/calls/{$call->id}/hang-up")->assertNotFound();
    }

    public function test_declining_ends_the_call(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $call = $this->ring($group, $host, $joiner);

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/calls/{$call->id}/decline")
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.end_reason', 'declined');

        // Nothing is live afterwards, so the ride is free for another call.
        Sanctum::actingAs($host);
        $this->getJson("/api/v1/groups/{$group->id}/calls/current")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_hanging_up_ends_an_accepted_call(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $call = $this->ring($group, $host, $joiner);

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/calls/{$call->id}/accept", ['answer_sdp' => self::ANSWER])->assertOk();

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/calls/{$call->id}/hang-up")
            ->assertOk()
            ->assertJsonPath('data.status', 'ended')
            ->assertJsonPath('data.end_reason', 'hung_up');
    }

    public function test_a_call_that_is_already_over_cannot_be_accepted(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $call = $this->ring($group, $host, $joiner);

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/calls/{$call->id}/decline")->assertOk();

        $this->postJson("/api/v1/calls/{$call->id}/accept", ['answer_sdp' => self::ANSWER])
            ->assertStatus(409)
            ->assertJsonPath('error', 'call_not_ringing');
    }

    public function test_an_unanswered_call_becomes_missed_and_leaves_a_note_in_the_chat(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $call = $this->ring($group, $host, $joiner);

        $this->travel((int) config('hashbuddy.calls.ring_seconds') + 5)->seconds();

        Sanctum::actingAs($joiner);
        $this->getJson("/api/v1/groups/{$group->id}/calls/current")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSame(CallStatus::Missed, $call->refresh()->status);
        $this->assertSame('no_answer', $call->end_reason);

        // The one call outcome worth a trace: it is the only sign someone tried
        // to reach you and could not.
        $this->assertTrue(
            $group->messages()
                ->where('type', MessageType::System)
                ->where('body', 'like', '%tried to call%')
                ->exists(),
        );
    }

    public function test_ice_servers_include_short_lived_turn_credentials(): void
    {
        config()->set('hashbuddy.calls.turn.urls', ['turn:turn.example.com:3478']);
        config()->set('hashbuddy.calls.turn.secret', 'a-shared-secret');
        config()->set('hashbuddy.calls.turn.credential_ttl_seconds', 600);

        [, $host] = $this->sharedRide();
        Sanctum::actingAs($host);

        $servers = $this->getJson('/api/v1/calls/ice-servers')->assertOk()->json('data.ice_servers');

        $turn = collect($servers)->firstWhere('username');
        $this->assertNotNull($turn);

        // The username is the expiry, so credentials lifted from a handset stop
        // relaying traffic within the hour rather than for ever.
        $this->assertGreaterThan(time(), (int) $turn['username']);
        $this->assertLessThanOrEqual(time() + 600, (int) $turn['username']);
        $this->assertSame(
            base64_encode(hash_hmac('sha1', $turn['username'], 'a-shared-secret', true)),
            $turn['credential'],
        );
    }

    public function test_turn_is_omitted_rather_than_sent_half_configured(): void
    {
        config()->set('hashbuddy.calls.turn.urls', ['turn:turn.example.com:3478']);
        config()->set('hashbuddy.calls.turn.secret', null);

        [, $host] = $this->sharedRide();
        Sanctum::actingAs($host);

        $servers = $this->getJson('/api/v1/calls/ice-servers')->assertOk()->json('data.ice_servers');

        $this->assertNull(collect($servers)->firstWhere('username'));
    }

    public function test_calling_can_be_switched_off(): void
    {
        config()->set('hashbuddy.calls.enabled', false);

        [$group, $host, $joiner] = $this->sharedRide();

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/calls", [
            'callee_id' => $joiner->id,
            'offer_sdp' => self::OFFER,
        ])
            ->assertStatus(503)
            ->assertJsonPath('error', 'calls_disabled');
    }

    private function ring(RideGroup $group, User $caller, User $callee): CallSession
    {
        Sanctum::actingAs($caller);

        $id = $this->postJson("/api/v1/groups/{$group->id}/calls", [
            'callee_id' => $callee->id,
            'offer_sdp' => self::OFFER,
        ])->assertCreated()->json('data.id');

        return CallSession::findOrFail($id);
    }

    /**
     * @return array{0: RideGroup, 1: User, 2: User}
     */
    private function sharedRide(): array
    {
        $host = User::factory()->create();
        $group = app(RideGroupService::class)->createFromRequest($this->request($host), 2);

        $joiner = User::factory()->create();
        app(RideGroupService::class)->join($group, $this->request($joiner));

        return [$group->refresh(), $host, $joiner];
    }

    private function request(?User $user = null): RideRequest
    {
        return RideRequest::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'zone_id' => $this->zone->id,
            'terminal' => 'T2',
            'flight_number' => null,
            'luggage_count' => 1,
            'seats' => 1,
            'window_start' => Carbon::parse('2026-09-01 12:00'),
            'window_end' => Carbon::parse('2026-09-01 12:40'),
        ]);
    }
}
