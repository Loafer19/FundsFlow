<?php

namespace App\Enums;

enum BudgetType: string
{
    /** Spending cap per recurring week/month/year bucket (the original budget). */
    case Limit = 'limit';

    /** Savings goal: a target amount reached through tagged contributions. */
    case Goal = 'goal';
}
