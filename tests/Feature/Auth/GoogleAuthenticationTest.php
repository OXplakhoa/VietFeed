<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
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
        ], 'mongodb');

        $this->assertNotNull(User::where('email', 'google-user@example.com')->first()?->email_verified_at);
    }

    public function test_google_sign_in_links_a_verified_address_and_keeps_verification_bound_to_it(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.com',
            'google_id' => null,
            'email_verified_at' => null,
            'avatar' => null,
        ]);

        $linked = $this->fakeGoogleUser('google-existing', 'Existing User', $user->email, 'https://example.com/existing.jpg');
        $different = $this->fakeGoogleUser('google-different', 'Different User', $user->email, 'https://example.com/different.jpg');
        Socialite::shouldReceive('driver->user')->times(3)->andReturn($linked, $different, $linked);

        $response = $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));

        $user->refresh();

        $this->assertSame('google-existing', $user->google_id);
        $this->assertSame('https://example.com/existing.jpg', $user->avatar);
        $this->assertNotNull($user->email_verified_at);
        auth()->logout();

        $this->get('/auth/google/callback')->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame('google-existing', $user->refresh()->google_id);
        $this->assertSame('https://example.com/existing.jpg', $user->avatar);

        $this->actingAs($user)->patch('/profile', ['name' => $user->name, 'email' => 'changed@example.com'])->assertRedirect(route('profile.edit'));
        auth()->logout();

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('changed@example.com', $user->refresh()->email);
        $this->assertNull($user->email_verified_at);
    }

    protected function fakeGoogleUser(string $id, string $name, string $email, string $avatar): SocialiteUser
    {
        $user = (new SocialiteUser)->setRaw(['email_verified' => true]);
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
