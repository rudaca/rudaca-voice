<?php

test('the mail From name is flagged with the current environment outside of production', function () {
    expect(app()->isProduction())->toBeFalse()
        ->and(config('mail.from.name'))->toEndWith(' ('.ucfirst(app()->environment()).')');
});
