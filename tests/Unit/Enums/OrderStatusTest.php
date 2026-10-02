<?php

namespace Tests\Unit\Enums;

use App\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    /**
     * The complete workflow: every allowed move, and nothing else.
     *
     * @return array<string, array{OrderStatus, list<OrderStatus>}>
     */
    public static function workflow(): array
    {
        return [
            'created' => [OrderStatus::Created, [OrderStatus::DroppedOff, OrderStatus::Cancelled]],
            'dropped off' => [OrderStatus::DroppedOff, [OrderStatus::Paid, OrderStatus::Cancelled]],
            'paid' => [OrderStatus::Paid, [OrderStatus::Assigned]],
            // Reassigned to another driver or day until the driver collects it.
            'assigned' => [OrderStatus::Assigned, [OrderStatus::Assigned, OrderStatus::PickedUp]],
            'picked up' => [OrderStatus::PickedUp, [OrderStatus::Delivered, OrderStatus::DeliveryFailed]],
            'delivery failed' => [OrderStatus::DeliveryFailed, [OrderStatus::Assigned, OrderStatus::ReturnedToSender]],
            'delivered' => [OrderStatus::Delivered, []],
            'returned to sender' => [OrderStatus::ReturnedToSender, []],
            'cancelled' => [OrderStatus::Cancelled, []],
        ];
    }

    /**
     * @param  list<OrderStatus>  $allowed
     */
    #[DataProvider('workflow')]
    public function test_only_the_documented_transitions_are_allowed(OrderStatus $from, array $allowed)
    {
        $this->assertSame($allowed, $from->allowedNext());

        foreach (OrderStatus::cases() as $to) {
            $this->assertSame(in_array($to, $allowed, true), $from->canTransitionTo($to), "{$from->value} -> {$to->value}");
        }
    }

    public function test_final_statuses_are_the_ones_with_no_next_step()
    {
        $final = array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $status) => $status->isFinal()));

        $this->assertSame([OrderStatus::Delivered, OrderStatus::ReturnedToSender, OrderStatus::Cancelled], $final);
    }

    public function test_every_status_has_a_label_and_a_customer_facing_description()
    {
        $this->assertSame('Dropped Off', OrderStatus::DroppedOff->label());
        $this->assertSame('Returned to Sender', OrderStatus::ReturnedToSender->label());

        foreach (OrderStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertStringEndsWith('.', $status->description());
        }
    }

    public function test_statuses_convert_to_frontend_options()
    {
        $this->assertSame(['value' => 'picked_up', 'label' => 'Picked Up'], OrderStatus::PickedUp->toOption());
        $this->assertCount(9, OrderStatus::options());
        $this->assertSame(['value' => 'created', 'label' => 'Created'], OrderStatus::options()[0]);
    }
}
