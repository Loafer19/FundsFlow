<?php

namespace App\Http\Controllers;

use App\Actions\Bootstrap\LoadBootstrapDataAction;
use App\Actions\Report\RenderReportPdfAction;
use App\Actions\Telegram\SendFileToUserTelegramAction;
use App\Support\PeriodRange;
use App\Support\ReportPrintToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReportController extends Controller
{
    /**
     * POST /api/report (web Settings → Report) and POST /api/agent/report (assistant report card):
     * render the report PDF, then download it or send it to the user's Telegram.
     */
    public function store(
        Request $request,
        RenderReportPdfAction $render,
        SendFileToUserTelegramAction $sendToTelegram,
    ): Response|JsonResponse {
        $data = $request->validate([
            'period' => ['nullable', 'string', 'in:' . implode(',', PeriodRange::PRESETS)],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'period_type' => ['nullable', 'string', 'in:' . implode(',', RenderReportPdfAction::PERIOD_TYPES)],
            'tag_ids' => ['nullable', 'array', 'max:50'],
            'tag_ids.*' => ['integer', 'min:1'],
            'untagged' => ['nullable', 'boolean'],
            'sections' => ['nullable', 'array'],
            'sections.*' => ['boolean'],
            'theme' => ['nullable', 'string', 'regex:' . RenderReportPdfAction::THEME_PATTERN],
            'delivery' => ['nullable', 'string', 'in:download,telegram'],
        ]);
        $user = $request->user();
        $toTelegram = ($data['delivery'] ?? 'download') === 'telegram';

        if ($toTelegram && ! $user->identities()->where('provider', 'telegram')->exists()) {
            return response()->json(['message' => 'Link Telegram in Settings first.', 'needs_link' => true], 422);
        }

        $args = ['from' => $data['from'], 'to' => $data['to'], 'tag_ids' => $data['tag_ids'] ?? [], 'untagged' => (bool) ($data['untagged'] ?? false)];
        // Keep the preset label ("This month (…)") only while it still means the same dates.
        if (isset($data['period'])) {
            $preset = PeriodRange::resolve($user, ['period' => $data['period']]);
            if ($preset->from->toDateString() === $data['from'] && $preset->fullTo->toDateString() === $data['to']) {
                $args = ['period' => $data['period']] + array_diff_key($args, ['from' => 1, 'to' => 1]);
            }
        }

        try {
            $report = $render->execute($user, $args, [
                'sections' => $data['sections'] ?? null,
                'theme' => $data['theme'] ?? null,
                'period_type' => $data['period_type'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::warning('ReportController: report PDF failed', ['message' => ReportPrintToken::redact($e->getMessage())]);

            return response()->json(['message' => "Couldn't build the PDF right now. Try again in a moment."], 502);
        }

        if ($toTelegram) {
            $sendToTelegram->execute($user, $report['pdf'], $report['filename'], 'application/pdf', $report['caption']);

            return response()->json(['ok' => true, 'message' => 'Report sent to Telegram.']);
        }

        return response($report['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $report['filename'] . '"',
            'Cache-Control' => 'no-store',
            'X-Report-Renderer' => $report['renderer'],
        ]);
    }

    /**
     * GET /api/report/print-data — the only endpoint a `report:print` token can call.
     * Returns the report parameters plus the user's data for the SPA print page, then
     * revokes the token (one-time use).
     */
    public function printData(Request $request, LoadBootstrapDataAction $bootstrap): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if (! ReportPrintToken::isReportToken($token)) {
            abort(403, 'Report print token required.');
        }

        $payload = ReportPrintToken::payload($token);
        ReportPrintToken::revoke((int) $token->getKey());

        if ($payload === null) {
            abort(410, 'Report print session expired.');
        }

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'preferences' => $user->preferences ?? (object) [],
            ],
            'report' => $payload,
            'data' => $bootstrap->execute($user),
        ], 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
