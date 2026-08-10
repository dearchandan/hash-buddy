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
