<?php

namespace App\Http\Controllers;

use App\Actions\Report\ConvertHtmlToPdfViaGotenbergAction;
use App\Actions\Telegram\SendFileToUserTelegramAction;
use App\Http\Requests\TelegramSendFileRequest;
use App\Http\Requests\TelegramSendReportRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class TelegramSendController extends Controller
{
    public function __construct(
        private readonly SendFileToUserTelegramAction $sendFile,
        private readonly ConvertHtmlToPdfViaGotenbergAction $htmlToPdf,
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

    public function sendReport(TelegramSendReportRequest $request): JsonResponse
    {
        $html = $request->validated('html');
        $caption = $request->validated('caption');

        try {
            $pdf = $this->htmlToPdf->execute($html);
        } catch (RuntimeException $e) {
            abort(502, $e->getMessage());
        }

        $this->sendFile->execute(
            $request->user(),
            $pdf,
            'fundsflow-report.pdf',
            'application/pdf',
            is_string($caption) ? $caption : null,
        );

        return response()->json([
            'message' => 'Sent to Telegram',
        ]);
    }
}
