<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionBulkStoreRequest extends FormRequest
{
    public const MAX_ITEMS = 50;

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
            'transactions' => 'required|array|min:1|max:' . self::MAX_ITEMS,
            'transactions.*.at' => 'required|string|date',
            'transactions.*.amount' => 'required|numeric|not_in:0',
            'transactions.*.note' => 'nullable|string|max:255',
            'transactions.*.tags' => 'nullable|array',
            'transactions.*.tags.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at'),
            ],
        ];
    }
}
