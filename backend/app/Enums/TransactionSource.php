<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Web = 'web';
    case Telegram = 'telegram';
    case Recurring = 'recurring';
    case Mcp = 'mcp';
}
