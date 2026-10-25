---
title: Quick add
description: Exact message syntax for adding transactions to FundsFlow from Telegram.
---

Send the bot a single message with an amount and an optional note. It is saved right away — no AI involved, and it
doesn't count toward the [daily AI limit](/docs/telegram/ai-assistant/#daily-ai-limit).

## Syntax

```text
[DD.MM[.YYYY]] AMOUNT [NOTE]
```

| Message | Result |
| --- | --- |
| `-350 groceries` | Expense of 350, note “groceries”, today |
| `+15000 salary` | Income of 15000, note “salary”, today |
| `350 coffee` | No sign means **expense**: −350, note “coffee” |
| `-12.50 lunch` or `-12,5 lunch` | Up to 2 decimals; `.` or `,` as the decimal separator |
| `-350` | Expense with no note |
| `20.08 -350 groceries` | Expense on 20 August of the current year |
| `20.08.2025 -350 groceries` | Expense on 20 August 2025 |

Rules:

- **Minus is an expense, plus is income.** A number without a sign is treated as an expense.
- The amount must be digits only — **don't use thousand separators or spaces** inside the number: write `-1500 rent`,
  not `-1 500 rent` or `-1,500 rent`.
- The amount can't be zero.
- The note is everything after the amount, up to 255 characters.
- An optional date prefix uses `DD.MM` or `DD.MM.YYYY`. Without it the transaction is dated **today in your timezone**
  (set in [Settings → Formatting](/docs/web/settings/)).
- Tags are not parsed from the text. Add them with the **🏷 Tags** button after saving.

If the message doesn't match this format, the bot tries the [AI assistant](/docs/telegram/ai-assistant/) for anything
that looks like a sentence, or replies with a format hint.

## After saving

The bot answers **✅ Saved** with a transaction card and three buttons:

- **🏷 Tags** — pick tags for the transaction (paged list; tap to toggle, then finish).
- **✏️ Amount** — reply with the new amount, e.g. `+120` or `-50.5`. A number without a sign keeps the direction
  (an expense stays an expense).
- **🗑** — delete. The bot asks for confirmation: **✅ Delete** or **↩️ Cancel**.

You can open the same card later from `/recent` (**Open 1…5** buttons).
