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
];
