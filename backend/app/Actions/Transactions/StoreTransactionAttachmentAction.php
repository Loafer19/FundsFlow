<?php

namespace App\Actions\Transactions;

use App\Models\Transaction;
use App\Models\TransactionAttachment;
use App\Models\User;
use App\Support\ImageConverter;
use App\Support\TransactionAttachmentRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreTransactionAttachmentAction
{
    /**
     * @param  array{name: string, mime?: string, contents: string}|UploadedFile  $file
     */
    public function execute(User $user, Transaction $transaction, UploadedFile|array $file): TransactionAttachment
    {
        Gate::forUser($user)->authorize('update', $transaction);

        if ($transaction->attachments()->count() >= TransactionAttachmentRules::MAX_PER_TRANSACTION) {
            throw ValidationException::withMessages([
                'file' => 'A transaction can have at most '
                    . TransactionAttachmentRules::MAX_PER_TRANSACTION
                    . ' attachments!',
            ]);
        }

        if ($file instanceof UploadedFile) {
            $claimedMime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
            $name = $file->getClientOriginalName() ?: TransactionAttachmentRules::fallbackFileName();
            $contents = $file->getContent();
            $size = $file->getSize() ?: strlen($contents);
        } else {
            $claimedMime = $file['mime'] ?? null;
            $name = $file['name'];
            $contents = $file['contents'];
            $size = strlen($contents);
        }


        if (!is_string($contents) || $contents === '') {
            throw ValidationException::withMessages([
                'file' => 'The file is empty or could not be read!',
            ]);
        }

        if ($size > TransactionAttachmentRules::MAX_BYTES) {
            throw ValidationException::withMessages([
                'file' => 'Each attachment must be 8 MB or smaller!',
            ]);
        }

        $mime = TransactionAttachmentRules::detectMime($contents, $claimedMime);

        if ($mime !== null && TransactionAttachmentRules::needsConversion($mime)) {
            // HEIC/HEIF (iPhone photos) are stored as JPEG so every browser and Telegram can show them.
            $jpeg = ImageConverter::toJpeg($contents, $mime);

            if ($jpeg === null || strlen($jpeg) > TransactionAttachmentRules::MAX_BYTES) {
                throw ValidationException::withMessages([
                    'file' => 'Could not convert this HEIC photo. Send it as JPEG or PNG!',
                ]);
            }

            $contents = $jpeg;
            $size = strlen($jpeg);
            $mime = 'image/jpeg';
            $name = TransactionAttachmentRules::jpegName($name);
        }

        $extension = $mime ? TransactionAttachmentRules::extensionForMime($mime) : null;

        if ($extension === null) {
            throw ValidationException::withMessages([
                'file' => 'Only ' . TransactionAttachmentRules::LABEL . ' files are allowed!',
            ]);
        }

        $disk = config('filesystems.default', 'local');
        $path = sprintf(
            'attachments/%d/%d/%s.%s',
            $user->id,
            $transaction->id,
            Str::uuid()->toString(),
            $extension,
        );

        Storage::disk($disk)->put($path, $contents);

        return $transaction->attachments()->create([
            'user_id' => $user->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => TransactionAttachmentRules::sanitizeOriginalName($name),
            'mime' => $mime,
            'size' => $size,
        ]);
    }
}
