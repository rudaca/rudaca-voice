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

    /*
    |--------------------------------------------------------------------------
    | Default Organization Slug
    |--------------------------------------------------------------------------
    |
    | For deployments permanently scoped to a single organization, set this
    | to that organization's slug to send the common `/login` page straight
    | to its organization-specific login page instead of asking for a work
    | email first. Leave unset for multi-tenant deployments.
    |
    */

    'default_slug' => env('DEFAULT_ORGANIZATION_SLUG'),

];
