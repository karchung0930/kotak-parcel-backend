<?php

namespace Database\Seeders;

use App\Actions\Delivery\AssignDriver;
use App\Actions\Delivery\MarkPickedUp;
use App\Actions\Delivery\RecordDeliveryFailure;
use App\Actions\Delivery\RecordDeliverySuccess;
use App\Actions\Delivery\ReturnToSender;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\CreateOrder;
use App\Actions\Orders\ExpireUnclaimedOrders;
use App\Actions\Orders\RecordDropOff;
use App\Actions\Payments\RecordPayment;
use App\Enums\DeliveryFailureReason;
use App\Enums\MalaysianState;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\Settings;
use App\Support\TrackingNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Demo data for local development and assessment: branches, staff, drivers,
 * customers and 24 orders in every status.
 *
 * Orders are driven through the real Actions (with the clock moved back in
 * time), so their history, payments and delivery attempts are consistent.
 * Every account uses the password "password", so this never runs in production.
 */
class DemoSeeder extends Seeder
{
    /**
     * The moment seeding started; the demo timeline never goes past it.
     */
    private CarbonImmutable $now;

    /** @var array<string, Branch> */
    private array $branches = [];

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<string, User> */
    private array $drivers = [];

    /** @var array<string, User> */
    private array $customers = [];

    private User $admin;

    /**
     * Create a new seeder instance.
     */
    public function __construct(
        private CreateOrder $createOrder,
        private RecordDropOff $recordDropOff,
        private RecordPayment $recordPayment,
        private CancelOrder $cancelOrder,
        private AssignDriver $assignDriver,
        private MarkPickedUp $markPickedUp,
        private RecordDeliverySuccess $recordDeliverySuccess,
        private RecordDeliveryFailure $recordDeliveryFailure,
        private ReturnToSender $returnToSender,
        private ExpireUnclaimedOrders $expireUnclaimedOrders,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder must never run in production.');
        }

        $this->now = CarbonImmutable::now();

        Storage::disk('local')->deleteDirectory('pod');

        try {
            $this->travelTo(30, '09:00');
            $this->seedBranches();
            $this->seedUsers();

            foreach ($this->orders() as $i => $scenario) {
                // Fixed tracking numbers (KT-00000002, KT-00000003, ...) so the demo can be followed from the README.
                $scenario['tracking_number'] ??= sprintf('%s%08d', TrackingNumber::PREFIX, $i + 1);
                $this->seedOrder($scenario);
            }
        } finally {
            Date::setTestNow();
        }
    }

    /**
     * Seed six Klang Valley branches.
     */
    private function seedBranches(): void
    {
        $branches = [
            ['PJ-SS2', 'Petaling Jaya - SS2', '12, Jalan SS 2/67, SS 2', 'Petaling Jaya', MalaysianState::Selangor, '47300', '03-7877 1203', 3.1185, 101.6225, 'Mon-Sat 9:00-21:00, Sun 10:00-18:00'],
            ['KL-BSR', 'Bangsar South', 'Ground Floor, Nexus, No. 7 Jalan Kerinchi', 'Kuala Lumpur', MalaysianState::KualaLumpur, '59200', '03-2242 0107', 3.1107, 101.6655, 'Mon-Fri 8:30-20:00, Sat 9:00-17:00'],
            ['KL-MVC', 'Mid Valley', 'LG-088, Mid Valley Megamall, Lingkaran Syed Putra', 'Kuala Lumpur', MalaysianState::KualaLumpur, '59200', '03-2938 3088', 3.1178, 101.6772, 'Daily 10:00-22:00'],
            ['SJ-SS15', 'Subang Jaya - SS15', '21, Jalan SS 15/4D, SS 15', 'Subang Jaya', MalaysianState::Selangor, '47500', '03-5612 1521', 3.0763, 101.5883, 'Mon-Sat 9:00-21:00, Sun 10:00-18:00'],
            ['KL-TCN', 'Cheras - Taman Connaught', '38, Jalan Cerdas, Taman Connaught', 'Cheras', MalaysianState::KualaLumpur, '56000', '03-9102 3838', 3.0790, 101.7390, 'Mon-Sat 9:00-21:00'],
            ['SA-S13', 'Shah Alam - Seksyen 13', '9, Jalan Kristal 13/7, Seksyen 13', 'Shah Alam', MalaysianState::Selangor, '40100', '03-5523 1309', 3.0850, 101.5390, 'Mon-Sat 9:00-20:00, Sun 10:00-17:00'],
        ];

        foreach ($branches as [$code, $name, $address, $city, $state, $postcode, $phone, $latitude, $longitude, $hours]) {
            $this->branches[$code] = Branch::create([
                'code' => $code,
                'name' => $name,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'postcode' => $postcode,
                'phone' => $phone,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'opening_hours' => $hours,
                'is_active' => true,
            ]);
        }
    }

    /**
     * Seed the admin, branch staff, drivers and customers (all with password "password").
     */
    private function seedUsers(): void
    {
        $this->admin = User::factory()->admin()->create([
            'name' => 'Farah Aziz',
            'email' => 'admin@kotak.test',
            'phone' => '+60122000001',
        ]);

        $staff = [
            ['PJ-SS2', 'Nurul Huda', 'staff.pj@kotak.test'],
            ['KL-BSR', 'Aina Sofea', 'staff.bangsar@kotak.test'],
            ['KL-MVC', 'Lim Mei Ling', 'staff.midvalley@kotak.test'],
            ['SJ-SS15', 'Arun Pillai', 'staff.subang@kotak.test'],
            ['KL-TCN', 'Hafiz Rahim', 'staff.cheras@kotak.test'],
            ['SA-S13', 'Tan Siew Lan', 'staff.shahalam@kotak.test'],
            ['PJ-SS2', 'Kevin Ong', 'staff2.pj@kotak.test'],
        ];

        foreach ($staff as $i => [$code, $name, $email]) {
            $user = User::factory()->staff($this->branches[$code])->create([
                'name' => $name,
                'email' => $email,
                'phone' => sprintf('+6012300%04d', $i + 1),
            ]);

            $this->staff[$code] ??= $user;
        }

        $drivers = [
            ['ravi', 'Ravi Kumar', 'WXA 1234'],
            ['faizal', 'Ahmad Faizal', 'BKM 5521'],
            ['wong', 'Wong Kah Wai', 'VFD 8812'],
            ['siti', 'Siti Nora', 'WTT 3390'],
        ];

        foreach ($drivers as $i => [$key, $name, $plate]) {
            $this->drivers[$key] = User::factory()->driver()->create([
                'name' => $name,
                'email' => "driver.{$key}@kotak.test",
                'phone' => sprintf('+6019400%04d', $i + 1),
                'vehicle_plate' => $plate,
            ]);
        }

        $customers = [
            ['aisyah', 'Aisyah Rahman', '+60123456789'],
            ['jason', 'Jason Tan', '+60162345678'],
            ['priya', 'Priya Nair', '+60193456789'],
        ];

        foreach ($customers as [$key, $name, $phone]) {
            $this->customers[$key] = User::factory()->customer()->create([
                'name' => $name,
                'email' => "{$key}@kotak.test",
                'phone' => $phone,
            ]);
        }
    }

    /**
     * Drive one order through the Actions until it reaches its target status.
     *
     * @param  array<string, mixed>  $scenario
     */
    private function seedOrder(array $scenario): void
    {
        /** @var OrderStatus $target */
        $target = $scenario['status'];
        /** @var int $day */
        $day = $scenario['days_ago'];
        $customer = $this->customers[$scenario['customer']];
        $counter = $this->staff[$scenario['branch']];
        $driver = $this->drivers[$scenario['driver'] ?? 'ravi'];

        // Some customers order a few days before they bring the parcel in.
        $this->travelTo($day + ($scenario['ordered_days_before'] ?? 0), '10:00');
        $order = $this->createOrder->handle($customer, [
            'branch_id' => $this->branches[$scenario['branch']]->id,
            ...$scenario['order'],
        ]);

        if (isset($scenario['tracking_number'])) {
            // Every demo order has a fixed tracking number, listed in the README.
            $order->forceFill(['tracking_number' => $scenario['tracking_number']])->save();
        }

        if ($target === OrderStatus::Created) {
            return;
        }

        if ($scenario['expired'] ?? false) {
            // Never dropped off: the nightly job cancels it at midnight after the deadline day.
            $this->travelTo($day - app(Settings::class)->unclaimedOrderDays() - 1, '00:00');
            $this->expireUnclaimedOrders->handle();

            return;
        }

        if ($target === OrderStatus::Cancelled && ! ($scenario['refused_price'] ?? false)) {
            $this->travelTo($day, '18:00');
            $this->cancelOrder->handle($order, $customer, 'Sent it by hand instead.');

            return;
        }

        $this->travelTo($day, '13:30');
        $order = $this->recordDropOff->handle($order, $counter, $scenario['measured_weight_g'] ?? $order->declared_weight_g);

        if ($target === OrderStatus::Cancelled) {
            $this->travelTo($day, '13:40');
            $this->cancelOrder->handle($order, $counter);

            return;
        }

        if ($target === OrderStatus::DroppedOff) {
            return;
        }

        $this->travelTo($day, '13:35');
        $this->recordPayment->handle(
            $order,
            $counter,
            $scenario['payment'] ?? PaymentMethod::Cash,
            (int) $order->final_price_sen,
            ($scenario['payment'] ?? null) === PaymentMethod::Card ? 'APR'.substr($order->tracking_number, -4) : null,
        );

        if ($target === OrderStatus::Paid) {
            return;
        }

        if (in_array($target, [OrderStatus::Assigned, OrderStatus::PickedUp], true)) {
            $this->dispatchTo($order, $driver, 0);

            if ($target === OrderStatus::PickedUp) {
                $this->travelTo(0, '09:15');
                $this->markPickedUp->handle($order, $driver);
            }

            return;
        }

        // Finished journeys: one delivery attempt per day, from the day after drop-off.
        $attemptDay = $day - 1;

        /** @var list<DeliveryFailureReason> $failures */
        $failures = $scenario['failures'] ?? [];

        foreach ($failures as $reason) {
            $this->dispatchTo($order, $driver, $attemptDay);
            $this->travelTo($attemptDay, '09:00');
            $this->markPickedUp->handle($order, $driver);
            $this->travelTo($attemptDay, '15:00');
            $this->recordDeliveryFailure->handle($order, $driver, $reason, 'Called the receiver twice, no answer.');
            $attemptDay--;
        }

        if ($target === OrderStatus::ReturnedToSender) {
            $this->travelTo($attemptDay, '10:00');
            $this->returnToSender->handle($order, $this->admin);
        }

        if ($target === OrderStatus::Delivered) {
            $this->dispatchTo($order, $driver, $attemptDay);
            $this->travelTo($attemptDay, '09:00');
            $this->markPickedUp->handle($order, $driver);
            $this->travelTo($attemptDay, '14:00');
            $this->recordDeliverySuccess->handle($order, $driver, $order->receiver_name, $this->proofPhoto());
        }
    }

    /**
     * Have the admin schedule the order with the driver at 8am on the given day.
     */
    private function dispatchTo(Order $order, User $driver, int $daysAgo): void
    {
        $this->travelTo($daysAgo, '08:00');
        $this->assignDriver->handle($order, $this->admin, $driver, $this->at($daysAgo, '08:00'));
    }

    /**
     * Move the clock to a Malaysian local time on a day in the past.
     */
    private function travelTo(int $daysAgo, string $time): void
    {
        Date::setTestNow($this->at($daysAgo, $time)->utc());
    }

    /**
     * Get a Malaysian local time on a day in the past, never later than when seeding started.
     */
    private function at(int $daysAgo, string $time): CarbonImmutable
    {
        $timezone = config()->string('kotak.timezone');
        $moment = $this->now->setTimezone($timezone)->startOfDay()->subDays($daysAgo)->setTimeFromTimeString($time);

        return $moment->min($this->now->subMinute()->setTimezone($timezone));
    }

    /**
     * Get the placeholder proof of delivery photo (the action copies it to private storage).
     */
    private function proofPhoto(): UploadedFile
    {
        return new UploadedFile(__DIR__.'/assets/proof-of-delivery.png', 'proof-of-delivery.png', 'image/png', null, true);
    }

    /**
     * The demo orders, covering every status.
     *
     * @return list<array<string, mixed>>
     */
    private function orders(): array
    {
        return [
            // The sample parcel from the brief: out for delivery today.
            [
                'customer' => 'aisyah', 'branch' => 'PJ-SS2', 'status' => OrderStatus::PickedUp, 'days_ago' => 2,
                'driver' => 'ravi', 'payment' => PaymentMethod::Card, 'tracking_number' => 'KT7Q4M92XD',
                'order' => $this->parcel('Daniel Lim', '+60127788990', 'No. 12, Jalan Datuk Sulaiman 1', 'Taman Tun Dr Ismail', 'Kuala Lumpur', MalaysianState::KualaLumpur, '60000', 'Ceramic dinner set', 4200, 40, 30, 25),
            ],
            [
                'customer' => 'aisyah', 'branch' => 'KL-MVC', 'status' => OrderStatus::Created, 'days_ago' => 0,
                'order' => $this->parcel('Nur Izzati Hassan', '+60172345601', 'Unit 18-3, Residensi Sentral', 'Jalan Tun Sambanthan', 'Kuala Lumpur', MalaysianState::KualaLumpur, '50470', 'Batik scarves', 600, 30, 20, 5),
            ],
            [
                'customer' => 'aisyah', 'branch' => 'PJ-SS2', 'status' => OrderStatus::Delivered, 'days_ago' => 6, 'ordered_days_before' => 2,
                'driver' => 'faizal', 'payment' => PaymentMethod::Cash,
                'order' => $this->parcel('Chong Wei Liang', '+60163456702', '8, Lorong Maarof', 'Bangsar Park', 'Kuala Lumpur', MalaysianState::KualaLumpur, '59000', 'Mechanical keyboard', 1500, 45, 16, 6),
            ],
            [
                'customer' => 'aisyah', 'branch' => 'PJ-SS2', 'status' => OrderStatus::Paid, 'days_ago' => 1, 'ordered_days_before' => 1,
                'payment' => PaymentMethod::Card,
                'order' => $this->parcel('Rosli Ismail', '+60134567803', 'No. 5, Jalan Meru', 'Taman Meru', 'Klang', MalaysianState::Selangor, '41050', 'Rice cooker', 2800, 35, 35, 30),
            ],
            [
                'customer' => 'aisyah', 'branch' => 'KL-BSR', 'status' => OrderStatus::DeliveryFailed, 'days_ago' => 4, 'ordered_days_before' => 3,
                'driver' => 'wong', 'failures' => [DeliveryFailureReason::RecipientUnavailable],
                'order' => $this->parcel('Faris Hakimi', '+60195678904', '22, Jalan Kenari 5', 'Bandar Puchong Jaya', 'Puchong', MalaysianState::Selangor, '47100', 'Office chair cushion', 1900, 45, 45, 12),
            ],
            [
                'customer' => 'aisyah', 'branch' => 'SJ-SS15', 'status' => OrderStatus::Cancelled, 'days_ago' => 3,
                'order' => $this->parcel('Goh Mei Ling', '+60128765432', '14, Jalan Temiang', null, 'Seremban', MalaysianState::NegeriSembilan, '70200', 'Cake stand', 1200, 30, 30, 20),
            ],
            [
                'customer' => 'jason', 'branch' => 'SJ-SS15', 'status' => OrderStatus::Created, 'days_ago' => 1,
                'order' => $this->parcel('Amanda Lau', '+60126789005', '3, Jalan USJ 9/5Q', null, 'Subang Jaya', MalaysianState::Selangor, '47620', 'Board games', 2100, 40, 30, 10),
            ],
            [
                'customer' => 'jason', 'branch' => 'KL-TCN', 'status' => OrderStatus::DroppedOff, 'days_ago' => 0, 'ordered_days_before' => 1,
                'measured_weight_g' => 7550,
                'order' => $this->parcel('Kumar Selvam', '+60137890106', '71, Jalan Sultan Iskandar', null, 'Ipoh', MalaysianState::Perak, '30000', 'Car parts', 7400, 50, 30, 20),
            ],
            [
                'customer' => 'jason', 'branch' => 'KL-TCN', 'status' => OrderStatus::Paid, 'days_ago' => 1,
                'payment' => PaymentMethod::Cash,
                'order' => $this->parcel('Lim Boon Hock', '+60168901207', '15, Lebuh Chulia', null, 'George Town', MalaysianState::PulauPinang, '10200', 'Biscuit gift box', 3200, 35, 25, 20),
            ],
            [
                'customer' => 'jason', 'branch' => 'KL-MVC', 'status' => OrderStatus::Assigned, 'days_ago' => 2,
                'driver' => 'siti', 'payment' => PaymentMethod::Card,
                'order' => $this->parcel('Sarah Abdullah', '+60179012308', 'C-12-5, Pangsapuri Seri Mas', 'Jalan Awan Hijau', 'Kuala Lumpur', MalaysianState::KualaLumpur, '58200', 'Laptop sleeve', 700, 40, 30, 4),
            ],
            [
                'customer' => 'jason', 'branch' => 'KL-TCN', 'status' => OrderStatus::Delivered, 'days_ago' => 8, 'ordered_days_before' => 4,
                'driver' => 'wong', 'payment' => PaymentMethod::Cash,
                'order' => $this->parcel('Tan Ah Kow', '+60120123409', '9, Jalan Bunga Raya', null, 'Melaka', MalaysianState::Melaka, '75100', 'Pineapple tarts', 1600, 30, 25, 15),
            ],
            [
                'customer' => 'jason', 'branch' => 'SA-S13', 'status' => OrderStatus::ReturnedToSender, 'days_ago' => 9, 'ordered_days_before' => 2,
                'driver' => 'faizal', 'payment' => PaymentMethod::Card,
                'failures' => [DeliveryFailureReason::RecipientUnavailable, DeliveryFailureReason::NoAccess, DeliveryFailureReason::RecipientUnavailable],
                'order' => $this->parcel('Brian Teo', '+60131234510', 'Lot 7, Jalan Lintas', 'Luyang', 'Kota Kinabalu', MalaysianState::Sabah, '88300', 'Hiking boots', 2300, 40, 30, 15),
            ],
            [
                'customer' => 'jason', 'branch' => 'SA-S13', 'status' => OrderStatus::PickedUp, 'days_ago' => 1,
                'driver' => 'faizal', 'payment' => PaymentMethod::Cash,
                'order' => $this->parcel('Hafizah Omar', '+60192345611', '4, Jalan Plumbum N7/N', 'Seksyen 7', 'Shah Alam', MalaysianState::Selangor, '40000', 'Handmade soap set', 900, 20, 15, 10),
            ],
            [
                'customer' => 'priya', 'branch' => 'KL-BSR', 'status' => OrderStatus::Created, 'days_ago' => 2,
                'order' => $this->parcel('Deepa Menon', '+60143456712', '11, Jalan Pantai Baru', null, 'Kuala Lumpur', MalaysianState::KualaLumpur, '59200', 'Silk saree', 900, 35, 25, 6),
            ],
            [
                'customer' => 'priya', 'branch' => 'KL-BSR', 'status' => OrderStatus::DroppedOff, 'days_ago' => 0,
                'order' => $this->parcel('Rajesh Nair', '+60124567813', '27, Jalan Dato Onn', null, 'Johor Bahru', MalaysianState::Johor, '80000', 'Brass lamp', 3600, 30, 30, 40),
            ],
            [
                'customer' => 'priya', 'branch' => 'SA-S13', 'status' => OrderStatus::Paid, 'days_ago' => 2, 'ordered_days_before' => 1,
                'payment' => PaymentMethod::Card,
                'order' => $this->parcel('Aaron Wong', '+60165678914', '6, Jalan Galing', null, 'Kuantan', MalaysianState::Pahang, '25200', 'Camera tripod', 2600, 65, 15, 15),
            ],
            [
                'customer' => 'priya', 'branch' => 'SJ-SS15', 'status' => OrderStatus::Assigned, 'days_ago' => 3,
                'driver' => 'ravi', 'payment' => PaymentMethod::Cash,
                'order' => $this->parcel('Nadia Karim', '+60176789015', '8, Jalan P9E/2', 'Presint 9', 'Putrajaya', MalaysianState::Putrajaya, '62250', 'Tea set', 2200, 35, 30, 25),
            ],
            [
                'customer' => 'priya', 'branch' => 'PJ-SS2', 'status' => OrderStatus::Assigned, 'days_ago' => 2,
                'driver' => 'ravi', 'payment' => PaymentMethod::Card,
                'order' => $this->parcel('Vincent Yeoh', '+60137890116', '2, Jalan 17/1', 'Seksyen 17', 'Petaling Jaya', MalaysianState::Selangor, '46400', 'Coffee grinder', 2400, 30, 20, 35),
            ],
            [
                'customer' => 'priya', 'branch' => 'KL-MVC', 'status' => OrderStatus::Delivered, 'days_ago' => 5, 'ordered_days_before' => 2,
                'driver' => 'siti', 'payment' => PaymentMethod::Cash,
                'order' => $this->parcel('Ong Siew Mei', '+60182901217', '18, Persiaran Multimedia', null, 'Cyberjaya', MalaysianState::Selangor, '63000', 'Monitor stand', 5200, 60, 30, 15),
            ],
            [
                'customer' => 'priya', 'branch' => 'PJ-SS2', 'status' => OrderStatus::DeliveryFailed, 'days_ago' => 10, 'ordered_days_before' => 5,
                'driver' => 'wong', 'payment' => PaymentMethod::Cash,
                'failures' => [DeliveryFailureReason::RecipientUnavailable, DeliveryFailureReason::AddressNotFound, DeliveryFailureReason::RecipientUnavailable],
                'order' => $this->parcel('Zulkifli Ahmad', '+60139012318', '3, Jalan Kampung Baru', null, 'Sungai Buloh', MalaysianState::Selangor, '47000', 'Cast iron cookware', 6800, 45, 35, 30),
            ],
            [
                // Bulky but light: the customer refuses the volumetric price at the counter.
                'customer' => 'priya', 'branch' => 'KL-TCN', 'status' => OrderStatus::Cancelled, 'days_ago' => 1,
                'refused_price' => true,
                'order' => $this->parcel('Stephanie Lee', '+60120123419', '9, Jalan Setia Nusantara', 'Setia Eco Park', 'Shah Alam', MalaysianState::Selangor, '40170', 'Floor lamp', 3000, 120, 30, 30),
            ],
            // Waiting for drop-off for 5 days: due the reminder, 2 days before the deadline (by default).
            [
                'customer' => 'jason', 'branch' => 'KL-BSR', 'status' => OrderStatus::Created, 'days_ago' => 5,
                'order' => $this->parcel('Melissa Chan', '+60125566778', '21, Jalan Kenanga 3', 'Taman Kenanga', 'Seremban', MalaysianState::NegeriSembilan, '70200', 'Picture frames', 1800, 50, 40, 8),
            ],
            [
                'customer' => 'priya', 'branch' => 'SJ-SS15', 'status' => OrderStatus::Created, 'days_ago' => 5,
                'order' => $this->parcel('Harith Iskandar', '+60174455661', '5, Jalan Bukit Tinggi 2', 'Bukit Tinggi', 'Klang', MalaysianState::Selangor, '41200', 'School books', 3400, 35, 25, 20),
            ],
            // Never dropped off: cancelled automatically after its deadline.
            [
                'customer' => 'aisyah', 'branch' => 'KL-MVC', 'status' => OrderStatus::Cancelled, 'days_ago' => 12,
                'expired' => true,
                'order' => $this->parcel('Nurul Ain Zakaria', '+60193344552', '17, Jalan Wangsa 2/3', 'Wangsa Maju', 'Kuala Lumpur', MalaysianState::KualaLumpur, '53300', 'Wall clock', 1300, 35, 35, 8),
            ],
        ];
    }

    /**
     * Build the customer-entered part of an order.
     *
     * @return array<string, mixed>
     */
    private function parcel(
        string $receiverName,
        string $receiverPhone,
        string $addressLine1,
        ?string $addressLine2,
        string $city,
        MalaysianState $state,
        string $postcode,
        string $itemName,
        int $weightGrams,
        int $lengthCm,
        int $widthCm,
        int $heightCm,
    ): array {
        return [
            'receiver_name' => $receiverName,
            'receiver_phone' => $receiverPhone,
            'address_line1' => $addressLine1,
            'address_line2' => $addressLine2,
            'city' => $city,
            'state' => $state,
            'postcode' => $postcode,
            'item_name' => $itemName,
            'declared_weight_g' => $weightGrams,
            'length_cm' => $lengthCm,
            'width_cm' => $widthCm,
            'height_cm' => $heightCm,
        ];
    }
}
