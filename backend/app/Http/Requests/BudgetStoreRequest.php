<?php

namespace App\Http\Requests;

use App\Enums\BudgetLength;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BudgetStoreRequest extends FormRequest
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
            'title' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'length' => ['required', Rule::in(array_column(BudgetLength::cases(), 'value'))],
            'tag_ids' => 'required|array|min:1',
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('user_id', $this->user()->id)->whereNull('deleted_at'),
            ],
        ];
    }
}
