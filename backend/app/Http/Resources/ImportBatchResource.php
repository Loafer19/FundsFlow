<?php

namespace App\Http\Resources;

use App\Models\ImportRow;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));
        $perPage = min(100, max(10, (int) $request->query('per_page', 50)));

        $query = $this->rows()->orderBy('line');
        if (is_string($status) && $status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('note', 'like', '%' . $search . '%')
                    ->orWhere('skip_reason', 'like', '%' . $search . '%');
            });
        }
        $page = $query->paginate($perPage);

        $tagIds = collect($page->items())->flatMap(fn (ImportRow $row) => $row->tag_ids ?? [])->unique()->values();
        $tags = $tagIds->isEmpty()
            ? collect()
            : Tag::query()->whereIn('id', $tagIds)->get()->keyBy('id');

        $byTag = [];
        foreach ($this->rows as $row) {
            if (!$row->isImportable()) {
                continue;
            }
            $ids = $row->tag_ids ?: [null];
            foreach ($ids as $id) {
                $key = $id === null ? 0 : (int) $id;
                $byTag[$key]['id'] = $id;
                $byTag[$key]['count'] = ($byTag[$key]['count'] ?? 0) + 1;
                $byTag[$key]['total'] = ($byTag[$key]['total'] ?? 0) + (float) $row->amount;
            }
        }
        $named = Tag::query()->whereIn('id', array_values(array_filter(array_keys($byTag))))->get()->keyBy('id');
        $byTagOut = [];
        foreach ($byTag as $item) {
            $tag = isset($item['id']) ? $named->get((int) $item['id']) : null;
            $byTagOut[] = [
                'tag_id' => $item['id'],
                'title' => $tag?->title ?? 'Untagged',
                'emoji' => $tag?->emoji,
                'count' => $item['count'],
                'total' => round($item['total'], 2),
            ];
        }
        usort($byTagOut, fn ($a, $b) => $b['count'] <=> $a['count']);

        $categoryCounts = [];
        foreach ($this->rows as $row) {
            if (is_string($row->category) && $row->category !== '') {
                $categoryCounts[$row->category] = ($categoryCounts[$row->category] ?? 0) + 1;
            }
        }
        $map = is_array($this->category_map) ? $this->category_map : [];
        $mapTagIds = [];
        foreach ($map as $decision) {
            if (is_array($decision) && ($decision['action'] ?? '') === 'map' && isset($decision['tag_id'])) {
                $mapTagIds[] = (int) $decision['tag_id'];
            }
        }
        $mapTags = $mapTagIds === []
            ? collect()
            : Tag::query()->whereIn('id', array_unique($mapTagIds))->get()->keyBy('id');
        $categories = [];
        $seen = [];
        foreach ($map as $name => $decision) {
            if (!is_string($name) || !is_array($decision)) {
                continue;
            }
            $seen[mb_strtolower($name)] = true;
            $action = $decision['action'] ?? 'ignore';
            $tag = ($action === 'map' && isset($decision['tag_id'])) ? $mapTags->get((int) $decision['tag_id']) : null;
            $categories[] = [
                'name' => $name,
                'count' => $categoryCounts[$name] ?? 0,
                'action' => $action,
                'tag_id' => $tag?->id,
                'title' => $action === 'create' ? ($decision['title'] ?? $name) : ($tag?->title),
                'tag' => $tag ? ['id' => $tag->id, 'title' => $tag->title, 'emoji' => $tag->emoji] : null,
            ];
        }
        foreach ($categoryCounts as $name => $count) {
            if (isset($seen[mb_strtolower($name)])) {
                continue;
            }
            $categories[] = [
                'name' => $name,
                'count' => $count,
                'action' => 'ignore',
                'tag_id' => null,
                'title' => null,
                'tag' => null,
            ];
        }
        usort($categories, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strcasecmp($a['name'], $b['name']));

        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'filename' => $this->filename,
            'format' => $this->format,
            'source' => $this->source,
            'url' => $this->previewUrl(),
            'isMutable' => $this->isMutable(),
            'categories' => $categories,
            'summary' => [
                'total_rows' => $this->total_rows,
                'new' => $this->new_count,
                'duplicate' => $this->duplicate_count,
                'transfer' => $this->transfer_count,
                'skipped' => $this->skipped_count,
                'needs_tag' => $this->needs_tag_count,
                'error' => $this->error_count,
                'income_total' => (float) $this->income_total,
                'expense_total' => (float) $this->expense_total,
                'period_from' => $this->period_from?->toDateString(),
                'period_to' => $this->period_to?->toDateString(),
            ],
            'by_tag' => $byTagOut,
            'options' => $this->options ?? [],
            'notes' => $this->notes ?? [],
            'ai_used' => (bool) $this->ai_used,
            'progress' => (int) $this->progress,
            'error_message' => $this->error_message,
            'committed_at' => $this->committed_at?->toIso8601String(),
            'undone_at' => $this->undone_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'rows' => collect($page->items())->map(fn (ImportRow $row) => [
                'id' => $row->id,
                'line' => $row->line,
                'status' => $row->status,
                'at' => $row->at?->toDateString(),
                'amount' => $row->amount !== null ? (float) $row->amount : null,
                'note' => $row->note,
                'category' => $row->category,
                'currency' => $row->currency,
                'manual' => (bool) $row->manual,
                'tag_ids' => $row->tag_ids ?? [],
                'tags' => collect($row->tag_ids ?? [])->map(function ($id) use ($tags) {
                    $tag = $tags->get((int) $id);
                    return $tag ? ['id' => $tag->id, 'title' => $tag->title, 'emoji' => $tag->emoji] : null;
                })->filter()->values()->all(),
                'new_tag_titles' => $row->new_tag_titles ?? [],
                'skip_reason' => $row->skip_reason,
                'error' => $row->error,
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }
}
