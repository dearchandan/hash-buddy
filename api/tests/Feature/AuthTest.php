<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_phone_number_gets_an_account_on_first_verification(): void
    {
        $code = $this->issueCodeFor('+919800000001');

        $response = $this->postJson('/api/v1/auth/verify', [
            'phone' => '+919800000001',
            'code' => $code,
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertTrue($response->json('needs_profile'));
        $this->assertDatabaseHas('users', ['phone' => '+919800000001']);
    }

    public function test_an_existing_traveller_signs_back_in_without_a_new_account(): void
    {
        $user = User::factory()->create(['phone' => '+919800000009', 'name' => 'Chandan']);

        $code = $this->issueCodeFor('+919800000009');

        $this->postJson('/api/v1/auth/verify', ['phone' => '+919800000009', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('needs_profile', false);

        $this->assertSame(1, User::where('phone', '+919800000009')->count());
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $this->issueCodeFor('+919800000002');

        $this->postJson('/api/v1/auth/verify', [
            'phone' => '+919800000002',
            'code' => '000000',
        ])->assertStatus(422)->assertJsonPath('error', 'invalid_otp');

        $this->assertSame(1, OtpCode::where('phone', '+919800000002')->first()->attempts);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        OtpCode::create([
            'phone' => '+919800000003',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/verify', [
            'phone' => '+919800000003',
            'code' => '123456',
        ])->assertStatus(422);
    }

    public function test_issuing_a_new_code_retires_the_previous_one(): void
    {
        $first = $this->issueCodeFor('+919800000004');
        $this->issueCodeFor('+919800000004');

        $this->postJson('/api/v1/auth/verify', [
            'phone' => '+919800000004',
            'code' => $first,
        ])->assertStatus(422);
    }

    public function test_production_never_returns_the_code_even_if_the_debug_flag_is_left_on(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config([
            'hashbuddy.otp.debug' => true,
            'hashbuddy.otp.test_numbers' => [],
            'hashbuddy.otp.sms_driver' => 'msg91',
        ]);

        // The blanket flag is ignored outside local, so a stray true in a live
        // .env cannot turn login into an open door.
        $this->postJson('/api/v1/auth/otp', ['phone' => '+919800000010'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'sms_unavailable');
    }

    public function test_an_allowlisted_test_number_still_gets_its_code_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config([
            'hashbuddy.otp.debug' => false,
            'hashbuddy.otp.test_numbers' => ['+919800000001'],
        ]);

        $code = $this->postJson('/api/v1/auth/otp', ['phone' => '+919800000001'])
            ->assertOk()
            ->json('debug_code');

        $this->assertNotEmpty($code);

        $this->postJson('/api/v1/auth/verify', ['phone' => '+919800000001', 'code' => $code])
            ->assertOk();
    }

    public function test_a_number_off_the_allowlist_is_refused_rather_than_left_hanging(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config([
            'hashbuddy.otp.debug' => false,
            'hashbuddy.otp.test_numbers' => ['+919800000001'],
            'hashbuddy.otp.sms_driver' => 'log',
        ]);

        $this->postJson('/api/v1/auth/otp', ['phone' => '+919800000099'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'sms_unavailable');

        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_ride_endpoints_require_a_token(): void
    {
        $this->getJson('/api/v1/ride-requests')->assertUnauthorized();
        $this->getJson('/api/v1/groups')->assertUnauthorized();
    }

    public function test_a_traveller_can_fill_in_their_profile(): void
    {
        Sanctum::actingAs(User::factory()->create(['name' => 'Traveller']));

        $this->patchJson('/api/v1/me', ['name' => 'Chandan', 'gender' => 'male'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Chandan')
            ->assertJsonPath('data.gender', 'male');
    }

    private function issueCodeFor(string $phone): string
    {
        return $this->postJson('/api/v1/auth/otp', ['phone' => $phone])
            ->assertOk()
            ->json('debug_code');
    }
}
