<?php

namespace Tests\Feature;

use App\Enums\MessageType;
use App\Enums\RideGroupStatus;
use App\Models\Message;
use App\Models\RideGroup;
use App\Models\RideRequest;
use App\Models\User;
use App\Models\Zone;
use App\Services\RideGroupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->zone = Zone::factory()->create(['sedan_fare' => 1250, 'suv_fare' => 2000]);
    }

    public function test_a_traveller_on_the_ride_can_send_and_read_messages(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'At gate 4, black jacket.'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'At gate 4, black jacket.')
            ->assertJsonPath('data.is_mine', true);

        Sanctum::actingAs($host);
        $this->getJson("/api/v1/groups/{$group->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.1.body', 'At gate 4, black jacket.')
            ->assertJsonPath('data.1.is_mine', false)
            ->assertJsonPath('data.1.sender.name', $joiner->name);
    }

    public function test_someone_who_is_not_on_the_ride_cannot_read_or_write(): void
    {
        [$group] = $this->sharedRide();

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        // 404 rather than 403: probing ids must not reveal which rides exist.
        $this->getJson("/api/v1/groups/{$group->id}/messages")->assertNotFound();
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'hello?'])->assertNotFound();
    }

    public function test_a_traveller_who_left_loses_access_to_the_chat(): void
    {
        [$group, , $joiner] = $this->sharedRide();

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/groups/{$group->id}/leave")->assertOk();

        $this->getJson("/api/v1/groups/{$group->id}/messages")->assertNotFound();
    }

    public function test_joining_writes_a_system_line_that_is_not_attributed_to_anyone(): void
    {
        [$group, , $joiner] = $this->sharedRide();

        $system = $group->messages()->where('type', MessageType::System)->first();

        $this->assertNotNull($system);
        $this->assertNull($system->user_id);
        $this->assertStringContainsString($joiner->name, $system->body);
    }

    public function test_polling_after_a_cursor_returns_only_newer_messages(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();

        Sanctum::actingAs($joiner);
        $first = $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'one'])->json('data.id');
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'two']);

        Sanctum::actingAs($host);
        $response = $this->getJson("/api/v1/groups/{$group->id}/messages?after={$first}")->assertOk();

        $this->assertSame(['two'], array_column($response->json('data'), 'body'));
    }

    public function test_unread_counts_ignore_your_own_messages_and_system_lines(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'one']);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'two']);

        // The joiner wrote both, and the only other line is the system join
        // notice, so nothing is waiting for them.
        $this->getJson('/api/v1/messages/unread')
            ->assertOk()
            ->assertJsonPath("data.{$group->id}", null);

        Sanctum::actingAs($host);
        $this->getJson('/api/v1/messages/unread')
            ->assertOk()
            ->assertJsonPath("data.{$group->id}", 2);
    }

    public function test_reading_the_history_clears_the_unread_badge(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();

        Sanctum::actingAs($joiner);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'one']);

        Sanctum::actingAs($host);
        $this->getJson("/api/v1/groups/{$group->id}/messages")->assertOk();

        $this->getJson('/api/v1/messages/unread')
            ->assertOk()
            ->assertJsonPath("data.{$group->id}", null);
    }

    public function test_the_read_cursor_never_rewinds(): void
    {
        [$group, $host, $joiner] = $this->sharedRide();

        Sanctum::actingAs($joiner);
        $first = $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'one'])->json('data.id');
        $second = $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'two'])->json('data.id');

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/messages/read", ['message_id' => $second])->assertOk();
        // A late-arriving read for an older message must not un-read the newer.
        $this->postJson("/api/v1/groups/{$group->id}/messages/read", ['message_id' => $first])->assertOk();

        $member = $group->members()->where('user_id', $host->id)->first();
        $this->assertSame($second, (int) $member->last_read_message_id);
    }

    public function test_chat_closes_when_the_ride_is_cancelled(): void
    {
        [$group, $host] = $this->sharedRide();

        $group->forceFill(['status' => RideGroupStatus::Cancelled])->save();

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => 'anyone there?'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'chat_closed');
    }

    public function test_an_empty_message_is_rejected(): void
    {
        [$group, $host] = $this->sharedRide();

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => '   '])
            ->assertStatus(422);
    }

    public function test_a_message_longer_than_the_limit_is_rejected(): void
    {
        [$group, $host] = $this->sharedRide();

        Sanctum::actingAs($host);
        $this->postJson("/api/v1/groups/{$group->id}/messages", ['body' => str_repeat('a', 1001)])
            ->assertStatus(422);
    }

    public function test_messages_are_scoped_to_their_own_ride(): void
    {
        [$groupA, $hostA] = $this->sharedRide();
        [$groupB, $hostB] = $this->sharedRide();

        Sanctum::actingAs($hostB);
        $this->postJson("/api/v1/groups/{$groupB->id}/messages", ['body' => 'ride B only']);

        Sanctum::actingAs($hostA);
        $bodies = array_column(
            $this->getJson("/api/v1/groups/{$groupA->id}/messages")->json('data'),
            'body',
        );

        $this->assertNotContains('ride B only', $bodies);
        $this->assertSame(0, Message::where('ride_group_id', $groupA->id)
            ->where('body', 'ride B only')->count());
    }

    /**
     * A locked two-seat ride with a host and one joiner, which is the shape
     * chat actually runs in.
     *
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
