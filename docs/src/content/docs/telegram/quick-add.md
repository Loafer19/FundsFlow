---
title: Adding transactions
description: Log expenses and income in Telegram by writing, speaking, sending a receipt, or with the ➕ Add keypad.
---

There is no special syntax: write the transaction the way you'd say it, and the
[AI assistant](/docs/telegram/ai-assistant/) saves it.

| Message | Result |
| --- | --- |
| `350 groceries` | Expense of 350, note “groceries”, today |
| `кава 80` | Expense of 80, any language works |
| `+15000 salary` | Income of 15000 |
| `40 taxi yesterday` | Expense dated yesterday |
| `20.08 350 groceries` | Expense on 20 August of the current year |

- A number without a sign, or with a minus, is an **expense**. A plus, or words like *salary*, *income* or *refund*,
  make it **income**.
- A date at the start (`DD.MM` or `DD.MM.YYYY`) or words like *yesterday* set the day; otherwise it's **today in your
  timezone** (set in [Settings → Formatting](/docs/web/settings/)). Dates can't be in the future.
- Matching tags are added when the words fit one of your existing tags; nothing new is created.
- If the amount or direction is unclear, the assistant asks first.

Each message like this uses one assist from the [daily AI limit](/docs/telegram/ai-assistant/#daily-ai-limit).
[Voice notes](/docs/telegram/voice/) work the same way.

## ➕ Add keypad (no AI)

Tap **➕ Add** in the menu or send `/add` for the keypad widget: type the amount, pick **Expense** or **Income** and
one of your frequent tags, then **Add**. It never uses the AI limit, so it keeps working when the limit is reached.

## After saving

The bot answers **✅ Saved** with a transaction card and three buttons:

- **🏷 Tags** — pick tags for the transaction (paged list; tap to toggle, then finish).
- **✏️ Amount** — reply with the new amount, e.g. `+120` or `-50.5`. A number without a sign keeps the direction
  (an expense stays an expense).
- **🗑** — delete. The bot asks for confirmation: **✅ Delete** or **↩️ Cancel**.

You can open the same card later by tapping a transaction in the `/recent` widget.
