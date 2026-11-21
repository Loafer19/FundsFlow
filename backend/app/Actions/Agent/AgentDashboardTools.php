<?php

namespace App\Actions\Agent;

use App\Actions\Dashboard\DashboardCards;
use App\Models\DashboardCard;
use App\Models\User;
use App\Support\DashboardCardConfig;
use App\Support\PeriodRange;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Analytics "My cards" for every agent transport (wired in through AgentPlanningTools):
 * list them with their numbers, and Confirm/Cancel proposals to add or remove one.
 * Configs go through DashboardCardConfig, the same validator as the web API.
 */
class AgentDashboardTools
{
    public const READ_TOOLS = ['list_dashboard_cards'];

    public const STAGING_TOOLS = ['add_dashboard_card', 'remove_dashboard_card'];

    public const PROMPT_RULE = '- Analytics "My cards" (the user\'s own cards on the Analytics tab): list_dashboard_cards shows them with ids and current numbers. add_dashboard_card proposes one (stages Confirm): kind stat (one number: agg of amount or a number field, or metric=fields for every number field of the type, compared with the previous period), chart (metric over time, line or bar, optional split by tag or a field → up to 6 series, e.g. average price_per_liter split by station) or pie (share by tag or a field value). Filters: type (name), tags (titles), filters {field: value} like {station: "WOG"} (needs type). agg sum|avg|count|min|max; flow expense (default for amount)|income|all; size S|M|L. The period always follows the Analytics date picker. remove_dashboard_card removes one by id (stages Confirm).';

    public function __construct(private readonly DashboardCards $cards) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $fn = AgentToolExecutor::fn(...);

        return [
            $fn('list_dashboard_cards', 'List the user\'s Analytics "My cards" in order with id, kind, size, config and current numbers for a period (default this_month; or from/to YYYY-MM-DD).', [
                'type' => 'object',
                'properties' => [
                    'period' => ['type' => 'string', 'enum' => PeriodRange::PRESETS],
                    'from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                ],
            ]),
            $fn('add_dashboard_card', 'Propose adding a card to Analytics → My cards. Stages Confirm/Cancel; nothing is saved until the user confirms. Examples: fuel spend at WOG = {kind: stat, type: "Fuel", filters: {station: "WOG"}}; price per liter by station = {kind: chart, type: "Fuel", metric: "price_per_liter", agg: "avg", split: "station", chart: "line"}; spending by tag = {kind: pie, split: "tag"}.', [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => DashboardCardConfig::KINDS],
                    'title' => ['type' => 'string', 'description' => 'Optional own title (default: automatic)'],
                    'type' => ['type' => 'string', 'description' => 'Transaction type name (needed for field metrics, field filters and field split)'],
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag titles to filter by (child tags included)'],
                    'filters' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'boolean']], 'description' => 'Field key = value, e.g. {"station": "WOG"}'],
                    'metric' => ['type' => 'string', 'description' => 'amount (default), a number field key of the type (e.g. price_per_liter, liters), or fields (stat only: all number fields)'],
                    'agg' => ['type' => 'string', 'enum' => DashboardCardConfig::AGGS],
                    'flow' => ['type' => 'string', 'enum' => DashboardCardConfig::FLOWS, 'description' => 'Which transactions: expense (default for amount), income, all (default for fields)'],
                    'split' => ['type' => 'string', 'description' => 'chart/pie: tag, or a field key of the type (e.g. station). Required for pie.'],
                    'chart' => ['type' => 'string', 'enum' => DashboardCardConfig::CHARTS],
                    'size' => ['type' => 'string', 'enum' => DashboardCardConfig::SIZES, 'description' => 'S = quarter, M = half, L = full width. Default: stat S, chart/pie M.'],
                ],
                'required' => ['kind'],
            ]),
            $fn('remove_dashboard_card', 'Propose removing one of My cards by id from list_dashboard_cards. Stages Confirm/Cancel.', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => ['id'],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function read(User $user, array $args): array
    {
        $cards = array_map(fn (array $card): array => $this->compact($card), $this->cards->list($user, $args));

        return ['count' => count($cards), 'period' => PeriodRange::resolve($user, $args)->toArray(), 'cards' => $cards];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    public function prepare(User $user, string $name, array $args): array
    {
        return match ($name) {
            'add_dashboard_card' => $this->prepareAdd($user, $args),
            'remove_dashboard_card' => $this->prepareRemove($user, $args),
            default => throw new InvalidArgumentException("Unknown staging tool: {$name}"),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message: string, summary: string}
     */
    public function apply(User $user, string $action, array $payload): array
    {
        try {
            if ($action === 'add_dashboard_card') {
                $card = $this->cards->create($user, $payload);
                $label = DashboardCardConfig::label($user, $card->kind, $card->config);

                return ['ok' => true, 'message' => '✅ Card added to Analytics → My cards: ' . $label, 'summary' => 'Dashboard card added via agent confirm: ' . $label];
            }

            if ($action === 'remove_dashboard_card') {
                $card = $user->dashboardCards()->find((int) ($payload['id'] ?? 0));

                if (!$card) {
                    return ['ok' => false, 'message' => 'That card no longer exists.', 'summary' => 'Dashboard card to remove no longer exists.'];
                }

                $label = DashboardCardConfig::label($user, $card->kind, $card->config);
                $this->cards->delete($card);

                return ['ok' => true, 'message' => '✅ Card removed: ' . $label, 'summary' => 'Dashboard card removed via agent confirm: ' . $label];
            }
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => 'Not saved: ' . $e->getMessage(), 'summary' => 'Dashboard card change failed: ' . $e->getMessage()];
        }

        return ['ok' => false, 'message' => 'Nothing to confirm.', 'summary' => 'Nothing to confirm.'];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    private function prepareAdd(User $user, array $args): array
    {
        $kind = DashboardCardConfig::kind($args['kind'] ?? null);
        $config = DashboardCardConfig::normalize($user, $kind, $args);
        $size = DashboardCardConfig::size($args['size'] ?? null, $kind);

        if ($user->dashboardCards()->count() >= DashboardCardConfig::MAX_CARDS) {
            throw new InvalidArgumentException('At most ' . DashboardCardConfig::MAX_CARDS . ' cards; remove one first.');
        }

        $label = DashboardCardConfig::label($user, $kind, $config);
        $preview = 'Add to Analytics → My cards: ' . ucfirst($kind) . ' “' . $label . '” (size ' . $size . ').'
            . "\nIt follows the Analytics date range.";

        return ['payload' => ['kind' => $kind, 'size' => $size, 'config' => $config], 'preview' => $preview];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string}
     */
    private function prepareRemove(User $user, array $args): array
    {
        $card = $user->dashboardCards()->find((int) ($args['id'] ?? 0));

        if (!$card instanceof DashboardCard) {
            throw new InvalidArgumentException('Unknown card id; call list_dashboard_cards first.');
        }

        $label = DashboardCardConfig::label($user, $card->kind, $card->config);

        return ['payload' => ['id' => (int) $card->id], 'preview' => 'Remove from Analytics → My cards: ' . ucfirst($card->kind) . ' “' . $label . '”.'];
    }

    /**
     * Numbers without chart points (keeps the tool result small).
     *
     * @param array<string, mixed> $card
     * @return array<string, mixed>
     */
    private function compact(array $card): array
    {
        $data = $card['data'] ?? [];
        $numbers = match ($card['kind']) {
            'stat' => isset($data['fields'])
                ? ['fields' => array_map(static fn (array $f): array => array_intersect_key($f, array_flip(['key', 'label', 'unit', 'value', 'previous', 'change_pct'])), $data['fields'])]
                : array_intersect_key($data, array_flip(['value', 'previous', 'change_pct'])),
            'chart' => ['bucket' => $data['bucket'] ?? null, 'series' => array_map(static fn (array $s): array => ['label' => $s['label'], 'total' => $s['total']], $data['series'] ?? [])],
            default => ['slices' => array_map(static fn (array $s): array => ['label' => $s['label'], 'total' => $s['total'], 'share' => $s['share']], $data['slices'] ?? [])],
        };

        return [
            'id' => $card['id'],
            'kind' => $card['kind'],
            'size' => $card['size'],
            'label' => $card['label'],
            'config' => $card['config'],
            'count' => $data['count'] ?? 0,
            'unit' => $data['unit'] ?? null,
            ...$numbers,
        ];
    }
}
