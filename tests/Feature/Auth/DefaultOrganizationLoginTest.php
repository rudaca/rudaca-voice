<?php

use App\Models\Team;
use Illuminate\Support\Facades\Log;

test('login redirects to the default organization login page when configured with a valid slug', function () {
    $team = Team::factory()->create();
    config(['organizations.default_slug' => $team->slug]);

    $response = $this->get(route('login'));

    $response->assertRedirect(route('org.login', $team));
});

test('login shows the generic email-entry page when no default organization slug is configured', function () {
    config(['organizations.default_slug' => null]);

    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee(route('login.store'), false);
});

test('login falls back to the generic page and logs a warning when the default organization slug does not exist', function () {
    config(['organizations.default_slug' => 'does-not-exist']);
    Log::spy();

    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee(route('login.store'), false);
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('default_organization_slug_invalid', ['slug' => 'does-not-exist']);
});
