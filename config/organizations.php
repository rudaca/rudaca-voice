<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hosting Mode
    |--------------------------------------------------------------------------
    |
    | Controls how the "system owner" permission (the ability to create new
    | organizations) is administered. In "hosted" mode this is Rudaca's own
    | multi-tenant deployment, so only a single designated Rudaca account
    | may hold the flag. In "self-hosted" mode a customer runs their own
    | installation and may designate one or more system owners themselves.
    |
    | Supported: "hosted", "self-hosted"
    |
    */

    'hosting_mode' => env('ORGANIZATIONS_HOSTING_MODE', 'self-hosted'),

];
