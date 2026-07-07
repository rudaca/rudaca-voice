<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
        Route::livewire('ideas', 'pages::ideas.index')->name('ideas.index');
        Route::livewire('ideas/create', 'pages::ideas.create')->name('ideas.create');
        Route::livewire('ideas/review', 'pages::ideas.review')
            ->middleware(EnsureTeamMembership::class.':manager')
            ->name('ideas.review');
        Route::livewire('idea-settings', 'pages::ideas.settings')
            ->middleware(EnsureTeamMembership::class.':admin')
            ->name('ideas.settings');
        Route::livewire('ideas/{idea}', 'pages::ideas.show')->name('ideas.show');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
