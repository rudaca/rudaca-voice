<?php

return [

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
