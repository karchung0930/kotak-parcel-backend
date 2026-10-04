<?php

namespace App\Providers;

use App\Support\RateCards;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request or queued job: the settings and rate cards
        // are read once per request, and a long-running worker still sees changes.
        $this->app->scoped(Settings::class);
        $this->app->scoped(RateCards::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureMail();
        $this->skipReservedTestAddresses();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        if (filled($proxies = config('kotak.trusted_proxies'))) {
            TrustProxies::at($proxies);
            TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR);
        }

        // Inertia props are resources; send them without the {"data": ...} wrapper.
        JsonResource::withoutWrapping();
    }

    /**
     * Configure the named rate limiters used by routes.
     */
    protected function configureRateLimiting(): void
    {
        // Public tracking lookups, per visitor, to stop tracking number enumeration.
        RateLimiter::for('tracking', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Order creation, per customer.
        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        // Rate imports: each upload, sheet or mapping keeps a file or queues a job, per admin.
        RateLimiter::for('rate-imports', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Keep users' own text in emails as plain text.
     *
     * Markdown emails escape HTML in {{ }} echoes but not Markdown, so a
     * "[text](url)" typed into an order would become a link in another
     * user's email. Secured encoding escapes "[" as well. It applies when the
     * mail views are compiled while an email renders; views compiled ahead
     * (view:cache) keep the plain HTML escaping, so text from users is also
     * made safe where it is built, such as Order::deliveryArea().
     */
    protected function configureMail(): void
    {
        Markdown::withSecuredEncoding();
    }

    /**
     * Never send mail that only goes to reserved .test addresses (the demo accounts).
     *
     * Those domains cannot exist, so a real mailer such as SES would record a bounce
     * for each one and the account's reputation would suffer. Real addresses, like
     * someone who signs up on the demo site, still get their mail.
     */
    protected function skipReservedTestAddresses(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event): ?bool {
            $recipients = [...$event->message->getTo(), ...$event->message->getCc(), ...$event->message->getBcc()];

            foreach ($recipients as $address) {
                if (! str_ends_with(strtolower($address->getAddress()), '.test')) {
                    return null;
                }
            }

            return $recipients === [] ? null : false;
        });
    }
}
