<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\DeleteTransactionAttachmentAction;
use App\Actions\Transactions\StoreTransactionAttachmentAction;
use App\Http\Requests\TransactionAttachmentBase64Request;
use App\Http\Requests\TransactionAttachmentStoreRequest;
use App\Http\Resources\TransactionAttachmentResource;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionAttachmentController extends Controller
{
    public function __construct(
        private readonly StoreTransactionAttachmentAction $storeAttachment,
        private readonly DeleteTransactionAttachmentAction $deleteAttachment,
    ) {}

    public function store(
        Transaction $transaction,
        TransactionAttachmentStoreRequest $request,
    ): TransactionAttachmentResource {
        $attachment = $this->storeAttachment->execute(
            $request->user(),
            $transaction,
            $request->file('file'),
        );

        return new TransactionAttachmentResource($attachment);
    }

    public function storeBase64(
        Transaction $transaction,
        TransactionAttachmentBase64Request $request,
    ): TransactionAttachmentResource {
        $data = $request->validated();
        $contents = base64_decode($data['content'], true);

        if ($contents === false) {
            abort(422, 'Invalid base64 content!');
        }

        $attachment = $this->storeAttachment->execute($request->user(), $transaction, [
            'name' => $data['name'],
            'mime' => $data['mime'],
            'contents' => $contents,
        ]);

        return new TransactionAttachmentResource($attachment);
    }

    public function show(
        Transaction $transaction,
        TransactionAttachment $attachment,
    ): StreamedResponse {
        Gate::authorize('update', $transaction);
        $this->assertBelongs($transaction, $attachment, auth()->id());

        $disk = Storage::disk($attachment->disk);

        if (!$disk->exists($attachment->path)) {
            abort(404, 'Attachment file is missing from storage!');
        }

        return $disk->response(
            $attachment->path,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->mime,
                'Content-Disposition' => 'inline; filename="' . addslashes($attachment->original_name) . '"',
            ],
        );
    }

    public function destroy(
        Transaction $transaction,
        TransactionAttachment $attachment,
    ): JsonResponse {
        $this->deleteAttachment->execute(auth()->user(), $transaction, $attachment);

        return response()->json([
            'message' => 'Attachment deleted successfully!',
        ]);
    }

    private function assertBelongs(
        Transaction $transaction,
        TransactionAttachment $attachment,
        int|string|null $userId = null,
    ): void {
        if (
            $attachment->transaction_id !== $transaction->id
            || ($userId !== null && (int) $attachment->user_id !== (int) $userId)
        ) {
            abort(404);
        }
    }
}
