<?php

use App\Providers\AppServiceProvider;

test('the mail From name is flagged with the current environment outside of production', function () {
    expect(app()->isProduction())->toBeFalse()
        ->and(config('mail.from.name'))->toEndWith(' ('.ucfirst(app()->environment()).')');
});

test('the environment tag is not duplicated when the configured From name already carries it', function () {
    config(['mail.from.name' => 'Voice Dev (Testing)']);

    (new AppServiceProvider($this->app))->boot();

    expect(config('mail.from.name'))->toBe('Voice Dev (Testing)');
});
