<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_can_authenticate_with_google_and_are_redirected_to_onboarding(): void
    {
        Socialite::shouldReceive('driver->user')
            ->once()
            ->andReturn($this->fakeGoogleUser(
                id: 'google-123',
                name: 'Google User',
                email: 'google-user@example.com',
                avatar: 'https://example.com/avatar.jpg',
            ));

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticated();
        $response->assertRedirect(route('onboarding.interests', absolute: false));

        $this->assertDatabaseHas('users', [
            'email' => 'google-user@example.com',
            'google_id' => 'google-123',
            'role' => 'user',
            'avatar' => 'https://example.com/avatar.jpg',
        ]);

        $this->assertNotNull(User::where('email', 'google-user@example.com')->first()?->email_verified_at);
    }

    public function test_existing_users_are_linked_by_email_when_signing_in_with_google(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'google_id' => null,
            'email_verified_at' => null,
            'avatar' => null,
        ]);

        Socialite::shouldReceive('driver->user')
            ->once()
            ->andReturn($this->fakeGoogleUser(
                id: 'google-existing',
                name: 'Existing User',
                email: 'existing@example.com',
                avatar: 'https://example.com/existing.jpg',
            ));

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));

        $user->refresh();

        $this->assertSame('google-existing', $user->google_id);
        $this->assertSame('https://example.com/existing.jpg', $user->avatar);
        $this->assertNotNull($user->email_verified_at);
    }

    protected function fakeGoogleUser(string $id, string $name, string $email, string $avatar): SocialiteUser
    {
        $user = new SocialiteUser;
        $user->map([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'avatar' => $avatar,
        ]);

        return $user;
    }

    protected function tearDown(): void
    {
        Socialite::clearResolvedInstances();
        Mockery::close();

        parent::tearDown();
    }
}
