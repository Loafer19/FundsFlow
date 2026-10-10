<?php

use App\Enums\RecurringFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Month-end rules drifted (Jan 31 → Feb 28 → Mar 28 …) and resumed rules
     * jumped to the resume day. Snap each next_run_at forward onto its
     * starts_at cadence. Only ever moves forward, so nothing fires twice.
     */
    public function up(): void
    {
        DB::table('recurring_transactions')
            ->select('id', 'frequency', 'starts_at', 'next_run_at')
            ->orderBy('id')
            ->each(function (object $rule) {
                $frequency = RecurringFrequency::tryFrom($rule->frequency);

                if ($frequency === null) {
                    return;
                }

                $current = CarbonImmutable::parse($rule->next_run_at);
                $anchored = $frequency->firstOnOrAfter(CarbonImmutable::parse($rule->starts_at), $current);

                if ($anchored->toDateString() !== $current->toDateString()) {
                    DB::table('recurring_transactions')
                        ->where('id', $rule->id)
                        ->update(['next_run_at' => $anchored->toDateString()]);
                }
            });
    }

    public function down(): void
    {
        // Data fix only; the previous drifted dates are not worth restoring.
    }
};
