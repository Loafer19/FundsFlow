<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TelegramSendReportRequest extends FormRequest
{
    public const MAX_HTML_CHARS = 12_000_000;

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
            'html' => ['required', 'string', 'max:'.self::MAX_HTML_CHARS],
            'caption' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
