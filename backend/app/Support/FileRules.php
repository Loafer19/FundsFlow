<?php

namespace App\Support;

use App\Actions\Agent\Documents\DocumentFile;

/**
 * File formats and limits for the web app (bootstrap "file_rules"), built from the backend rules
 * (TransactionAttachmentRules, DocumentFile) so the pickers and checks there never drift.
 */
final class FileRules
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function forClient(): array
    {
        $attachMimes = TransactionAttachmentRules::allowedMimes();
        $attachExtensions = TransactionAttachmentRules::EXTENSIONS;
        $docExtensions = array_keys(DocumentFile::EXTENSIONS);

        return [
            'attachments' => [
                'mimes' => $attachMimes,
                'extensions' => $attachExtensions,
                'accept' => implode(',', [...$attachMimes, ...array_map(static fn (string $e): string => '.' . $e, $attachExtensions)]),
                'label' => TransactionAttachmentRules::LABEL,
                'max_bytes' => TransactionAttachmentRules::MAX_BYTES,
                'max_files' => TransactionAttachmentRules::MAX_PER_TRANSACTION,
            ],
            'receipt_images' => [
                'mimes' => array_keys(TransactionAttachmentRules::IMAGE_MIMES),
                'max_bytes' => TransactionAttachmentRules::MAX_BYTES,
                'max_files' => TransactionAttachmentRules::MAX_PER_TRANSACTION,
            ],
            'documents' => [
                'extensions' => $docExtensions,
                'accept' => implode(',', array_map(static fn (string $e): string => '.' . $e, $docExtensions)),
                'max_pdf_bytes' => DocumentFile::maxBytes(DocumentFile::PDF),
                'max_file_bytes' => DocumentFile::maxBytes(DocumentFile::CSV),
                'max_files' => DocumentFile::maxFiles(),
                'max_total_bytes' => DocumentFile::maxTotalBytes(),
                'max_screenshots' => max(1, (int) config('agent.documents.max_screenshots', 10)),
            ],
        ];
    }
}
