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
        return [
            'at' => 'required|string|date',
            'amount' => 'required|numeric|not_in:0',
            'note' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
