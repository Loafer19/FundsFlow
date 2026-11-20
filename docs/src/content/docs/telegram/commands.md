---
title: Commands and menu
description: All FundsFlow bot commands and reply-menu buttons.
---

## Reply menu

Below the message field the bot shows six buttons:

| Button | Same as |
| --- | --- |
| ➕ Add | `/add` |
| 🕘 Recent | `/recent` |
| 📊 Month | `/month` |
| 💰 Budgets | `/budgets` |
| 🏷 Tags | `/tags` |
| 🔁 Recurring | `/recurring` |

The Web UI opens from the menu button next to the message field.

## Commands

The bot's command menu lists the commands in this order. In a private chat, when Telegram allows widgets, most of
them open a live widget that updates by itself after every transaction. In a group, and when Telegram refuses
widgets (since 13 November 2026, even in a private chat), they reply with text. The bot tries widgets again after
about an hour.

| Command | What it does |
| --- | --- |
| `/start` | Create an account for this chat, or link it with a code from the website |
| `/add` | Keypad: amount, expense or income, one of your frequent tags (no AI). A widget when available; otherwise the bot asks you to write the transaction |
| `/recent` | Recent transactions with all their tags (📎 marks ones with files); open one for **📎 Files** (its files, each with **Send** and 🗑, and **+ Attach**), **✏️ Edit** or **🗑 Delete** |
| `/month` | This month's income, expenses, net, a daily chart, and top tags (a parent tag includes its child tags) |
| `/budgets` | Spending limits for the current period (spent / limit, left or over, period dates, days left) and savings goals (saved vs target, deadline, pace) |
| `/tags` | Your top-level tags with this month's expenses (−) and income (+); tap a tag to split it by subtags. Tags with no transactions this month are folded |
| `/recurring` | Recurring payments with their next dates; tap one to pause or resume it. The text list also shows the type and service when a rule has them |
| `/notifications` | Alerts, digest, reminders and pinned month: three switches for **Alerts and digest** (limit alerts, goal messages, the weekly digest, recurring and bank sync notifications), **Recurring reminders** (a day before each payment) and **Pinned month summary** (a live `/month` pinned at the top of the chat). See [Notifications](/docs/telegram/notifications/) |
| `/website` | One-time code to [log in on the website](/docs/getting-started/sign-in/#telegram-code) |
| `/reset` | Start a new AI conversation (alias `/new`); see [AI assistant](/docs/telegram/ai-assistant/) |
| `/support` | Report a problem or send feedback: `/support The PDF report doesn't open`; see [FAQ](/docs/help/faq/#how-do-i-report-a-problem-or-send-feedback) |
| `/help` | All commands and tips |
| `/unlink` | Unlink this chat; the account stays on the website |

These still work but are not in the menu: `/newtag` (below), `/mute` and `/unmute` (same as the
**Alerts and digest** switch), and `/app` (how to open the Web UI). Any other command gets "Unknown command, see /help". `/summary` and `/analytics`
were removed: use `/month`, or ask the [AI assistant](/docs/telegram/ai-assistant/) about any period.

### `/newtag`

```text
/newtag <emoji> <title> [> <parent title>]
```

- `/newtag ☕ Coffee` creates a top-level tag.
- `/newtag 🍕 Fast Food > Food` creates *Fast Food* under the existing tag *Food* (parent matched by title,
  case-insensitive).

## Editing and deleting transactions

Open a transaction from `/recent`: **✏️ Edit** opens the [editor](/docs/telegram/quick-add/#edit-a-transaction),
and **🗑 Delete** asks *Delete this transaction?* first. A transaction with a [type](/docs/web/types/) shows the type
next to its tags and a box with its fields (*Liters 40 l*, *Price per liter 58,90 ₴*…). The editor shows them too;
change fields in the web app or by asking the assistant. **📎 Files (N)** lists the transaction's files (name and
size); **Send** next to a file sends it to the chat, 🗑 deletes it after *Delete? Yes / No*, and **+ Attach** asks
for a photo or file to [attach](/docs/telegram/receipts/#attach-to-an-existing-transaction). A card you just saved
has the same **✏️ Edit** and **🗑 Delete** buttons; **🗑 Delete** turns the card into *Delete this transaction?* with
**🗑 Delete** and **↩️ Cancel**, and nothing is deleted until you confirm.

When widgets aren't available (in a group, or when Telegram refuses them in a private chat), `/recent` sends text
cards (date as DD.MM.YYYY). **✏️ Edit** turns the card itself into an editor with buttons for tags, amount, date and
note, plus **🗑 Delete**, **📎 Attach** and, when it has attachments, **📎 Files (N)**. A card you just added also has
**↩️ Undo** for 5 minutes. You can also ask the [AI assistant](/docs/telegram/ai-assistant/) to change or delete a
transaction; it shows what will change and asks for confirmation.
