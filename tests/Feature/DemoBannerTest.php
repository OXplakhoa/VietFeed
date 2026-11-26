<?php

namespace Tests\Feature;

use Tests\TestCase;

class DemoBannerTest extends TestCase
{
    public function test_banner_is_hidden_by_default_and_shown_when_demo_mode_is_on(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('Bản demo');

        config(['app.demo_mode' => true]);

        $this->get('/login')->assertOk()->assertSee('Bản demo — Stripe ở chế độ thử nghiệm', false);
    }
}
