<?php

namespace Tests\Feature\Driver;

use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryOutcome;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FailTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->driver = User::factory()->driver()->create();
        $this->order = Order::factory()->pickedUp($this->driver)->create();
    }

    public function test_the_driver_records_why_the_delivery_failed()
    {
        $this->actingAs($this->driver)
            ->post(route('driver.jobs.fail', $this->order), [
                'reason' => 'address_not_found',
                'note' => 'No unit number, guard turned me away.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('driver.jobs'))
            ->assertInertiaFlash('toast', [
                'type' => 'info',
                'message' => "Failed delivery recorded for {$this->order->formatted_tracking_number}.",
            ]);

        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::DeliveryFailed, $order?->status);
        $this->assertSame(1, $order->failedAttemptsCount());

        $attempt = $order->latestAttempt()->firstOrFail();
        $this->assertSame(DeliveryOutcome::Failed, $attempt->outcome);
        $this->assertSame(DeliveryFailureReason::AddressNotFound, $attempt->failure_reason);
        $this->assertSame('No unit number, guard turned me away.', $attempt->note);
        $this->assertSame($this->driver->id, $attempt->driver_id);
    }

    public function test_a_job_for_a_later_day_returns_to_that_days_list()
    {
        $tomorrow = today(config('kotak.timezone'))->addDay()->toDateString();
        $order = Order::factory()->pickedUp($this->driver)->create(['scheduled_for' => $tomorrow]);

        $this->actingAs($this->driver)
            ->post(route('driver.jobs.fail', $order), ['reason' => 'recipient_unavailable'])
            ->assertRedirect(route('driver.jobs', ['date' => $tomorrow]));
    }

    public function test_the_note_is_optional_for_listed_reasons()
    {
        $this->actingAs($this->driver)
            ->post(route('driver.jobs.fail', $this->order), ['reason' => 'recipient_unavailable'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->order->latestAttempt()->firstOrFail()->note);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFailures(): array
    {
        return [
            'missing reason' => [['reason' => ''], 'reason'],
            'reason not in the list' => [['reason' => 'lost'], 'reason'],
            'other without a note' => [['reason' => 'other', 'note' => ''], 'note'],
            'note too long' => [['reason' => 'recipient_unavailable', 'note' => str_repeat('a', 501)], 'note'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('invalidFailures')]
    public function test_the_reason_must_come_from_the_fixed_list(array $data, string $field)
    {
        $this->actingAs($this->driver)
            ->post(route('driver.jobs.fail', $this->order), $data)
            ->assertSessionHasErrors($field);

        $this->assertSame(OrderStatus::PickedUp, $this->order->fresh()?->status);
        $this->assertSame(0, $this->order->deliveryAttempts()->count());
    }

    public function test_other_needs_a_note()
    {
        $this->actingAs($this->driver)
            ->post(route('driver.jobs.fail', $this->order), ['reason' => 'other', 'note' => 'Gate locked, dog in the yard.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::DeliveryFailed, $this->order->fresh()?->status);
    }

    public function test_a_parcel_must_be_picked_up_before_a_failure_is_recorded()
    {
        $order = Order::factory()->assigned($this->driver)->create();

        $this->actingAs($this->driver)
            ->post(route('driver.jobs.fail', $order), ['reason' => 'recipient_unavailable'])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::Assigned, $order->fresh()?->status);
        $this->assertSame(0, $order->deliveryAttempts()->count());
    }

    public function test_drivers_cannot_fail_another_drivers_job()
    {
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('driver.jobs.fail', $this->order), ['reason' => 'recipient_unavailable'])
            ->assertForbidden();

        $this->assertSame(OrderStatus::PickedUp, $this->order->fresh()?->status);
        $this->assertSame(0, $this->order->deliveryAttempts()->count());
    }
}
