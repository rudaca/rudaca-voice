<?php

namespace App\Providers;

use App\Enums\Timezone;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->last_login_at = now();
                $event->user->saveQuietly();
            }
        });

        // The guard clears the authenticated user (and the session is
        // invalidated) before LogoutResponse builds its redirect, so the
        // team has to be captured here, while it's still available on the
        // event, and stashed in the container for LogoutResponse to read.
        Event::listen(function (Logout $event) {
            if ($event->user instanceof User) {
                $this->app->instance('logout.team', $event->user->currentTeam ?? $event->user->personalTeam());
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        $forUser = function () {
            /** @var Carbon|CarbonImmutable $this */
            return $this->setTimezone((Auth::user()?->timezone ?? Timezone::default())->value);
        };

        Carbon::macro('forUser', fn () => $forUser->call($this->copy()));
        CarbonImmutable::macro('forUser', $forUser);

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
    }
}
