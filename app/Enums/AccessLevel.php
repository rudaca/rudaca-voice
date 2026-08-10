<?php

namespace App\Enums;

enum AccessLevel: string
{
    case View = 'view';
    case Contribute = 'contribute';
    case Manage = 'manage';
}
