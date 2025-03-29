<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
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
            'amount' => 'required|numeric',
            'note' => 'nullable|string|max:255',
        ];
    }
}
