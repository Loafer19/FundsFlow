<?php

namespace App\Http\Controllers;

use App\Actions\Telegram\SendFileToUserTelegramAction;
use App\Actions\Transactions\DeleteTransactionAttachmentAction;
use App\Actions\Transactions\StoreTransactionAttachmentAction;
use App\Http\Requests\TransactionAttachmentBase64Request;
use App\Http\Requests\TransactionAttachmentStoreRequest;
use App\Http\Resources\TransactionAttachmentResource;
use App\Models\Transaction;
use App\Models\TransactionAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;



class TransactionAttachmentController extends Controller
{
    public function __construct(
        private readonly StoreTransactionAttachmentAction $storeAttachment,
        private readonly DeleteTransactionAttachmentAction $deleteAttachment,
        private readonly SendFileToUserTelegramAction $sendFileToTelegram,
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
        Request $request,
        Transaction $transaction,
        TransactionAttachment $attachment,
    ): StreamedResponse {
        Gate::forUser($request->user())->authorize('view', $transaction);
        $this->assertBelongs($transaction, $attachment, $request->user()->id);

        $disk = Storage::disk($attachment->disk);

        abort_if($disk->missing($attachment->path), 404, 'Attachment file is missing from storage!');

        return $disk->response(

            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime],
            'inline',
        );

    }

    public function sendToTelegram(
        Request $request,
        Transaction $transaction,
        TransactionAttachment $attachment,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('view', $transaction);
        $this->assertBelongs($transaction, $attachment, $request->user()->id);

        $disk = Storage::disk($attachment->disk);

        abort_if($disk->missing($attachment->path), 404, 'Attachment file is missing from storage!');

        $contents = $disk->get($attachment->path);

        if (!is_string($contents) || $contents === '') {
            abort(404, 'Attachment file is missing from storage!');
        }

        $this->sendFileToTelegram->execute(
            $request->user(),
            $contents,
            $attachment->original_name ?: 'attachment',
            (string) ($attachment->mime ?: 'application/octet-stream'),
            filled($request->input('caption')) ? (string) $request->input('caption') : null,
        );

        return response()->json([
            'message' => 'Sent to Telegram successfully!',
        ]);
    }

    public function destroy(
        Request $request,
        Transaction $transaction,
        TransactionAttachment $attachment,
    ): JsonResponse {
        $this->deleteAttachment->execute($request->user(), $transaction, $attachment);

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
