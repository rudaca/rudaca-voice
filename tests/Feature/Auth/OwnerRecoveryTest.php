<?php

use App\Enums\OwnerRecoveryAuditAction;
use App\Enums\TeamRole;
use App\Models\OwnerRecoveryAudit;
use App\Models\OwnerRecoveryToken;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Auth\OwnerRecoveryRequested;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The rate limiter hashes its cache key as md5($limiterName.$limitKey)
 * (Illuminate\Routing\Middleware\ThrottleRequests::$shouldHashKeys, on by
 * default) — this reproduces that exact derivation so tests can reset a
 * named limiter's bucket between runs.
 */
function ownerRecoveryLimiterKey(string $limiterName, string $limitKey): string
{
    return md5($limiterName.$limitKey);
}

beforeEach(function () {
    // The 'owner-recovery-verify' limiter is keyed by IP only (not by
    // token), and the array cache store used in tests persists across
    // tests within the same run — reset it so one test's confirm() calls
    // never bleed into another's rate-limit budget.
    RateLimiter::clear(ownerRecoveryLimiterKey('owner-recovery-verify', '127.0.0.1'));
});

/**
 * Request recovery and capture the token + plaintext one-time code that
 * would have been emailed, without actually sending mail.
 *
 * @return array{token: OwnerRecoveryToken, code: string}
 */
function requestOwnerRecovery(Team $team, string $email): array
{
    Notification::fake();

    test()->post(route('org.recovery.store', $team), ['email' => $email]);

    $captured = [];

    Notification::assertSentTo($team->owner(), OwnerRecoveryRequested::class, function ($notification) use (&$captured) {
        $captured = ['token' => $notification->token, 'code' => $notification->code];

        return true;
    });

    return $captured;
}

test('an owner can request recovery and receives a one-time code by email', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Notification::fake();

    $response = $this->post(route('org.recovery.store', $team), ['email' => $owner->email]);

    $response->assertRedirect(route('org.recovery', $team));

    Notification::assertSentTo($owner, OwnerRecoveryRequested::class);

    $token = OwnerRecoveryToken::where('team_id', $team->id)->sole();
    expect($token->user_id)->toBe($owner->id)
        ->and($token->isUsable())->toBeTrue();

    expect(OwnerRecoveryAudit::where('team_id', $team->id)->pluck('action'))
        ->toContain(OwnerRecoveryAuditAction::Requested)
        ->toContain(OwnerRecoveryAuditAction::CodeSent);
});

test('requesting recovery for an email that is not this team\'s owner sends nothing and is silently audited', function () {
    ['team' => $team] = teamWithMember(TeamRole::Owner);
    $stranger = User::factory()->create();

    Notification::fake();

    $this->post(route('org.recovery.store', $team), ['email' => $stranger->email]);

    Notification::assertNothingSent();
    expect(OwnerRecoveryToken::where('team_id', $team->id)->count())->toBe(0);

    $audit = OwnerRecoveryAudit::where('team_id', $team->id)->sole();
    expect($audit->action)->toBe(OwnerRecoveryAuditAction::DeniedNotOwner)
        ->and($audit->user_id)->toBeNull();
});

test('a regular employee\'s email cannot be used to start recovery even though they belong to the team', function () {
    ['team' => $team] = teamWithMember(TeamRole::Owner);
    $employee = User::factory()->create();
    $team->members()->attach($employee, ['role' => TeamRole::Employee->value]);

    Notification::fake();

    $this->post(route('org.recovery.store', $team), ['email' => $employee->email]);

    Notification::assertNothingSent();
    expect(OwnerRecoveryToken::where('team_id', $team->id)->count())->toBe(0);
});

test('the recovery request response is identical whether or not the email matched an owner', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $stranger = User::factory()->create();

    Notification::fake();

    $matched = $this->post(route('org.recovery.store', $team), ['email' => $owner->email]);
    $unmatched = $this->post(route('org.recovery.store', $team), ['email' => $stranger->email]);

    expect($matched->getSession()->get('status'))->toBe($unmatched->getSession()->get('status'));
});

test('entering the correct one-time code logs the owner in and marks the token used', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    ['token' => $token, 'code' => $code] = requestOwnerRecovery($team, $owner->email);

    $response = $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => $code]);

    $response->assertRedirect(route('ideas.settings', ['current_team' => $team->slug, 'tab' => 'authentication']));
    $this->assertAuthenticatedAs($owner);

    expect($token->fresh()->isUsed())->toBeTrue();
    expect(OwnerRecoveryAudit::where('team_id', $team->id)->where('action', OwnerRecoveryAuditAction::Succeeded)->exists())->toBeTrue();
});

test('the wrong code is rejected without logging anyone in', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    ['token' => $token] = requestOwnerRecovery($team, $owner->email);

    $response = $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => '000000']);

    $response->assertSessionHas('error');
    $this->assertGuest();
    expect($token->fresh()->isUsed())->toBeFalse()
        ->and($token->fresh()->attempts)->toBe(1);
});

test('an expired token is rejected with the same generic message as any other failure', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $token = OwnerRecoveryToken::factory()->expired()->create(['team_id' => $team->id, 'user_id' => $owner->id]);

    $response = $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => '123456']);

    $response->assertSessionHas('error');
    $this->assertGuest();
});

test('a token that has already been used cannot be redeemed a second time', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    ['token' => $token, 'code' => $code] = requestOwnerRecovery($team, $owner->email);

    $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => $code]);
    Auth::logout();

    $response = $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => $code]);

    $response->assertSessionHas('error');
    $this->assertGuest();
});

test('a token is invalidated after too many wrong-code attempts, even with the correct code', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    ['token' => $token, 'code' => $code] = requestOwnerRecovery($team, $owner->email);

    foreach (range(1, OwnerRecoveryToken::MAX_ATTEMPTS) as $attempt) {
        $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => 'wrong-code']);
    }

    expect($token->fresh()->hasExceededAttempts())->toBeTrue();

    $response = $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => $code]);

    $response->assertSessionHas('error');
    $this->assertGuest();
});

test('a token cannot be redeemed against a different organization', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);
    $otherTeam = Team::factory()->create();

    ['token' => $token, 'code' => $code] = requestOwnerRecovery($team, $owner->email);

    $response = $this->post(route('org.recovery.confirm', ['team' => $otherTeam, 'token' => $token]), ['code' => $code]);

    $response->assertNotFound();
    $this->assertGuest();
});

test('recovery is denied if the token holder is no longer the team\'s owner', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    ['token' => $token, 'code' => $code] = requestOwnerRecovery($team, $owner->email);

    $team->memberships()->where('user_id', $owner->id)->update(['role' => TeamRole::Employee->value]);

    $response = $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => $code]);

    $response->assertSessionHas('error');
    $this->assertGuest();

    expect(OwnerRecoveryAudit::where('team_id', $team->id)->where('action', OwnerRecoveryAuditAction::DeniedNotOwner)->exists())->toBeTrue();
});

test('recovery requests are rate limited per email and IP', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Notification::fake();

    foreach (range(1, 3) as $attempt) {
        $this->post(route('org.recovery.store', $team), ['email' => $owner->email])->assertRedirect();
    }

    $this->post(route('org.recovery.store', $team), ['email' => $owner->email])->assertStatus(429);
});

test('recovery code verification attempts are rate limited by IP', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    ['token' => $token] = requestOwnerRecovery($team, $owner->email);

    // The IP-level cap (10) is deliberately above the per-token attempt cap
    // (5, asserted separately) — this loop exhausts the IP budget across
    // requests to the same token, which itself becomes attempts-exceeded
    // partway through but keeps returning normal (non-429) responses until
    // the IP limiter itself kicks in.
    foreach (range(1, 10) as $attempt) {
        $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => 'wrong-code']);
    }

    $this->post(route('org.recovery.confirm', ['team' => $team, 'token' => $token]), ['code' => 'wrong-code'])
        ->assertStatus(429);
});

test('the recovery request form renders for a guest visitor', function () {
    ['team' => $team] = teamWithMember(TeamRole::Owner);

    $this->get(route('org.recovery', $team))->assertOk();
});
