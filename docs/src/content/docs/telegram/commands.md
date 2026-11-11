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

The bot's command menu lists the commands in this order. In a private chat most of them open a live
widget that updates by itself after every transaction; in a group they reply with text.

| Command | What it does |
| --- | --- |
| `/start` | Create an account for this chat, or link it with a code from the website |
| `/add` | Keypad widget: amount, expense or income, one of your frequent tags (no AI) |
| `/recent` | Recent transactions; tap one to open it, edit its tags, or delete it |
| `/month` | This month's income, expenses, net, a daily chart, and top tags (a parent tag includes its child tags) |
| `/budgets` | Spending limits for the current period (spent / limit, left or over, period dates, days left) and savings goals (saved vs target, deadline, pace) |
| `/tags` | Your top-level tags with this month's expenses (−) and income (+); tap a tag to split it by subtags. Tags with no transactions this month are folded |
| `/recurring` | Recurring payments with their next dates; tap one to pause or resume it |
| `/notifications` | One switch for limit alerts, goal messages, the weekly digest, and recurring and bank sync notifications in this chat |
| `/website` | One-time code to [log in on the website](/docs/getting-started/sign-in/#telegram-code) |
| `/reset` | Start a new AI conversation (alias `/new`); see [AI assistant](/docs/telegram/ai-assistant/) |
| `/support` | Report a problem or send feedback: `/support The PDF report doesn't open`; see [FAQ](/docs/help/faq/#how-do-i-report-a-problem-or-send-feedback) |
| `/help` | All commands and tips |
| `/unlink` | Unlink this chat; the account stays on the website |

These still work but are not in the menu: `/newtag` (below), `/mute` and `/unmute` (same as the
`/notifications` switch), and `/app` (how to open the Web UI). Any other command gets "Unknown command, see /help".

### `/newtag`

```text
/newtag <emoji> <title> [> <parent title>]
```

- `/newtag ☕ Coffee` creates a top-level tag.
- `/newtag 🍕 Fast Food > Food` creates *Fast Food* under the existing tag *Food* (parent matched by title,
  case-insensitive).

## Deleting transactions

Open a transaction card — right after saving it, by tapping it in the `/recent` widget, or with **Open N** under `/recent` in a group — and tap **🗑**. The bot asks
**✅ Delete** or **↩️ Cancel**; nothing is deleted until you confirm. You can also ask the
[AI assistant](/docs/telegram/ai-assistant/) to delete a transaction; it also asks for confirmation.
