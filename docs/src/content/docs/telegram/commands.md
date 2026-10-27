---
title: Commands and menu
description: All FundsFlow bot commands and reply-menu buttons.
---

## Reply menu

Below the message field the bot shows six buttons:

| Button | Same as |
| --- | --- |
| 📊 Month | `/month` |
| 🕘 Recent | `/recent` |
| 🏷 Tags | `/tags` |
| 💰 Budgets | `/budgets` |
| 🔁 Recurring | `/recurring` |
| 🌐 Web UI | `/app` |

## Commands

| Command | What it does |
| --- | --- |
| `/start` | Create an account for this chat, or link it with a code from the website |
| `/month` | This month's income, expenses, net, and top tags |
| `/analytics` | Same as `/month` |
| `/recent` | Your 5 most recent transactions, with **Open** buttons for each |
| `/tags` | List your tags |
| `/newtag` | Create a tag: `/newtag 🍕 Fast Food > Food` |
| `/budgets` | Active budgets for the current period (spent vs limit) and savings goals (saved vs target, deadline, pace) |
| `/recurring` | Your recurring rules |
| `/app` | How to open the Web UI (Mini App) from the menu button |
| `/website` | One-time code to [log in on the website](/docs/getting-started/sign-in/#telegram-code) |
| `/mute` | Mute budget alerts, the weekly digest, and recurring notifications |
| `/unmute` | Turn those notifications back on |
| `/unlink` | Unlink this chat; the account stays on the website |
| `/reset` | Start a new AI conversation (alias `/new`); see [AI assistant](/docs/telegram/ai-assistant/) |
| `/help` | List commands and the quick-add format |

### `/newtag`

```text
/newtag <emoji> <title> [> <parent title>]
```

- `/newtag ☕ Coffee` creates a top-level tag.
- `/newtag 🍕 Fast Food > Food` creates *Fast Food* under the existing tag *Food* (parent matched by title,
  case-insensitive).

## Deleting transactions

Open a transaction card — right after saving it, or with **Open N** under `/recent` — and tap **🗑**. The bot asks
**✅ Delete** or **↩️ Cancel**; nothing is deleted until you confirm. You can also ask the
[AI assistant](/docs/telegram/ai-assistant/) to delete a transaction; it also asks for confirmation.
