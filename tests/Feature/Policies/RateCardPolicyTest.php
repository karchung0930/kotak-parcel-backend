<?php

namespace Tests\Feature\Policies;

use App\Models\RateCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateCardPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_see_and_start_rate_cards()
    {
        $card = RateCard::query()->sole();

        $admin = User::factory()->admin()->create();
        $this->assertTrue($admin->can('viewAny', RateCard::class));
        $this->assertTrue($admin->can('view', $card));
        $this->assertTrue($admin->can('create', RateCard::class));
        $this->assertTrue($admin->can('manage', RateCard::class));

        foreach ([User::factory()->staff()->create(), User::factory()->driver()->create(), User::factory()->create()] as $user) {
            $this->assertFalse($user->can('viewAny', RateCard::class), $user->role->value);
            $this->assertFalse($user->can('view', $card), $user->role->value);
            $this->assertFalse($user->can('create', RateCard::class), $user->role->value);
            $this->assertFalse($user->can('manage', RateCard::class), $user->role->value);
        }
    }

    public function test_drafts_are_edited_published_and_deleted_but_published_cards_never_change()
    {
        $admin = User::factory()->admin()->create();
        $draft = RateCard::factory()->create();
        $current = RateCard::query()->published()->sole();

        $this->assertTrue($admin->can('update', $draft));
        $this->assertTrue($admin->can('publish', $draft));
        $this->assertTrue($admin->can('delete', $draft));
        $this->assertFalse($admin->can('withdraw', $draft));

        $this->assertFalse($admin->can('update', $current));
        $this->assertFalse($admin->can('publish', $current));
        $this->assertFalse($admin->can('delete', $current));
        $this->assertFalse($admin->can('withdraw', $current));

        $this->assertFalse(User::factory()->staff()->create()->can('update', $draft));
    }

    public function test_only_scheduled_cards_can_be_withdrawn()
    {
        $admin = User::factory()->admin()->create();
        $scheduled = RateCard::factory()->flat()->scheduled()->create();

        $this->assertTrue($admin->can('withdraw', $scheduled));
        $this->assertFalse($admin->can('update', $scheduled));
        $this->assertFalse(User::factory()->staff()->create()->can('withdraw', $scheduled));

        $this->travelTo($scheduled->effective_from);
        $this->assertFalse($admin->can('withdraw', $scheduled));
    }
}
