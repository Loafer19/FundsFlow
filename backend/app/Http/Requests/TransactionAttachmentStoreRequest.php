<?php

namespace App\Http\Requests;

use App\Support\TransactionAttachmentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class TransactionAttachmentStoreRequest extends FormRequest
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
            'file' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf'])
                    ->max(TransactionAttachmentRules::MAX_BYTES / 1024),
            ],
        ];
    }
}
