<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('tags', 'id')->where('user_id', $this->user()->id),
            ],
            'title' => 'required|string|max:255',
            'emoji' => 'required|string|max:255',
            'calc_balance' => 'required|boolean',
        ];
    }
}
