<?php

namespace App\Actions\RecurringTransactions;

use App\Actions\Transactions\CreateTransactionAction;
use App\Enums\RecurringFrequency;
use App\Enums\TransactionSource;
use App\Models\RecurringTransaction;
use Carbon\CarbonInterface;

class GenerateRecurringTransactionsAction
{
    public function __construct(private readonly CreateTransactionAction $createTransaction) {}

    public function execute(): void
    {
        $today = now()->toDateString();

        RecurringTransaction::query()
            ->where('active', true)
            ->where('next_run_at', '<=', $today)
            ->with(['user', 'tags'])
            ->chunkById(50, function ($rules) use ($today) {
                foreach ($rules as $rule) {
                    $this->materialize($rule, $today);
                }
            });
    }

    private function materialize(RecurringTransaction $rule, string $today): void
    {
        // A rule can be many occurrences behind if the scheduler was down —
        // catch up in order rather than only firing the latest one.
        while ($rule->next_run_at->toDateString() <= $today) {
            if ($rule->ends_at && $rule->next_run_at->toDateString() > $rule->ends_at->toDateString()) {
                $rule->update(['active' => false]);

                return;
            }

            $this->createTransaction->execute($rule->user, [
                'at' => $rule->next_run_at->toDateString(),
                'amount' => $rule->amount,
                'note' => $rule->note,
                'tags' => $rule->tags->pluck('id')->all(),
            ], TransactionSource::Recurring);

            $rule->next_run_at = $this->advance($rule->next_run_at, $rule->frequency);
            $rule->save();
        }
    }

    private function advance(CarbonInterface $date, RecurringFrequency $frequency): CarbonInterface
    {
        return match ($frequency) {
            RecurringFrequency::Daily => $date->copy()->addDay(),
            RecurringFrequency::Weekly => $date->copy()->addWeek(),
            RecurringFrequency::Monthly => $date->copy()->addMonthsNoOverflow(1),
            RecurringFrequency::Yearly => $date->copy()->addYearsNoOverflow(1),
        };
    }
}
