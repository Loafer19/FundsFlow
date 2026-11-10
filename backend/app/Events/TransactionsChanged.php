<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TransactionsChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param list<int> $ids
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?string $kind = null,
        public readonly array $ids = [],
    ) {}
}
