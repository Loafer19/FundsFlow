<?php

namespace App\Http\Controllers;

use App\Actions\Telegram\SendFileToUserTelegramAction;
use App\Http\Requests\TelegramSendFileRequest;
use Illuminate\Http\JsonResponse;

class TelegramSendController extends Controller
{
    public function __construct(
        private readonly SendFileToUserTelegramAction $sendFile,
    ) {}

    public function sendFile(TelegramSendFileRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $caption = $request->validated('caption');

        $this->sendFile->execute(
            $request->user(),
            $file->getContent(),
            $file->getClientOriginalName() ?: 'file',
            (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'),
            is_string($caption) ? $caption : null,
        );

        return response()->json([
            'message' => 'Sent to Telegram',
        ]);
    }
}
