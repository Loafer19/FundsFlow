<?php

namespace App\Http\Requests;

use App\Support\TransactionAttachmentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionAttachmentBase64Request extends FormRequest
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
            'name' => 'required|string|max:255',
            'mime' => ['required', 'string', Rule::in(TransactionAttachmentRules::allowedMimes())],
            'content' => 'required|string',
        ];
    }
}
