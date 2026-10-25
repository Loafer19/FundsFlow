<?php

namespace App\Actions\Report;

use App\Models\User;
use App\Support\PeriodRange;
use App\Support\ReportPrintToken;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The one report PDF renderer (web Settings → Report, Telegram send_report, assistant report card).
 *
 * Primary: Gotenberg Chromium opens the real SPA in print mode (REPORT_RENDER_URL/?print=report#t=…)
 * under a one-time `report:print` token, so the PDF is ReportDocument.vue with the user's theme,
 * real Chart.js charts, the chosen sections, period and tag filter.
 * Fallback (REPORT_RENDER_URL unset, or the URL render failed): the server HTML of BuildUserReportAction.
 */
class RenderReportPdfAction
{
    public const SECTIONS = ['analytics', 'calendar', 'budgets', 'tags', 'flow', 'trend', 'list'];

    public const PERIOD_TYPES = ['week', 'month', 'year', 'custom'];

    public const THEME_PATTERN = '/^[a-z0-9-]{1,32}$/';

    public function __construct(
        private readonly BuildUserReportAction $buildReport,
        private readonly ConvertHtmlToPdfViaGotenbergAction $htmlToPdf,
        private readonly ConvertUrlToPdfViaGotenbergAction $urlToPdf,
    ) {}

    /**
     * @param array<string, mixed> $args period | from/to, tag_ids, untagged (BuildUserReportAction args)
     * @param array{sections?: array<string, mixed>|null, theme?: string|null, period_type?: string|null} $options
     * @return array{pdf: string, filename: string, caption: string, summary: array<string, mixed>, renderer: string}
     *
     * @throws RuntimeException when both renderers fail
     */
    public function execute(User $user, array $args, array $options = []): array
    {
        $report = $this->buildReport->execute($user, $args);
        $meta = ['filename' => $report['filename'], 'caption' => $report['caption'], 'summary' => $report['summary']];

        $base = rtrim((string) config('services.report.render_url'), '/');
        if ($base !== '') {
            try {
                return ['pdf' => $this->renderSpa($user, $base, $this->printPayload($user, $args, $options)), 'renderer' => 'spa'] + $meta;
            } catch (Throwable $e) {
                Log::warning('RenderReportPdfAction: SPA render failed, using server HTML', [
                    'message' => ReportPrintToken::redact($e->getMessage()),
                ]);
            }
        }

        return ['pdf' => $this->htmlToPdf->execute($report['html']), 'renderer' => 'html'] + $meta;
    }

    /**
     * What the print page needs besides the user's data: dates (full periods), the header
     * period type, sections, tag filter and theme.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function printPayload(User $user, array $args, array $options = []): array
    {
        ['range' => $range, 'tag_ids' => $tagIds, 'untagged' => $untagged] = $this->buildReport->resolve($user, $args);

        $type = $options['period_type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::PERIOD_TYPES, true)) {
            $type = $this->detectPeriodType($range);
        }

        return [
            'from' => $range->from->toDateString(),
            'to' => $range->fullTo->toDateString(),
            'previous_from' => $range->previousFrom->toDateString(),
            'previous_to' => $range->previousTo->toDateString(),
            'period_type' => $type,
            'label' => $range->label,
            'sections' => self::normalizeSections($options['sections'] ?? null),
            'tag_ids' => $tagIds,
            'untagged' => $untagged,
            'theme' => self::theme($user, $options['theme'] ?? null),
        ];
    }

    /**
     * @return array<string, bool> every section key; all on when nothing (or nothing valid) is selected
     */
    public static function normalizeSections(mixed $sections): array
    {
        $out = array_fill_keys(self::SECTIONS, true);
        if (! is_array($sections)) {
            return $out;
        }

        $picked = [];
        foreach (self::SECTIONS as $key) {
            $picked[$key] = filter_var($sections[$key] ?? false, FILTER_VALIDATE_BOOL);
        }

        return in_array(true, $picked, true) ? $picked : $out;
    }

    /** Requested theme → saved preference → app default. */
    public static function theme(User $user, mixed $requested): string
    {
        foreach ([$requested, $user->preferences['theme'] ?? null] as $theme) {
            if (is_string($theme) && preg_match(self::THEME_PATTERN, $theme)) {
                return $theme;
            }
        }

        return 'bumblebee';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderSpa(User $user, string $base, array $payload): string
    {
        ['token' => $token, 'id' => $id] = ReportPrintToken::mint($user, $payload);

        try {
            // Fragment, not query: the token never appears in an HTTP request line or access log.
            return $this->urlToPdf->execute($base . '/?print=report#t=' . rawurlencode($token));
        } finally {
            ReportPrintToken::revoke($id);
        }
    }

    private function detectPeriodType(PeriodRange $range): string
    {
        $from = $range->from;
        $to = $range->fullTo;

        if ($from->isSameDay($from->startOfYear()) && $to->isSameDay($from->endOfYear())) {
            return 'year';
        }
        if ($from->isSameDay($from->startOfMonth()) && $to->isSameDay($from->endOfMonth())) {
            return 'month';
        }
        if ((int) $from->diffInDays($to) === 6) {
            return 'week';
        }

        return 'custom';
    }
}
