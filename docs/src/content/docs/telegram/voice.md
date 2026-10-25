---
title: Voice messages
description: Send voice notes to the FundsFlow bot instead of typing.
---

Send a **voice note** (or an audio file) to the bot. It is transcribed with speech-to-text, and the transcript is
handled exactly like a typed message:

- a quick add such as “minus three hundred fifty groceries” → transcribed and saved if it matches the
  [quick add](/docs/telegram/quick-add/) format, otherwise passed to the [AI assistant](/docs/telegram/ai-assistant/);
- commands and questions (“show my budgets”, “month summary”).

What you'll see:

1. *Listening…*
2. *Heard: …* with the transcript.
3. The result, as if you had typed the transcript.

Details:

- The language is detected automatically, so you can speak in your own language.
- Voice messages up to **20 MB** are accepted.
- Each successfully transcribed voice message uses **one** [AI assist](/docs/telegram/ai-assistant/#daily-ai-limit).
  If the transcript then goes to the AI assistant, that counts as another assist.
- A caption on a voice message is ignored — the transcript is the command.
