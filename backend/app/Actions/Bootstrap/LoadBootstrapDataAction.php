<?php

namespace App\Actions\Bootstrap;

use App\Actions\Budgets\ListBudgetsAction;
use App\Actions\RecurringTransactions\ListRecurringTransactionsAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Http\Resources\BudgetResource;
use App\Http\Resources\RecurringTransactionResource;
use App\Http\Resources\TagResource;
use App\Http\Resources\TransactionResource;
use App\Models\User;

class LoadBootstrapDataAction
{
    public function __construct(
        private readonly ListTagsAction $listTags,
        private readonly ListTransactionsAction $listTransactions,
        private readonly ListBudgetsAction $listBudgets,
        private readonly ListRecurringTransactionsAction $listRecurring,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        return [
            'tags' => TagResource::collection($this->listTags->execute($user))->resolve(),
            'transactions' => TransactionResource::collection($this->listTransactions->execute($user))->resolve(),
            'budgets' => BudgetResource::collection($this->listBudgets->execute($user))->resolve(),
            'recurring_transactions' => RecurringTransactionResource::collection(
                $this->listRecurring->execute($user),
            )->resolve(),
        ];
    }
}
