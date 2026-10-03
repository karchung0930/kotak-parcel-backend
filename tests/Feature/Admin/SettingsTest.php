<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_admins_see_the_current_settings_their_limits_and_the_drop_off_timing()
    {
        $order = Order::factory()->droppedOff()->create();
        $order->forceFill(['created_at' => now()->subDays(2), 'dropped_off_at' => now()])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Settings')
                ->where('settings', [
                    'unclaimed_order_days' => 7,
                    'drop_off_reminder_days_before' => 2,
                    'max_failed_attempts' => 3,
                ])
                ->where('limits.unclaimed_order_days', ['min' => 2, 'max' => 60])
                ->where('limits.drop_off_reminder_days_before', ['min' => 0, 'max' => 59])
                ->where('limits.max_failed_attempts', ['min' => 1, 'max' => 10])
                ->where('timing.dropped_off', 1)
                ->where('timing.median_days', 2)
                ->where('timing.within_limit_percent', 100)
                ->where('timing.suggestion', '95% drop off within 2.0 days, inside the 7-day limit.'));
    }

    public function test_the_timing_panel_is_empty_without_drop_offs()
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timing.dropped_off', 0)
                ->where('timing.median_days', null)
                ->where('timing.suggestion', null));
    }

    public function test_admins_save_the_settings()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [
                'unclaimed_order_days' => '10',
                'drop_off_reminder_days_before' => '3',
                'max_failed_attempts' => '4',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.settings.edit'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Site settings saved.']);

        $this->assertSame([
            'unclaimed_order_days' => 10,
            'drop_off_reminder_days_before' => 3,
            'max_failed_attempts' => 4,
        ], app(Settings::class)->all());
        $this->assertSame($this->admin->id, Setting::query()->findOrFail('max_failed_attempts')->updated_by);

        // Every page sees the new attempt limit straight away.
        $this->actingAs($this->admin)
            ->get(route('admin.settings.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.unclaimed_order_days', 10)
                ->where('maxFailedAttempts', 4));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): array
    {
        return [
            'limit below 2 days' => [['unclaimed_order_days' => 1, 'drop_off_reminder_days_before' => 0], 'unclaimed_order_days'],
            'limit above 60 days' => [['unclaimed_order_days' => 61], 'unclaimed_order_days'],
            'limit not a whole number' => [['unclaimed_order_days' => '7.5'], 'unclaimed_order_days'],
            'missing limit' => [['unclaimed_order_days' => ''], 'unclaimed_order_days'],
            'negative reminder' => [['drop_off_reminder_days_before' => -1], 'drop_off_reminder_days_before'],
            'reminder on the day of expiry' => [['unclaimed_order_days' => 5, 'drop_off_reminder_days_before' => 5], 'drop_off_reminder_days_before'],
            'reminder after expiry' => [['unclaimed_order_days' => 5, 'drop_off_reminder_days_before' => 9], 'drop_off_reminder_days_before'],
            'no delivery attempts' => [['max_failed_attempts' => 0], 'max_failed_attempts'],
            'more than 10 attempts' => [['max_failed_attempts' => 11], 'max_failed_attempts'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidSettings')]
    public function test_the_settings_are_validated(array $overrides, string $field)
    {
        $this->actingAs($this->admin)
            ->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), [...$this->validSettings(), ...$overrides])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Setting::count());
    }

    public function test_a_number_with_a_leading_zero_is_taken()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [
                'unclaimed_order_days' => '07',
                'drop_off_reminder_days_before' => '02',
                'max_failed_attempts' => '003',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([
            'unclaimed_order_days' => 7,
            'drop_off_reminder_days_before' => 2,
            'max_failed_attempts' => 3,
        ], app(Settings::class)->all());
    }

    public function test_the_errors_are_in_plain_english()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [
                'unclaimed_order_days' => '',
                'drop_off_reminder_days_before' => '1.5',
                'max_failed_attempts' => 'three',
            ])
            ->assertSessionHasErrors([
                'unclaimed_order_days' => 'Enter a number.',
                'drop_off_reminder_days_before' => 'Enter a whole number.',
                'max_failed_attempts' => 'Enter a whole number.',
            ]);
    }

    public function test_a_missing_limit_gives_no_error_on_the_reminder()
    {
        foreach (['', 'seven', '7.5'] as $limit) {
            $this->actingAs($this->admin)
                ->put(route('admin.settings.update'), [...$this->validSettings(), 'unclaimed_order_days' => $limit])
                ->assertSessionHasErrors('unclaimed_order_days')
                ->assertSessionDoesntHaveErrors('drop_off_reminder_days_before');
        }
    }

    public function test_the_reminder_must_come_before_the_order_is_cancelled()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [...$this->validSettings(), 'unclaimed_order_days' => 4, 'drop_off_reminder_days_before' => 4])
            ->assertSessionHasErrors([
                'drop_off_reminder_days_before' => 'Use fewer days than the drop-off limit, so the reminder comes first.',
            ]);
    }

    public function test_a_reminder_of_zero_days_turns_it_off()
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), [...$this->validSettings(), 'drop_off_reminder_days_before' => 0])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, app(Settings::class)->dropOffReminderDaysBefore());
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function otherRoles(): array
    {
        return [
            'customer' => [Role::Customer],
            'driver' => [Role::Driver],
            'branch staff' => [Role::Staff],
        ];
    }

    #[DataProvider('otherRoles')]
    public function test_only_admins_can_see_or_change_the_settings(Role $role)
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('admin.settings.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.update'), $this->validSettings())->assertForbidden();

        $this->assertSame(0, Setting::count());
    }

    public function test_the_policy_allows_admins_only()
    {
        $this->assertTrue($this->admin->can('viewAny', Setting::class));
        $this->assertTrue($this->admin->can('update', Setting::class));
        $this->assertFalse(User::factory()->staff()->create()->can('update', Setting::class));
    }

    public function test_guests_are_sent_to_log_in()
    {
        $this->get(route('admin.settings.edit'))->assertRedirect(route('login'));
        $this->put(route('admin.settings.update'), $this->validSettings())->assertRedirect(route('login'));
    }

    /**
     * @return array<string, int>
     */
    private function validSettings(): array
    {
        return [
            'unclaimed_order_days' => 7,
            'drop_off_reminder_days_before' => 2,
            'max_failed_attempts' => 3,
        ];
    }
}
