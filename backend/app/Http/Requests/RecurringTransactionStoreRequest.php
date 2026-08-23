<?php

namespace App\Http\Requests;

use App\Enums\RecurringFrequency;
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
        return [
            'amount' => 'required|numeric|not_in:0',
            'note' => 'nullable|string|max:255',
            'frequency' => ['required', Rule::in(array_column(RecurringFrequency::cases(), 'value'))],
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'active' => 'sometimes|boolean',
            'tags' => 'nullable|array',
            'tags.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at'),
            ],
        ];
    }
}
