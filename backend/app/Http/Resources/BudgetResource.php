<?php

namespace App\Http\Resources;

use App\Actions\Budgets\CalculateGoalProgressAction;
use App\Actions\Budgets\CalculateLimitStatusAction;
use App\Enums\BudgetType;
use App\Models\BudgetPeriod;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isGoal = $this->resource->isGoal();

        if ($this->resource->relationLoaded('periods')) {
            // Lets BudgetPeriodResource hide the placeholder length of goal periods
            // and limit periods find their bucket grid without a query each.
            $this->periods->each(fn (BudgetPeriod $period) => $period->setRelation('budget', $this->resource));
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => ($this->type ?? BudgetType::Limit)->value,
            'start_date' => $isGoal ? $this->resource->goalStartDate() : null,
            'target_date' => $this->target_date?->toDateString(),
            'start_amount' => $this->start_amount === null ? null : (float) $this->start_amount,
            // Limits: day the weekly/monthly/yearly buckets of all periods repeat from;
            // null = calendar weeks/months/years.
            'anchor_date' => $isGoal ? null : $this->anchor_date?->toDateString(),
            'periods' => BudgetPeriodResource::collection($this->whenLoaded('periods')),
            // Server-side goal progress (API / MCP clients); the web app recomputes it live.
            'progress' => $this->when($isGoal, fn () => $this->goalProgress($request)),
            // Limits: the active period (spent counts from its first day, also after a mid-period
            // edit), for API / MCP clients; the web app recomputes it live. Null when paused.
            'current_period' => $this->when(!$isGoal, fn () => $this->limitPeriod($request)),
        ];
    }

    /**
     * @return null|array<string, mixed>
     */
    private function goalProgress(Request $request): ?array
    {
        $user = $this->owner($request);

        return $user ? app(CalculateGoalProgressAction::class)->execute($this->resource, $user) : null;
    }

    /**
     * @return null|array<string, mixed>
     */
    private function limitPeriod(Request $request): ?array
    {
        $user = $this->owner($request);
        $period = $this->resource->periods->first(fn (BudgetPeriod $p) => $p->ends_at === null);

        if (!$user || !$period) {
            return null;
        }

        $period->setRelation('budget', $this->resource);
        $st = app(CalculateLimitStatusAction::class)->execute($period, $user);

        return [
            'start' => $st['start'],
            'end' => $st['end'],
            'amount' => $st['limit'],
            'spent' => $st['spent'],
            'left' => $st['left'],
            'percent' => $st['percent'],
            'days_left' => $st['days_left'],
            'status' => $st['status'],
        ];
    }

    private function owner(Request $request): ?User
    {
        return $request->user()?->id === $this->user_id ? $request->user() : $this->resource->user;
    }
}
