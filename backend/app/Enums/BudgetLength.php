<?php

namespace App\Enums;

use Carbon\Carbon;

enum BudgetLength: string
{
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    public function calendarStart(?Carbon $now = null): Carbon
    {
        $now = ($now ?? now())->copy();

        return match ($this) {
            self::Week => $now->startOfWeek(),
            self::Month => $now->startOfMonth(),
            self::Year => $now->startOfYear(),
        };
    }
}

