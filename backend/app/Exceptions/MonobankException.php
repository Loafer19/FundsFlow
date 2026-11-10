<?php

namespace App\Exceptions;

use RuntimeException;

class MonobankException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 502)
    {
        parent::__construct($message, $status);
    }
}
