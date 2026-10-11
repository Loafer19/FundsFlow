<?php

namespace App\Http\Requests;

use App\Enums\RecurringFrequency;
use App\Models\RecurringTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringTransactionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>|\Illuminate\Contracts\Validation\ValidationRule|string>
     */
    public function rules(): array
    {
        // PATCH is a partial update; POST/PUT need the full record.
        $patch = $this->isMethod('PATCH');
        $presence = $patch ? 'sometimes' : 'required';

        // A PATCH may change ends_at alone; compare it with the stored start then.
        $startsAt = 'starts_at';
        $rule = $this->route('recurringTransaction');
        if ($patch && !$this->has('starts_at') && $rule instanceof RecurringTransaction) {
            $startsAt = $rule->starts_at->toDateString();
        }

        return [
            'amount' => $presence . '|numeric|not_in:0',
            'note' => 'nullable|string|max:255',
            'frequency' => [$presence, Rule::in(array_column(RecurringFrequency::cases(), 'value'))],
            'starts_at' => $presence . '|date',
            'ends_at' => 'nullable|date|after_or_equal:' . $startsAt,
            'active' => 'sometimes|boolean',
            'tags' => 'nullable|array',
            'tags.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
