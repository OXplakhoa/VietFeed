<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class RegistrationTest extends TestCase
{
    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        // Public demo hardening: the 11th registration attempt from one IP within a minute is throttled.
        $this->post('/logout');
        for ($i = 0; $i < 9; $i++) {
            $this->post('/register', []);
        }
        $this->post('/register', [])->assertStatus(429);
    }
}
