<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\RideGroup;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\Zone;
use App\Push\PushSender;
use App\Services\RideGroupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\RecordingPushSender;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    private RecordingPushSender $push;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create(['sedan_fare' => 1250, 'suv_fare' => 2000]);

        $this->push = new RecordingPushSender;
        $this->app->instance(PushSender::class, $this->push);
    }

    public function test_a_device_registers_for_push(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/devices', ['token' => 'fcm-token-abc'])
            ->assertCreated();

        $this->assertDatabaseHas('device_tokens', [
            'user_id' => $user->id,
            'token_hash' => DeviceToken::hashFor('fcm-token-abc'),
        ]);
    }

    public function test_re_registering_the_same_token_updates_rather_than_duplicates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/devices', ['token' => 'fcm-token-abc'])->assertCreated();
        $this->postJson('/api/v1/me/devices', ['token' => 'fcm-token-abc'])->assertOk();

        $this->assertSame(1, DeviceToken::count());
    }

    public function test_a_handset_that_changes_hands_stops_buzzing_for_the_previous_owner(): void
    {
        $first = User::factory()->create();
        Sanctum::actingAs($first);
        $this->postJson('/api/v1/me/devices', ['token' => 'shared-handset'])->assertCreated();

        $second = User::factory()->create();
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/me/devices', ['token' => 'shared-handset'])->assertOk();

        $this->assertSame(1, DeviceToken::count());
        $this->assertSame(0, $first->deviceTokens()->count());
        $this->assertSame(1, $second->deviceTokens()->count());
    }

    public function test_signing_out_removes_only_your_own_registration(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        Sanctum::actingAs($theirs);
        $this->postJson('/api/v1/me/devices', ['token' => 'their-handset'])->assertCreated();

        Sanctum::actingAs($mine);
        $this->postJson('/api/v1/me/devices', ['token' => 'my-handset'])->assertCreated();
        $this->deleteJson('/api/v1/me/devices', ['token' => 'their-handset'])->assertOk();

        // Not mine to delete, so it survives.
        $this->assertSame(1, $theirs->deviceTokens()->count());

        $this->deleteJson('/api/v1/me/devices', ['token' => 'my-handset'])->assertOk();
        $this->assertSame(0, $mine->deviceTokens()->count());
    }

    public function test_joining_a_ride_notifies_the_people_already_aboard(): void
    {
        $host = User::factory()->create();
        $group = app(RideGroupService::class)->createFromRequest($this->request($host), 2);

        $joiner = User::factory()->create();
        app(RideGroupService::class)->join($group, $this->request($joiner));

        // This is the notification that makes matching work at all.
        $this->assertContains('ride.joined', $this->push->typesTo($host->id));
        // The person who just tapped join already knows.
        $this->assertSame([], $this->push->typesTo($joiner->id));
    }

    public function test_a_chat_message_notifies_the_other_traveller_only(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $this->push->sent = [];

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'At gate 4.'])->assertCreated();

        $this->assertSame(['chat.message'], $this->push->typesTo($host->id));
        $this->assertSame([], $this->push->typesTo($joiner->id));

        $message = $this->push->to($host->id)[0];
        $this->assertSame($joiner->name, $message->title);
        $this->assertSame('At gate 4.', $message->body);
        $this->assertSame((string) $group->id, $message->payload()['ride_group_id']);
    }

    public function test_a_long_message_is_truncated_in_the_banner(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => str_repeat('x', 400)]);

        $banner = $this->push->to($host->id)[0]->body;
        $this->assertLessThanOrEqual(125, strlen((string) $banner));
    }

    public function test_a_call_invite_is_silent_so_it_leaves_nothing_in_the_tray(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();
        $this->push->sent = [];

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/calls", [
            'callee_id' => $joiner->id,
            'offer_sdp' => 'v=0',
        ])->assertCreated();

        $invite = $this->push->to($joiner->id)[0];

        $this->assertSame('call.incoming', $invite->type);
        // A banner would still be sitting there after the call was answered.
        $this->assertTrue($invite->isSilent());
        $this->assertSame((string) $host->id, $invite->payload()['caller_id']);
    }

    public function test_the_log_sender_never_throws_when_nothing_is_configured(): void
    {
        // The default driver must degrade to silence, not to a 500 on every
        // message, when Firebase credentials are absent.
        $this->app->forgetInstance(PushSender::class);
        config()->set('hashbuddy.push.driver', 'log');

        [$group, , $joiner] = $this->sharedRide();

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'still works'])
            ->assertCreated();
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
