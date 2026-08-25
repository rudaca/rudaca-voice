<?php

use App\Enums\IdentityProvider;
use App\Enums\TeamRole;
use Livewire\Livewire;

test('an owner can view the Microsoft SSO setup guide, including the redirect URI', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    $response = $this->actingAs($owner)->get(route('ideas.settings.microsoft-sso-guide', ['current_team' => $team->slug]));

    $response->assertOk();
    $response->assertSee(IdentityProvider::Microsoft->redirectUrl());
    $response->assertSee(__('Organization'));
    $response->assertSee(__('Authentication'));
    $response->assertSee(__('Setup Guide'));
});

test('a manager without manage-authentication permission cannot view the setup guide', function () {
    ['team' => $team, 'user' => $manager] = teamWithMember(TeamRole::Manager);

    $response = $this->actingAs($manager)->get(route('ideas.settings.microsoft-sso-guide', ['current_team' => $team->slug]));

    $response->assertForbidden();
});

test('the authentication settings panel links to the in-app setup guide', function () {
    ['team' => $team, 'user' => $owner] = teamWithMember(TeamRole::Owner);

    Livewire::actingAs($owner)
        ->test('pages::ideas.authentication-settings', ['team' => $team])
        ->assertSeeHtml(route('ideas.settings.microsoft-sso-guide', ['current_team' => $team->slug]));
});
