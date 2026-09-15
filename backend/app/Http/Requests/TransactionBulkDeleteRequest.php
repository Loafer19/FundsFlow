<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionBulkDeleteRequest extends FormRequest
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
            'ids' => 'required|array|min:1|max:' . self::MAX_ITEMS,
            'ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('transactions', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
