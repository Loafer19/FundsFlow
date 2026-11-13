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
  timezone** (set in [Settings → Appearance](/docs/web/settings/)). Dates can't be in the future.
- Matching tags are added when the words fit one of your existing tags; nothing new is created.
- If the amount or direction is unclear, the assistant asks first.

Each message like this uses one assist from the [daily AI limit](/docs/telegram/ai-assistant/#daily-ai-limit).
[Voice notes](/docs/telegram/voice/) work the same way.

## ➕ Add keypad (no AI)

Tap **➕ Add** in the menu or send `/add` for the keypad widget: type the amount, pick **Expense** or **Income** and
one of your frequent tags, then **Add**. It never uses the AI limit, so it keeps working when the limit is reached.

## After saving

The bot answers **✅ Saved** with a transaction card:

```text
✅ Saved

-80.00
📅 11.11.2026
📝 кава
🏷 ☕ Cafes
```

Under it are three buttons:

- **↩️ Undo** — removes the transaction you just added. It works for **5 minutes**; after that the bot says
  *Too late to undo. Use 🗑 Delete* and the button goes away.
- **✏️ Edit** — opens the editor below the card (see next section).
- **🗑 Delete** — the card asks *Delete this transaction?* with **🗑 Delete** and **↩️ Cancel**; nothing is
  deleted until you confirm.

You can open the same card later by tapping a transaction in the `/recent` widget (without **Undo**).

## Edit a transaction

**✏️ Edit** opens an editor widget in the chat:

- **Amount** — tap it to show a keypad and type the new amount.
- **Expense / Income** — switch the direction; the tag chips follow it.
- **Date** — step back with **‹** and forward with **›** (*Today* and *Yesterday* are labelled). Dates can't be in
  the future.
- **Tags** — tap chips to select or clear them (✓ marks selected ones). **All tags…** opens the full list on the
  card for tags that aren't shown.
- **📝 Note** — the bot asks for the new note; reply with the text, or `-` to remove it.
- **Save** applies the changes and updates the card above (**✅ Updated**). **Delete** removes the transaction.

Only one editor per transaction is open at a time: tapping **✏️ Edit** again replaces the old one.

### In a group

Widgets work only in a private chat with the bot. In a group the card keeps text buttons instead:

- **🏷 Tags** — pick tags for the transaction (paged list; tap to toggle, then finish).
- **✏️ Amount** — reply with the new amount, e.g. `+120` or `-50.5`. A number without a sign keeps the direction
  (an expense stays an expense).
- **🗑 Delete** — delete after confirmation.
- **📎 Attach** — the next photo or file you send goes to this transaction (see
  [Attach to an existing transaction](/docs/telegram/receipts/#attach-to-an-existing-transaction)).
- **📎 Files (N)** — next to Attach when the transaction has attachments; sends them to the group.

**↩️ Undo** is shown on its own row above them for the first 5 minutes.
