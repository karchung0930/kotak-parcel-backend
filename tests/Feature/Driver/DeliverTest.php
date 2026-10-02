<?php

namespace Tests\Feature\Driver;

use App\Enums\DeliveryOutcome;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliverTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('local');

        $this->driver = User::factory()->driver()->create();
        $this->order = Order::factory()->pickedUp($this->driver)->create();
    }

    public function test_the_driver_records_the_recipient_and_a_private_photo()
    {
        $this->actingAs($this->driver)
            ->post(route('driver.jobs.deliver', $this->order), [
                'recipient_name' => 'Daniel Lim',
                'photo' => UploadedFile::fake()->image('door.png', 800, 600),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('driver.jobs'))
            ->assertInertiaFlash('toast', [
                'type' => 'success',
                'message' => "Delivered {$this->order->formatted_tracking_number}.",
            ]);

        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::Delivered, $order?->status);
        $this->assertNotNull($order->delivered_at);

        $attempt = $order->latestAttempt()->firstOrFail();
        $this->assertSame(DeliveryOutcome::Delivered, $attempt->outcome);
        $this->assertSame('Daniel Lim', $attempt->recipient_name);
        $this->assertStringStartsWith("pod/{$order->id}/", (string) $attempt->photo_path);
        Storage::disk('local')->assertExists((string) $attempt->photo_path);
    }

    public function test_the_photo_is_served_privately_to_the_customer()
    {
        $this->actingAs($this->driver)->post(route('driver.jobs.deliver', $this->order), [
            'recipient_name' => 'Daniel Lim',
            'photo' => UploadedFile::fake()->image('door.png'),
        ]);

        $response = $this->actingAs($this->order->customer)
            ->get(route('orders.proof', $this->order))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_parcel_must_be_picked_up_before_it_is_delivered()
    {
        $order = Order::factory()->assigned($this->driver)->create();

        $this->actingAs($this->driver)
            ->post(route('driver.jobs.deliver', $order), [
                'recipient_name' => 'Daniel Lim',
                'photo' => UploadedFile::fake()->image('door.png'),
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(OrderStatus::Assigned, $order->fresh()?->status);
        $this->assertSame(0, $order->deliveryAttempts()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_delivered_parcel_cannot_be_delivered_again()
    {
        $order = Order::factory()->delivered($this->driver)->create();

        $this->actingAs($this->driver)
            ->post(route('driver.jobs.deliver', $order), [
                'recipient_name' => 'Daniel Lim',
                'photo' => UploadedFile::fake()->image('door.png'),
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(1, $order->deliveryAttempts()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_drivers_cannot_deliver_another_drivers_job()
    {
        $this->actingAs(User::factory()->driver()->create())
            ->post(route('driver.jobs.deliver', $this->order), [
                'recipient_name' => 'Daniel Lim',
                'photo' => UploadedFile::fake()->image('door.png'),
            ])
            ->assertForbidden();

        $this->assertSame(OrderStatus::PickedUp, $this->order->fresh()?->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidDeliveries(): array
    {
        return [
            'missing recipient' => [['recipient_name' => ''], 'recipient_name'],
            'recipient name too long' => [['recipient_name' => str_repeat('a', 101)], 'recipient_name'],
            'missing photo' => [['photo' => null], 'photo'],
            'not a file' => [['photo' => 'door.png'], 'photo'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidDeliveries')]
    public function test_the_recipient_and_photo_are_required(array $overrides, string $field)
    {
        $this->actingAs($this->driver)
            ->post(route('driver.jobs.deliver', $this->order), [
                'recipient_name' => 'Daniel Lim',
                'photo' => UploadedFile::fake()->image('door.png'),
                ...$overrides,
            ])
            ->assertSessionHasErrors($field);

        $this->assertSame(OrderStatus::PickedUp, $this->order->fresh()?->status);
    }

    /**
     * @return array<string, array{Closure(): UploadedFile}>
     */
    public static function invalidPhotos(): array
    {
        return [
            'pdf' => [fn () => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf')],
            'svg' => [fn () => UploadedFile::fake()->createWithContent('proof.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'gif' => [fn () => UploadedFile::fake()->image('proof.gif')],
            'larger than 5 MB' => [fn () => UploadedFile::fake()->image('proof.png')->size(5121)],
        ];
    }

    /**
     * @param  Closure(): UploadedFile  $photo
     */
    #[DataProvider('invalidPhotos')]
    public function test_the_photo_must_be_a_jpeg_png_or_webp_image_up_to_5_mb(Closure $photo)
    {
        $this->actingAs($this->driver)
            ->post(route('driver.jobs.deliver', $this->order), [
                'recipient_name' => 'Daniel Lim',
                'photo' => $photo(),
            ])
            ->assertSessionHasErrors('photo');

        $this->assertSame(OrderStatus::PickedUp, $this->order->fresh()?->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_photo_type_is_checked_by_its_content_not_its_name()
    {
        // A real upload, since fakes report their type from the file name: a script named like a PNG.
        $path = (string) tempnam(sys_get_temp_dir(), 'pod');
        file_put_contents($path, '<?php echo "hi";');

        try {
            $this->actingAs($this->driver)
                ->post(route('driver.jobs.deliver', $this->order), [
                    'recipient_name' => 'Daniel Lim',
                    'photo' => new UploadedFile($path, 'proof.png', 'image/png', null, true),
                ])
                ->assertSessionHasErrors('photo');
        } finally {
            @unlink($path);
        }

        $this->assertSame(OrderStatus::PickedUp, $this->order->fresh()?->status);
    }
}
