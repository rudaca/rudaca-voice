<?php

namespace App\Enums;

enum ViewAsSessionEndReason: string
{
    case Manual = 'manual';
    case Timeout = 'timeout';
}
