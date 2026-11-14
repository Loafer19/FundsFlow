<?php

namespace App\Http\Controllers;

use App\Actions\Agent\Documents\DocumentFile;
use App\Actions\Agent\Documents\DocumentImport;
use App\Actions\Imports\ApplyImportRulesAction;
use App\Actions\Imports\CommitImportBatchAction;
use App\Actions\Imports\ImportTypeAssigner;
use App\Actions\Imports\UndoImportBatchAction;
use App\Actions\Imports\UpdateImportRowAction;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\CommitImportBatchJob;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $batches = $request->user()->importBatches()
            ->latest()
            ->limit(50)
            ->get([
                'uuid',
                'filename',
                'format',
                'source',
                'status',
                'total_rows',
                'new_count',
                'committed_at',
                'undone_at',
                'created_at',
            ]);

        return response()->json([
            'data' => $batches->map(fn (ImportBatch $batch) => [
                'id' => $batch->uuid,
                'filename' => $batch->filename,
                'format' => $batch->format,
                'source' => $batch->source,
                'status' => $batch->status,
                'total_rows' => (int) $batch->total_rows,
                'imported_count' => (int) $batch->new_count,
                'can_undo' => $batch->status === ImportBatch::STATUS_COMMITTED,
                'created_at' => $batch->created_at?->toIso8601String(),
                'committed_at' => $batch->committed_at?->toIso8601String(),
                'undone_at' => $batch->undone_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function show(Request $request, string $import): ImportBatchResource
    {
        return new ImportBatchResource($this->batch($request, $import));
    }

    public function store(Request $request, DocumentImport $documents, ImportTypeAssigner $types): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|max:20480',
            'caption' => 'nullable|string|max:2000',
        ]);
        $uploaded = $data['file'];
        $name = (string) $uploaded->getClientOriginalName();
        $bytes = (string) file_get_contents($uploaded->getRealPath());
        if ($bytes === '') {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }
        $ext = strtolower((string) $uploaded->getClientOriginalExtension());
        $kind = DocumentFile::EXTENSIONS[$ext] ?? DocumentFile::kindOfMime((string) $uploaded->getMimeType()) ?? DocumentFile::sniffTextKind($bytes);
        if ($kind === null) {
            throw ValidationException::withMessages(['file' => DocumentFile::UNSUPPORTED]);
        }

        $result = $documents->fromFiles(
            $request->user(),
            [['name' => $name, 'kind' => $kind, 'bytes' => $bytes]],
            $data['caption'] ?? null,
            'Until then, name the columns date, amount and note and send the file again: that needs no AI.',
        );

        if (($result['type'] ?? null) !== 'preview') {
            $status = ($result['type'] ?? '') === 'quota' ? 429 : 422;
            return response()->json(['message' => $result['message'] ?? 'Nothing to import.', 'type' => $result['type'] ?? 'error'], $status);
        }

        $batch = $request->user()->importBatches()->where('uuid', $result['batch_id'])->firstOrFail();
        $batch->update(['source' => 'web']);
        // Type suggestions are for the web preview, where each one can be seen and changed.
        $types->assign($request->user(), $batch);

        return (new ImportBatchResource($batch->fresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $import, ApplyImportRulesAction $apply): ImportBatchResource
    {
        $batch = $this->batch($request, $import);
        $data = $request->validate([
            'include_foreign' => 'sometimes|boolean',
            'skip_transfers' => 'sometimes|boolean',
            'skip_before' => 'sometimes|nullable|date_format:Y-m-d',
            'merchant_map' => 'sometimes|array',
            'merchant_map.*' => 'string|max:80',
            'skip_names' => 'sometimes|array',
            'skip_names.*' => 'string|max:80',
            'instruction' => 'sometimes|nullable|string|max:2000',
            'category_map' => 'sometimes|array',
            'category' => 'sometimes|string|max:80',
            'category_action' => 'sometimes|in:map,create,ignore',
            'action' => 'sometimes|in:map,create,ignore',
            'tag_id' => 'sometimes|nullable|integer',
            'title' => 'sometimes|nullable|string|max:80',
            'tag_title' => 'sometimes|nullable|string|max:80',
            'include_ids' => 'sometimes|array',
            'include_ids.*' => 'integer',
            'skip_ids' => 'sometimes|array',
            'skip_ids.*' => 'integer',
            'type_id' => 'sometimes|nullable|integer',
            'type_match' => 'sometimes|nullable|string|max:80',
        ]);
        $batch = $apply->execute($request->user(), $batch, $data);

        return new ImportBatchResource($batch);
    }

    public function updateRow(Request $request, string $import, ImportRow $row, UpdateImportRowAction $update): ImportBatchResource
    {
        $batch = $this->batch($request, $import);
        if ((int) $row->import_batch_id !== (int) $batch->id) {
            abort(404);
        }
        $data = $request->validate([
            'status' => 'sometimes|in:new,skipped,transfer,needs_tag',
            'tag_ids' => 'sometimes|array',
            'tag_ids.*' => 'integer',
            'transaction_type_id' => 'sometimes|nullable|integer',
        ]);
        $update->execute($request->user(), $batch, $row, $data);

        return new ImportBatchResource($batch->fresh());
    }

    public function commit(Request $request, string $import, CommitImportBatchAction $commit): ImportBatchResource|JsonResponse
    {
        $batch = $this->batch($request, $import);
        if ($batch->total_rows >= 200) {
            $batch->update(['status' => ImportBatch::STATUS_COMMITTING, 'progress' => 1]);
            CommitImportBatchJob::dispatch($batch->id)->afterResponse();
            return (new ImportBatchResource($batch->fresh()))->response();
        }
        $commit->execute($request->user(), $batch);

        return new ImportBatchResource($batch->fresh());
    }

    public function undo(Request $request, string $import, UndoImportBatchAction $undo): ImportBatchResource
    {
        $batch = $this->batch($request, $import);
        $undo->execute($request->user(), $batch);

        return new ImportBatchResource($batch->fresh());
    }

    private function batch(Request $request, string $uuid): ImportBatch
    {
        return $request->user()->importBatches()->where('uuid', $uuid)->firstOrFail();
    }
}
