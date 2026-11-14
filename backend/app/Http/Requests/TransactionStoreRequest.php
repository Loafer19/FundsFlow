<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionStoreRequest extends FormRequest
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
        $presence = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'at' => $presence . '|string|date',
            // A type whose computed field fills the amount may leave it out (TransactionTypeData).
            'amount' => ($this->isMethod('PATCH') ? 'sometimes' : 'required_without:transaction_type_id') . '|nullable|numeric|not_in:0',
            'note' => 'nullable|string|max:255',
            'transaction_type_id' => 'nullable|integer',
            'data' => 'nullable|array',
            'add_options' => 'sometimes|boolean',
            'default_tags' => 'sometimes|boolean',
            'tags' => 'nullable|array',
            'tags.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
