<?php

namespace App\Http\Requests;

use App\Support\TransactionAttachmentRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class TransactionAttachmentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(TransactionAttachmentRules::EXTENSIONS)
                    ->max(TransactionAttachmentRules::MAX_BYTES / 1024),
            ],
        ];
    }
}
