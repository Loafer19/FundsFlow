<?php

return [
    // Web chat drawer conversation (history, pending confirm/ask) lifetime, minutes. Sliding: every turn extends it.
    'web_session_ttl_minutes' => (int) env('AGENT_WEB_SESSION_TTL_MINUTES', 60),

    // Max chat items kept per web conversation (shown by GET /api/agent/history).
    'web_transcript_max' => (int) env('AGENT_WEB_TRANSCRIPT_MAX', 60),

    // Prior text turns sent to the model as context on each web message.
    'web_history_turns' => (int) env('AGENT_WEB_HISTORY_TURNS', 12),

    // Max characters of a web chat message.
    'web_message_max_chars' => (int) env('AGENT_WEB_MESSAGE_MAX_CHARS', 2000),
];
