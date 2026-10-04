<?php

namespace Tests\Feature\Notifications;

use App\Actions\Delivery\AssignDriver;
use App\Actions\Delivery\SendRunSheets;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DriverRunSheet;
use App\Services\OrderStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class DriverRunSheetTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $day;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00', 'Asia/Kuala_Lumpur'));
        $this->day = CarbonImmutable::parse('2026-10-05', 'Asia/Kuala_Lumpur');
        $this->driver = User::factory()->driver()->create(['name' => 'Ravi Kumar', 'email' => 'ravi@example.com']);
    }

    public function test_the_email_counts_the_jobs_and_lists_those_on_the_van_then_those_to_collect_by_branch()
    {
        [$ss2, $bangsar] = $this->branches();
        $today = $this->job($ss2, ['postcode' => '60000', 'city' => 'Kuala Lumpur']);
        $overdue = $this->job($ss2, ['postcode' => '47301', 'city' => 'Petaling Jaya', 'scheduled_for' => '2026-10-02'], pickedUp: true);
        $onTheVan = $this->job($bangsar, ['postcode' => '59100', 'city' => 'Kuala Lumpur'], pickedUp: true);
        $bangsars = $this->job($bangsar, ['postcode' => '59200', 'city' => 'Kuala Lumpur']);
        $late = $this->job($ss2, ['postcode' => '43000', 'city' => 'Kajang', 'scheduled_for' => '2026-10-03']);

        $sheet = new DriverRunSheet($this->day);
        $mail = $sheet->toMail($this->driver);

        $this->assertSame('Your round for Monday, 5 October: 5 deliveries', $mail->subject);
        $this->assertSame('Hi Ravi Kumar,', $mail->greeting);
        $this->assertSame([
            'You have **5 deliveries** today, 2 carried over from earlier days.',
            "The receivers' names, addresses and phone numbers are on My jobs.",
        ], $mail->introLines);
        $this->assertSame([
            [
                'title' => 'Already on your van',
                'parcels' => '2 parcels',
                'address' => null,
                'jobs' => [
                    ['tracking_number' => $overdue->formatted_tracking_number, 'area' => "Petaling Jaya\u{00A0}47301", 'status' => 'Picked Up', 'overdue' => "Overdue, was due 2\u{00A0}Oct"],
                    ['tracking_number' => $onTheVan->formatted_tracking_number, 'area' => "Kuala Lumpur\u{00A0}59100", 'status' => 'Picked Up', 'overdue' => null],
                ],
            ],
            [
                'title' => "Collect from Bangsar\u{00A0}South",
                'parcels' => '1 parcel',
                'address' => "Lot 3, Jalan Kerinchi, 59200\u{00A0}Kuala\u{00A0}Lumpur",
                'jobs' => [
                    ['tracking_number' => $bangsars->formatted_tracking_number, 'area' => "Kuala Lumpur\u{00A0}59200", 'status' => 'Assigned', 'overdue' => null],
                ],
            ],
            [
                'title' => "Collect from Petaling Jaya\u{00A0}- SS2",
                'parcels' => '2 parcels',
                'address' => "12, Jalan SS 2/67, SS 2, 47300\u{00A0}Petaling\u{00A0}Jaya",
                'jobs' => [
                    ['tracking_number' => $late->formatted_tracking_number, 'area' => "Kajang\u{00A0}43000", 'status' => 'Assigned', 'overdue' => "Overdue, was due 3\u{00A0}Oct"],
                    ['tracking_number' => $today->formatted_tracking_number, 'area' => "Kuala Lumpur\u{00A0}60000", 'status' => 'Assigned', 'overdue' => null],
                ],
            ],
        ], $sheet->groups($sheet->jobsFor($this->driver)));
        $this->assertSame('Open My jobs', $mail->actionText);
        $this->assertSame(route('driver.jobs'), $mail->actionUrl);
        $this->assertSame([], $mail->outroLines);
    }

    public function test_the_email_has_the_button_then_one_table_for_every_group()
    {
        [$ss2, $bangsar] = $this->branches();
        $overdue = $this->job($ss2, ['scheduled_for' => '2026-10-02'], pickedUp: true);
        $today = $this->job($ss2);
        $bangsars = $this->job($bangsar);

        $html = (string) (new DriverRunSheet($this->day))->toMail($this->driver)->render();

        // The button comes before the list, and there is a single table.
        $this->assertLessThan(strpos($html, '<table class="jobs"'), strpos($html, route('driver.jobs')));
        $this->assertSame(1, substr_count($html, '<table class="jobs"'));
        $this->assertMatchesRegularExpression('/<th[^>]*>Tracking no\.<\/th>/', $html);
        $this->assertMatchesRegularExpression('/<th[^>]*>Area<\/th>/', $html);
        $this->assertSame(3, substr_count($html, 'scope="rowgroup"'));
        $this->assertStringContainsString('Already on your van', $html);
        $this->assertStringContainsString("Collect from Bangsar\u{00A0}South", $html);
        $this->assertStringContainsString("Collect from Petaling Jaya\u{00A0}- SS2", $html);
        $this->assertStringContainsString("Overdue, was due 2\u{00A0}Oct", $html);

        foreach ([$overdue, $today, $bangsars] as $job) {
            $this->assertMatchesRegularExpression("/<td[^>]*>{$job->formatted_tracking_number}<\/td>/", $html);
        }
    }

    public function test_the_plain_text_part_lists_the_same_jobs()
    {
        [$ss2] = $this->branches();
        $overdue = $this->job($ss2, ['postcode' => '47301', 'city' => 'Petaling Jaya', 'scheduled_for' => '2026-10-02'], pickedUp: true);
        $today = $this->job($ss2, ['postcode' => '60000', 'city' => 'Kuala Lumpur']);

        app(SendRunSheets::class)->handle();

        $text = str_replace("\r\n", "\n", (string) $this->sentEmails()[0]->getTextBody());

        $this->assertStringContainsString(implode("\n", [
            'Already on your van',
            '1 parcel',
            "- {$overdue->formatted_tracking_number}: Petaling Jaya\u{00A0}47301, Picked Up (Overdue, was due 2\u{00A0}Oct)",
            '',
            "Collect from Petaling Jaya\u{00A0}- SS2",
            "1 parcel · 12, Jalan SS 2/67, SS 2, 47300\u{00A0}Petaling\u{00A0}Jaya",
            "- {$today->formatted_tracking_number}: Kuala Lumpur\u{00A0}60000, Assigned",
        ]), $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_a_single_job_reads_naturally()
    {
        [$ss2] = $this->branches();
        $this->job($ss2);
        $sheet = new DriverRunSheet($this->day);

        $mail = $sheet->toMail($this->driver);
        $this->assertSame('Your round for Monday, 5 October: 1 delivery', $mail->subject);
        $this->assertSame('You have **1 delivery** today.', $mail->introLines[0]);
        $this->assertSame('1 parcel', $sheet->groups($sheet->jobsFor($this->driver))[0]['parcels']);

        $this->job($ss2, ['scheduled_for' => '2026-10-02']);
        $this->assertSame('You have **2 deliveries** today, 1 carried over from an earlier day.', $sheet->toMail($this->driver)->introLines[0]);
    }

    public function test_the_email_carries_no_private_details()
    {
        [$ss2] = $this->branches();
        $customer = User::factory()->create(['name' => 'Aisyah Rahman', 'email' => 'aisyah@example.com', 'phone' => '+60123456789']);
        $job = Order::factory()->for($customer, 'customer')->for($ss2)->assigned($this->driver)->create([
            'receiver_name' => 'Daniel Lim',
            'receiver_phone' => '+60127788990',
            'address_line1' => 'No. 12, Jalan Datuk Sulaiman 1',
            'address_line2' => 'Taman Tun Dr Ismail',
            'item_name' => 'Ceramic dinner set',
        ]);

        app(SendRunSheets::class)->handle();
        $email = $this->sentEmails()[0];

        foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
            $this->assertStringContainsString($job->formatted_tracking_number, $body);

            foreach (['Daniel Lim', '127788990', 'Jalan Datuk Sulaiman', 'Taman Tun Dr Ismail', 'Aisyah', 'aisyah@example.com', '123456789', 'Ceramic dinner set'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }
        }
    }

    public function test_a_city_cannot_break_the_table_or_add_markup()
    {
        [$ss2] = $this->branches();
        // Older orders, from before the city was checked for these characters.
        $this->job($ss2, ['city' => "Kuala | Lumpur\n\n# URGENT\n\n[Verify](https://evil.example) ![](https://evil.example/p.gif) <b>now</b>", 'postcode' => '50450']);
        $this->job($ss2, ['city' => 'Klang', 'postcode' => '41050']);

        app(SendRunSheets::class)->handle();
        $email = $this->sentEmails()[0];
        $html = (string) $email->getHtmlBody();

        $area = "Kuala Lumpur # URGENT Verify (https://evil.example) ! (https://evil.example/p.gif) b now /b\u{00A0}50450";
        $this->assertMatchesRegularExpression('/<td[^>]*>'.preg_quote($area, '/').'<br/', $html);
        $this->assertMatchesRegularExpression("/<td[^>]*>Klang\u{00A0}41050<br/u", $html);
        $this->assertSame(2, substr_count($html, 'class="jobs-tracking"', strpos($html, '<tbody')));
        $this->assertStringNotContainsString('<h1>URGENT', $html);
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>now', $html);
        $this->assertStringContainsString($area, (string) $email->getTextBody());
    }

    public function test_the_email_lists_the_jobs_as_they_are_when_it_is_sent()
    {
        Queue::fake();
        [$ss2] = $this->branches();
        $delivered = $this->job($ss2, pickedUp: true);
        $handedOver = $this->job($ss2);
        $staying = $this->job($ss2);

        app(SendRunSheets::class)->handle();

        // Before the queue worker sends it: one parcel delivered, one given to Wong.
        app(OrderStatusService::class)->transition($delivered, OrderStatus::Delivered, $this->driver, null, ['delivered_at' => now()]);
        $wong = User::factory()->driver()->create();
        app(AssignDriver::class)->handle($handedOver, User::factory()->admin()->create(), $wong, $this->day);

        $sheet = $this->queuedSheet();
        $this->assertTrue($sheet->shouldSend($this->driver, 'mail'));
        $mail = $sheet->toMail($this->driver);
        $this->assertSame('Your round for Monday, 5 October: 1 delivery', $mail->subject);
        $this->assertSame([$staying->formatted_tracking_number], array_column($mail->viewData['groups'][0]['jobs'], 'tracking_number'));

        // Once nothing is left, the email is not sent at all.
        app(AssignDriver::class)->handle($staying, User::factory()->admin()->create(), $wong, $this->day);
        $this->assertFalse($this->queuedSheet()->shouldSend($this->driver, 'mail'));
    }

    public function test_the_email_is_not_sent_once_its_day_is_over()
    {
        Queue::fake();
        [$ss2] = $this->branches();
        $this->job($ss2);

        app(SendRunSheets::class)->handle();
        $this->assertTrue($this->queuedSheet()->shouldSend($this->driver, 'mail'));

        // The worker was down overnight: tomorrow morning's sheet takes over.
        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:10', 'Asia/Kuala_Lumpur'));
        $this->assertFalse($this->queuedSheet()->shouldSend($this->driver, 'mail'));
    }

    public function test_the_email_is_not_sent_to_an_account_that_can_no_longer_be_emailed()
    {
        [$ss2] = $this->branches();
        $this->job($ss2);
        $sheet = new DriverRunSheet($this->day);
        $this->assertTrue($sheet->shouldSend($this->driver, 'mail'));

        // An address changed on Profile and not confirmed yet.
        $this->driver->forceFill(['email_verified_at' => null])->save();
        $this->assertFalse($sheet->shouldSend($this->driver, 'mail'));

        $this->driver->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
        $this->assertFalse($sheet->shouldSend($this->driver, 'mail'));
    }

    public function test_the_email_is_delivered_to_the_driver()
    {
        [$ss2] = $this->branches();
        $job = $this->job($ss2);

        app(SendRunSheets::class)->handle();

        $messages = $this->sentEmails();
        $this->assertCount(1, $messages);
        $this->assertSame('ravi@example.com', $messages[0]->getTo()[0]->getAddress());
        $this->assertSame('Your round for Monday, 5 October: 1 delivery', $messages[0]->getSubject());
        $this->assertStringContainsString($job->formatted_tracking_number, (string) $messages[0]->getHtmlBody());
        $this->assertStringContainsString($job->formatted_tracking_number, (string) $messages[0]->getTextBody());
    }

    /**
     * Get the run sheet queued for Ravi, as the queue worker would restore it.
     */
    private function queuedSheet(): DriverRunSheet
    {
        $job = Queue::pushed(SendQueuedNotifications::class)->first();
        $this->assertInstanceOf(SendQueuedNotifications::class, $job);
        $job = unserialize(serialize($job));
        $this->assertInstanceOf(DriverRunSheet::class, $job->notification);

        return $job->notification;
    }

    /**
     * Get the emails sent so far.
     *
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return array_values(array_map(function ($message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);

            return $email;
        }, $transport->messages()->all()));
    }

    /**
     * Create the SS2 and Bangsar South branches.
     *
     * @return list<Branch>
     */
    private function branches(): array
    {
        return [
            Branch::factory()->create([
                'name' => 'Petaling Jaya - SS2',
                'address' => '12, Jalan SS 2/67, SS 2',
                'city' => 'Petaling Jaya',
                'postcode' => '47300',
            ]),
            Branch::factory()->create([
                'name' => 'Bangsar South',
                'address' => 'Lot 3, Jalan Kerinchi',
                'city' => 'Kuala Lumpur',
                'postcode' => '59200',
            ]),
        ];
    }

    /**
     * Create one of Ravi's jobs for today, collected from the given branch.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function job(Branch $branch, array $attributes = [], bool $pickedUp = false): Order
    {
        $factory = Order::factory()->for($branch);

        return ($pickedUp ? $factory->pickedUp($this->driver) : $factory->assigned($this->driver))->create($attributes);
    }
}
