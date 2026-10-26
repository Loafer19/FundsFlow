<?php

return [
    // Web chat drawer conversation (history, pending confirm/ask) lifetime, minutes. Sliding: every turn extends it.
    'web_session_ttl_minutes' => (int) env('AGENT_WEB_SESSION_TTL_MINUTES', 60),

    // Max chat items kept per web conversation (shown by GET /api/agent/history).
    'web_transcript_max' => (int) env('AGENT_WEB_TRANSCRIPT_MAX', 60),

    // Prior text turns sent to the model as context on each web message.
    'web_history_turns' => (int) env('AGENT_WEB_HISTORY_TURNS', 12),

    // Conversation memory shared by the web and Telegram assistants (AgentHistory), minutes. Sliding.
    'shared_history_ttl_minutes' => (int) env('AGENT_SHARED_HISTORY_TTL_MINUTES', env('AGENT_WEB_SESSION_TTL_MINUTES', 60)),

    // Max items kept in the shared memory (the model gets the last `web_history_turns` of them).
    'shared_history_max' => (int) env('AGENT_SHARED_HISTORY_MAX', 40),

    // Max characters of a web chat message.
    'web_message_max_chars' => (int) env('AGENT_WEB_MESSAGE_MAX_CHARS', 2000),

    // Documents sent to the assistant (web drawer and Telegram): PDF, Word, CSV, Excel/ODS.
    'documents' => [
        // Upload caps, bytes. PDF matches the receipt attachment cap (it can be attached to a transaction).
        'max_pdf_bytes' => (int) env('AGENT_DOC_MAX_PDF_BYTES', 8 * 1024 * 1024),
        'max_file_bytes' => (int) env('AGENT_DOC_MAX_FILE_BYTES', 5 * 1024 * 1024),
        // Rows one import may create (CSV / Excel / statements).
        'max_rows' => (int) env('AGENT_DOC_MAX_ROWS', 1000),
        // Documents in one web message / Telegram media group, and their total size, bytes.
        'max_files' => (int) env('AGENT_DOC_MAX_FILES', 5),
        'max_total_bytes' => (int) env('AGENT_DOC_MAX_TOTAL_BYTES', 20 * 1024 * 1024),
        // Bank-app screenshots read as one transaction list (one vision call).
        'max_screenshots' => (int) env('AGENT_DOC_MAX_SCREENSHOTS', 10),
        // PDF pages read for text; a scanned PDF (no text layer) is read from its first page only.
        'max_pdf_pages' => (int) env('AGENT_DOC_MAX_PDF_PAGES', 20),
        // Document text sent to the model, characters (longer text is cut).
        'max_text_chars' => (int) env('AGENT_DOC_MAX_TEXT_CHARS', 40000),
        // Total uncompressed size of a DOCX / XLSX / ODS archive (zip bomb guard), bytes.
        'max_unzipped_bytes' => (int) env('AGENT_DOC_MAX_UNZIPPED_BYTES', 60 * 1024 * 1024),
        // xAI call that reads a document's text, seconds (statements can be long).
        'ai_timeout' => (int) env('AGENT_DOC_AI_TIMEOUT', 120),
        // pdftotext / pdftoppm run time, seconds.
        'tool_timeout' => (int) env('AGENT_DOC_TOOL_TIMEOUT', 30),
    ],
];
