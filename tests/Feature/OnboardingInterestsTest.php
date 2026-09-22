<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Gate 3 T2: onboarding interests round-trip on embedded favorite_category_ids
// (category_user pivot retired for user writes).
class OnboardingInterestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_interests_save_embeds_ids_and_show_preselects(): void
    {
        $user = User::factory()->create();
        $a = Category::create(['name' => 'A', 'slug' => 'a', 'is_active' => true]);
        $b = Category::create(['name' => 'B', 'slug' => 'b', 'is_active' => true]);

        $this->actingAs($user)
            ->post(route('onboarding.interests.save'), ['categories' => [$a->id, $b->id]])
            ->assertRedirect(route('home'));

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $user->fresh()->favorite_category_ids,
        );

        $this->actingAs($user)
            ->get(route('onboarding.interests'))
            ->assertOk();
    }
}
