<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class TelegramSendFileRequest extends FormRequest
{
    public const MAX_BYTES = 20 * 1024 * 1024;

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
                File::types(['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx'])
                    ->max(self::MAX_BYTES / 1024),
            ],
            'caption' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
