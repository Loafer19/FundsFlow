<?php

namespace App\Http\Requests;

use App\Enums\BudgetLength;
use App\Enums\BudgetType;
use App\Models\Budget;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BudgetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Type of the budget being validated: fixed by the existing row on update
     * (a limit cannot turn into a goal or back), from the payload on create.
     */
    public function budgetType(): BudgetType
    {
        $budget = $this->route('budget');

        if ($budget instanceof Budget) {
            return $budget->type ?? BudgetType::Limit;
        }

        return BudgetType::tryFrom((string) $this->input('type', BudgetType::Limit->value)) ?? BudgetType::Limit;
    }

    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        $rules = [
            'type' => ['sometimes', Rule::enum(BudgetType::class)],
            'title' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'tag_ids' => 'required|array|min:1',
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('user_id', $this->user()->id),
            ],
        ];

        if ($this->budgetType() === BudgetType::Goal) {
            return $rules + [
                'start_date' => 'nullable|date_format:Y-m-d|after:2000-01-01|before_or_equal:' . $this->user()->todayDateString(),
                'target_date' => 'nullable|date_format:Y-m-d|after:2000-01-01|before:2100-01-01',
                'start_amount' => 'nullable|numeric|min:0|max:99999999.99',
            ];
        }

        return $rules + [
            'length' => ['required', Rule::in(array_column(BudgetLength::cases(), 'value'))],
            // First day of the limit; buckets repeat from it. Default today.
            'start_date' => 'nullable|date_format:Y-m-d|after:2000-01-01|before_or_equal:' . $this->user()->todayDateString(),
            // Legacy (kept for old clients): start at the beginning of the current week/month/year.
            'align_to_calendar' => 'sometimes|boolean',
        ];
    }

    /**
     * A goal cannot start after its deadline. Either side may come from the
     * stored goal on update, so this runs after the field rules.
     *
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->budgetType() !== BudgetType::Goal || $validator->errors()->hasAny(['start_date', 'target_date'])) {
                    return;
                }

                $budget = $this->route('budget');
                $stored = $budget instanceof Budget ? $budget : null;

                $start = $this->filled('start_date')
                    ? (string) $this->input('start_date')
                    : ($stored?->goalStartDate() ?? $this->user()->todayDateString());
                $target = $this->exists('target_date')
                    ? ($this->filled('target_date') ? (string) $this->input('target_date') : null)
                    : $stored?->target_date?->toDateString();

                if ($target !== null && $start > $target) {
                    $validator->errors()->add('start_date', 'The start date must be on or before the deadline.');
                }
            },
        ];
    }
}
