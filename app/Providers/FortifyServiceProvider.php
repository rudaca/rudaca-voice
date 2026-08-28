<?php

namespace App\Providers;

use App\Actions\Auth\RedirectToDefaultOrganizationLogin;
use App\Actions\Fortify\ResetUserPassword;
use App\Enums\IdentityProvider;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\LogoutResponse;
use App\Http\Responses\VerifyEmailResponse;
use App\Models\TeamIdentityProvider;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where(Fortify::username(), $request->input(Fortify::username()))->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return null;
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    Fortify::username() => [__('Your account has been deactivated. Contact your administrator for access.')],
                ]);
            }

            if ($user->requiresSsoSignIn()) {
                throw ValidationException::withMessages([
                    Fortify::username() => [__('Your organization requires Microsoft sign-in. Please use your organization\'s sign-in page instead of a password.')],
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            if ($url = app(RedirectToDefaultOrganizationLogin::class)->handle()) {
                return redirect($url);
            }

            return view('pages::auth.login', [
                'teamInvitation' => $this->teamInvitation($request),
                'showMicrosoft' => TeamIdentityProvider::anyConfiguredFor(IdentityProvider::Microsoft),
            ]);
        });
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('owner-recovery-request', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perHour(3)->by($throttleKey);
        });

        RateLimiter::for('owner-recovery-verify', function (Request $request) {
            // Deliberately higher than OwnerRecoveryToken::MAX_ATTEMPTS (5) —
            // that per-token cap is the real defense against guessing one
            // token's code; this is a coarser, per-IP backstop against
            // spraying attempts across many different tokens.
            return Limit::perMinutes(15, 10)->by($request->ip());
        });
    }

    /**
     * Get the pending team invitation context for auth pages.
     *
     * @return array{code: string, teamName: string}|null
     */
    private function teamInvitation(Request $request): ?array
    {
        $invitationCode = $request->query('invitation');

        if (! is_string($invitationCode)) {
            return null;
        }

        $invitation = TeamInvitation::query()
            ->with('team')
            ->where('code', $invitationCode)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->first();

        if (! $invitation) {
            return null;
        }

        return [
            'code' => $invitation->code,
            'teamName' => $invitation->team->name,
        ];
    }
}
