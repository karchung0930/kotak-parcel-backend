<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\MailDate;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Str;

/**
 * A driver's run sheet, emailed every morning by
 * App\Actions\Delivery\SendRunSheets: how many deliveries are on today's
 * round, then the parcels already on the van and the ones to collect, by
 * pickup branch, each with its area and status. It is the same list as My
 * jobs, without the receivers' details, which stay behind the sign-in.
 *
 * The list is read when the email is sent, not when it is queued, so a
 * delayed email never lists a job delivered or handed to another driver
 * in the meantime.
 */
#[Tries(3)]
#[Backoff(60)]
#[DeleteWhenMissingModels]
class DriverRunSheet extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The jobs shouldSend() found for each driver, for toMail() to list.
     *
     * @var array<int, Collection<int, Order>>
     */
    private array $jobs = [];

    /**
     * Create a new notification instance.
     *
     * @param  CarbonInterface  $day  the Malaysian day the round is for
     */
    public function __construct(
        public CarbonInterface $day,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Determine if the email should still go out: only on its own day, while
     * the driver still has open jobs, and to an account that can still be
     * emailed (not deactivated, nor an address changed and not confirmed).
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        if (! $notifiable->canBeEmailed() || $this->day->toDateString() !== today(config()->string('kotak.timezone'))->toDateString()) {
            return false;
        }

        $this->jobs[$notifiable->id] = $this->jobsFor($notifiable);

        return $this->jobs[$notifiable->id]->isNotEmpty();
    }

    /**
     * Get the driver's open jobs for the day as they are now, in My jobs order.
     *
     * @return Collection<int, Order>
     */
    public function jobsFor(User $driver): Collection
    {
        return Order::query()->jobListFor($driver, $this->day)->with('branch')->get();
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(User $notifiable): MailMessage
    {
        // The list shouldSend() checked, so the email shows what it decided on.
        $jobs = $this->jobs[$notifiable->id] ?? $this->jobsFor($notifiable);
        unset($this->jobs[$notifiable->id]);

        $count = $jobs->count();
        $overdue = $jobs->filter(fn (Order $job) => $job->isOverdue())->count();
        $deliveries = $count.' '.Str::plural('delivery', $count);

        return (new MailMessage)
            ->subject("Your round for {$this->day->format('l, j F')}: {$deliveries}")
            ->greeting("Hi {$notifiable->name},")
            ->line(sprintf(
                'You have **%s** today%s.',
                $deliveries,
                match (true) {
                    $overdue === 1 => ', 1 carried over from an earlier day',
                    $overdue > 1 => ", {$overdue} carried over from earlier days",
                    default => '',
                },
            ))
            ->line("The receivers' names, addresses and phone numbers are on My jobs.")
            ->action('Open My jobs', route('driver.jobs'))
            ->markdown('mail.driver-run-sheet', ['groups' => $this->groups($jobs)]);
    }

    /**
     * Group the jobs as the email lists them: the parcels already on the van
     * first, then the ones to collect, by pickup branch in name order. Each
     * group keeps the My jobs order.
     *
     * @param  Collection<int, Order>  $jobs
     * @return array<int, array{title: string, parcels: string, address: string|null, jobs: array<int, array{tracking_number: string, area: string, status: string, overdue: string|null}>}>
     */
    public function groups(Collection $jobs): array
    {
        [$onTheVan, $toCollect] = $jobs->partition(fn (Order $job) => $job->status === OrderStatus::PickedUp);

        // A stable sort, so the jobs of each branch stay in My jobs order.
        $groups = $toCollect
            ->sortBy(fn (Order $job) => $job->branch->name)
            ->groupBy('branch_id')
            ->map(function (Collection $jobs): array {
                /** @var Order $first */
                $first = $jobs->first();
                $branch = $first->branch;

                return $this->group(
                    'Collect from '.$this->unbrokenBranchName($branch->name),
                    $branch->mailAddress(),
                    $jobs,
                );
            })
            ->values();

        if ($onTheVan->isNotEmpty()) {
            $groups->prepend($this->group('Already on your van', null, $onTheVan));
        }

        return $groups->all();
    }

    /**
     * Join a branch name with no-break spaces, as BranchName does on the
     * site, so a narrow screen wraps it only after its dash ("Cheras - /
     * Taman Connaught") and never leaves its last word alone.
     */
    private function unbrokenBranchName(string $name): string
    {
        $dash = strrpos($name, ' - ');
        $unbroken = fn (string $part) => str_replace(' ', "\u{00A0}", $part);

        return $dash === false
            ? $unbroken($name)
            : substr($name, 0, $dash)."\u{00A0}- ".$unbroken(substr($name, $dash + 3));
    }

    /**
     * Build one group of the list, with a row for each job.
     *
     * @param  Collection<int, Order>  $jobs
     * @return array{title: string, parcels: string, address: string|null, jobs: array<int, array{tracking_number: string, area: string, status: string, overdue: string|null}>}
     */
    private function group(string $title, ?string $address, Collection $jobs): array
    {
        return [
            'title' => $title,
            'parcels' => $jobs->count().' '.Str::plural('parcel', $jobs->count()),
            'address' => $address,
            'jobs' => $jobs->map(fn (Order $job): array => [
                'tracking_number' => $job->formatted_tracking_number,
                'area' => $job->deliveryArea(),
                'status' => $job->status->label(),
                // As the job card on My jobs puts it.
                'overdue' => $job->isOverdue() && $job->scheduled_for !== null
                    ? 'Overdue, was due '.MailDate::short($job->scheduled_for)
                    : null,
            ])->values()->all(),
        ];
    }
}
