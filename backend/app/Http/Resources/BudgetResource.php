<?php

namespace App\Http\Resources;

use App\Actions\Budgets\CalculateGoalProgressAction;
use App\Enums\BudgetType;
use App\Models\BudgetPeriod;
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
        ];
    }

    /**
     * @return null|array<string, mixed>
     */
    private function goalProgress(Request $request): ?array
    {
        $user = $request->user()?->id === $this->user_id ? $request->user() : $this->resource->user;

        return $user ? app(CalculateGoalProgressAction::class)->execute($this->resource, $user) : null;
    }
}
