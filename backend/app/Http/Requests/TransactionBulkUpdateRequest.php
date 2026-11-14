<?php

namespace App\Http\Requests;

use App\Support\TransactionBulkRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionBulkUpdateRequest extends FormRequest
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
            'transactions' => 'required|array|min:1|max:' . TransactionBulkRules::MAX_ITEMS,

            'transactions.*.id' => [
                'required',
                'integer',
                Rule::exists('transactions', 'id')->where('user_id', $this->user()->id),
            ],
            'transactions.*.at' => 'required|string|date',
            'transactions.*.amount' => 'sometimes|numeric|not_in:0',
            'transactions.*.transaction_type_id' => 'nullable|integer',
            'transactions.*.data' => 'nullable|array',
            'transactions.*.add_options' => 'sometimes|boolean',
            'transactions.*.note' => 'nullable|string|max:255',
            'transactions.*.tags' => 'nullable|array',
            'transactions.*.tags.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
