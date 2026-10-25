<?php

namespace App\Actions\Agent\Web;

use App\Models\User;
use App\Support\TransactionAttachmentRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Receipt photos sent to the web assistant, kept on the private `local` disk
 * (never public) only until their Confirm card is resolved. Leftovers (expired
 * conversations, outdated cards) are removed by `agent:prune-uploads`.
 *
 * A ref is {id, name, mime, file_size}; the path is derived from the user id + id,
 * so a ref can never point outside that user's folder.
 */
final class WebAgentUploads
{
    public const DISK = 'local';

    public const DIR = 'agent-uploads';

    /** Photos per message (also the attachment limit of one transaction). */
    public const MAX_FILES = TransactionAttachmentRules::MAX_PER_TRANSACTION;

    /** Same cap as transaction attachments, since the photos become attachments. */
    public const MAX_BYTES = TransactionAttachmentRules::MAX_BYTES;

    /** @var array<string, string> sniffed mime => extension */
    public const MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * @return array{id: string, name: string, mime: string, file_size: int}
     */
    public function store(User $user, UploadedFile $file): array
    {
        $contents = (string) $file->getContent();
        $size = strlen($contents);
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['images' => 'Each photo must be 8 MB or smaller.']);
        }

        // Sniff the bytes; the client-declared type is ignored.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';
        if (!isset(self::MIMES[$mime])) {
            throw ValidationException::withMessages(['images' => 'Only JPEG, PNG and WebP photos are supported.']);
        }

        $id = Str::uuid()->toString();
        Storage::disk(self::DISK)->put($this->path($user, $id, $mime), $contents);

        return [
            'id' => $id,
            'name' => TransactionAttachmentRules::sanitizeOriginalName((string) ($file->getClientOriginalName() ?: 'receipt.' . self::MIMES[$mime])),
            'mime' => $mime,
            'file_size' => $size,
        ];
    }

    /**
     * Bytes of a stored photo, or null when it is gone (expired / already used) or the ref is invalid.
     *
     * @param array<string, mixed> $ref
     */
    public function read(User $user, array $ref): ?string
    {
        $path = $this->refPath($user, $ref);
        if ($path === null || !Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * @param list<array<string, mixed>> $refs
     */
    public function delete(User $user, array $refs): void
    {
        $paths = array_values(array_filter(array_map(fn (array $ref): ?string => $this->refPath($user, $ref), $refs)));
        if ($paths !== []) {
            Storage::disk(self::DISK)->delete($paths);
        }
    }

    public function deleteAll(User $user): void
    {
        Storage::disk(self::DISK)->deleteDirectory(self::DIR . '/' . $user->id);
    }

    /**
     * Remove photos older than $minutes (all users). Returns how many files were deleted.
     */
    public function prune(int $minutes): int
    {
        $disk = Storage::disk(self::DISK);
        $cutoff = now()->subMinutes(max(1, $minutes))->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles(self::DIR) as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
                $deleted++;
            }
        }

        foreach ($disk->directories(self::DIR) as $dir) {
            if ($disk->allFiles($dir) === []) {
                $disk->deleteDirectory($dir);
            }
        }

        return $deleted;
    }

    /**
     * Photo refs held by a pending state (receipt ask, receipt Confirm card).
     *
     * @param mixed $pending
     * @return list<array<string, mixed>>
     */
    public static function refsIn(mixed $pending): array
    {
        if (!is_array($pending)) {
            return [];
        }

        $refs = [];
        $collect = static function (mixed $files) use (&$refs): void {
            foreach (is_array($files) ? $files : [] as $ref) {
                if (is_array($ref) && isset($ref['id'])) {
                    $refs[] = $ref;
                }
            }
        };

        $collect($pending['files'] ?? null);
        $payload = is_array($pending['payload'] ?? null) ? $pending['payload'] : [];
        $collect($payload['files'] ?? null);
        foreach (is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [] as $tx) {
            $collect(is_array($tx) ? ($tx['files'] ?? null) : null);
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $ref
     */
    private function refPath(User $user, array $ref): ?string
    {
        $id = is_string($ref['id'] ?? null) ? $ref['id'] : '';
        $mime = is_string($ref['mime'] ?? null) ? $ref['mime'] : '';
        if (!preg_match(self::ID_PATTERN, $id) || !isset(self::MIMES[$mime])) {
            return null;
        }

        return $this->path($user, $id, $mime);
    }

    private function path(User $user, string $id, string $mime): string
    {
        return self::DIR . '/' . $user->id . '/' . $id . '.' . self::MIMES[$mime];
    }
}
