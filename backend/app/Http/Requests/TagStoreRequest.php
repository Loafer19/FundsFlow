<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TagStoreRequest extends FormRequest
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
            'parent_id' => 'nullable|integer|exists:tags,id',
            'title' => 'required|string|max:255',
            'emoji' => 'required|string|max:255',
            'calc_balance' => 'required|boolean',
        ];
    }
}
