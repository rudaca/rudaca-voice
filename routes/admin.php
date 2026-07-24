<?php

use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth', 'verified', EnsureSuperAdmin::class])
    ->name('admin.')
    ->group(function () {
        Route::livewire('users', 'pages::admin.users')->name('users');
    });
