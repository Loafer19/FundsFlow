<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\DashboardCards;
use App\Models\DashboardCard;
use App\Support\PeriodRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Analytics "My cards". index/store/update return cards with their numbers for the given
 * from/to (the Analytics date range); preview computes an unsaved config for the editor.
 */
class DashboardCardController extends Controller
{
    public function __construct(private readonly DashboardCards $cards) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->cards->list($request->user(), $request->only(['from', 'to', 'period'])));
    }

    public function store(Request $request): JsonResponse
    {
        $card = $this->cards->create($request->user(), $request->all());

        return response()->json($this->presentWithRange($request, $card), 201);
    }

    public function update(Request $request, int $card): JsonResponse
    {
        $model = $this->cards->update($request->user(), $this->own($request, $card), $request->all());

        return response()->json($this->presentWithRange($request, $model));
    }

    public function destroy(Request $request, int $card): JsonResponse
    {
        $this->cards->delete($this->own($request, $card));

        return response()->json(['message' => 'Card deleted!']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->input('ids');
        $this->cards->reorder($request->user(), is_array($ids) ? $ids : []);

        return response()->json(['message' => 'Cards reordered!']);
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->cards->preview($request->user(), $request->all(), $request->only(['from', 'to', 'period'])));
    }

    private function own(Request $request, int $id): DashboardCard
    {
        return DashboardCard::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function presentWithRange(Request $request, DashboardCard $card): array
    {
        $range = PeriodRange::resolve($request->user(), $request->only(['from', 'to', 'period']));

        return $this->cards->present($request->user(), $card, $range);
    }
}
