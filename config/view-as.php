<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable View As
    |--------------------------------------------------------------------------
    |
    | When disabled, Super Admins cannot start a "View As" session and the
    | trigger button is hidden entirely, regardless of the is_super_admin flag.
    |
    */

    'enabled' => (bool) env('ENABLE_VIEW_AS_SUPER_ADMIN', false),

    /*
    |--------------------------------------------------------------------------
    | View As Inactivity Timeout
    |--------------------------------------------------------------------------
    |
    | The number of minutes of inactivity after which an active "View As"
    | session is automatically ended and reverted back to the Super Admin.
    |
    */

    'timeout_minutes' => (int) env('VIEW_AS_TIMEOUT_MINUTES', 30),

];
