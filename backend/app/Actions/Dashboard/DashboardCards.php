<?php

namespace App\Actions\Dashboard;

use App\Models\DashboardCard;
use App\Models\User;
use App\Support\DashboardCardConfig;
use App\Support\PeriodRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create / change / delete / reorder "My cards" and present them with their numbers.
 * The single entry point for the web API, the assistant tools and MCP (through the API).
 */
class DashboardCards
{
    public function __construct(private readonly DashboardCardData $data) {}

    /**
     * @param array<string, mixed> $args from/to (YYYY-MM-DD) or period preset; default this month
     * @return list<array<string, mixed>>
     */
    public function list(User $user, array $args = [], bool $withData = true): array
    {
        $range = PeriodRange::resolve($user, $args);

        return $user->dashboardCards()->get()
            ->map(fn (DashboardCard $card): array => $this->present($user, $card, $withData ? $range : null))
            ->all();
    }

    /**
     * @param array<string, mixed> $input kind, size, config (or the config keys at the top level)
     */
    public function create(User $user, array $input): DashboardCard
    {
        $kind = DashboardCardConfig::kind($input['kind'] ?? null);
        $config = DashboardCardConfig::normalize($user, $kind, $this->configInput($input));
        $size = DashboardCardConfig::size($input['size'] ?? null, $kind);

        return DB::transaction(function () use ($user, $kind, $config, $size): DashboardCard {
            // Lock the user's cards so two quick adds don't share a position or pass the cap together.
            $count = $user->dashboardCards()->lockForUpdate()->count();

            if ($count >= DashboardCardConfig::MAX_CARDS) {
                throw ValidationException::withMessages(['kind' => 'At most ' . DashboardCardConfig::MAX_CARDS . ' cards!']);
            }

            $position = (int) $user->dashboardCards()->max('position') + 1;

            return $user->dashboardCards()->create([
                'kind' => $kind,
                'config' => $config,
                'size' => $size,
                'position' => $count === 0 ? 0 : $position,
            ]);
        });
    }

    /**
     * Kind and config are replaced together; size alone can change too (the ⋮ menu).
     *
     * @param array<string, mixed> $input
     */
    public function update(User $user, DashboardCard $card, array $input): DashboardCard
    {
        $kind = array_key_exists('kind', $input) ? DashboardCardConfig::kind($input['kind']) : $card->kind;
        $configInput = $this->configInput($input);

        if ($configInput !== [] || $kind !== $card->kind) {
            $card->kind = $kind;
            $card->config = DashboardCardConfig::normalize($user, $kind, $configInput !== [] ? $configInput : $card->config);
        }

        if (array_key_exists('size', $input)) {
            $card->size = DashboardCardConfig::size($input['size'], $kind);
        }

        $card->save();

        return $card;
    }

    public function delete(DashboardCard $card): void
    {
        $card->delete();
    }

    /**
     * New order from a list of the user's card ids; positions become 0..n-1. Ids not listed keep
     * their relative order after the listed ones.
     *
     * @param list<int|string> $ids
     */
    public function reorder(User $user, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        DB::transaction(function () use ($user, $ids): void {
            $cards = $user->dashboardCards()->lockForUpdate()->get();
            $known = $cards->pluck('id')->map(fn ($id) => (int) $id)->all();
            $unknown = array_diff($ids, $known);

            if ($unknown !== []) {
                throw ValidationException::withMessages(['ids' => 'Unknown card ids: ' . implode(', ', $unknown) . '!']);
            }

            $order = [...$ids, ...array_values(array_diff($known, $ids))];

            foreach ($order as $position => $id) {
                DashboardCard::query()->whereKey($id)->where('position', '!=', $position)->update(['position' => $position]);
            }
        });
    }

    /**
     * Data for an unsaved config (editor live preview).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function preview(User $user, array $input, array $args): array
    {
        $kind = DashboardCardConfig::kind($input['kind'] ?? null);
        $config = DashboardCardConfig::normalize($user, $kind, $this->configInput($input));

        return [
            'kind' => $kind,
            'size' => DashboardCardConfig::size($input['size'] ?? null, $kind),
            'config' => $config,
            'data' => $this->data->build($user, $kind, $config, PeriodRange::resolve($user, $args)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $user, DashboardCard $card, ?PeriodRange $range = null): array
    {
        $out = [
            'id' => (int) $card->id,
            'kind' => $card->kind,
            'size' => $card->size,
            'position' => (int) $card->position,
            'config' => $card->config,
            'label' => DashboardCardConfig::label($user, $card->kind, $card->config),
        ];

        if ($range) {
            $out['data'] = $this->data->build($user, $card->kind, $card->config, $range);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function configInput(array $input): array
    {
        if (is_array($input['config'] ?? null)) {
            return $input['config'];
        }

        return array_intersect_key($input, array_flip([
            'title', 'type', 'type_id', 'tags', 'tag_ids', 'filters', 'flow', 'metric', 'agg', 'split', 'split_by', 'chart',
        ]));
    }
}
